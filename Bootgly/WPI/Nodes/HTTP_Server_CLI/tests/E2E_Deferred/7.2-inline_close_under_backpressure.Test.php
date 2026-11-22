<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * RFC 9112 §9.6 under backpressure: an inline deferral on a request that
 * asked to close answers with a body the socket cannot take in one write,
 * and the client reads late. The unsent part drains first, then the
 * connection closes — the request pipelined behind it is never answered,
 * though the transport keeps reading while a write is pending.
 *
 * The backlog depends on the host: the 8 MB answer must outgrow what one
 * non-blocking write takes (about 2.6 MB on a loopback with the Linux default
 * `tcp_wmem` ceiling of 4 MiB). A host whose ceiling takes the whole answer at
 * once would write it without a backlog and prove only the header and the
 * close, so the case fails loudly there instead of passing on the easy path.
 */
$Probe = new class {
   public int $ceiling = 0;
   public string $wire = '';
   public bool $eof = false;
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         // ! The host's TCP send-buffer ceiling, where the kernel says it
         $sysctl = @file_get_contents('/proc/sys/net/ipv4/tcp_wmem');
         if (is_string($sysctl) && preg_match('/(\d+)\s*$/', trim($sysctl), $matches) === 1) {
            $Probe->ceiling = (int) $matches[1];
         }

         $Socket = Client::open($hostPort);
         $closing = Client::request('/deferred/inline/large', $testIndex, ['Connection' => 'close']);
         $behind = Client::request('/deferred/tag?tag=B', $testIndex);
         @fwrite($Socket, "{$closing}{$behind}");

         // ! Read late: the answer outgrows the socket buffers meanwhile
         usleep(500_000);

         // @@ Read until the server closes the connection
         $deadline = microtime(true) + 10.0;
         while (microtime(true) < $deadline) {
            $read = [$Socket];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 50_000) !== 1) {
               continue;
            }
            $chunk = @fread($Socket, 1_048_576);
            if ($chunk === false || $chunk === '') {
               $Probe->eof = true;
               break;
            }
            $Probe->wire .= $chunk;
         }
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $wire = $Probe->wire;
      $separator = strpos($wire, "\r\n\r\n");
      $head = $separator === false ? $wire : substr($wire, 0, $separator);
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $evidence = json_encode([
         'ceiling' => $Probe->ceiling,
         'error' => $Probe->error,
         'eof' => $Probe->eof,
         'head' => $head,
         'bytes' => strlen($body),
         'tail' => substr($body, 7_999_900)
      ]);

      yield new Assertion(
         description: 'The host leaves a backlog: its send-buffer ceiling is below the answer',
         fallback: "The host's tcp_wmem ceiling takes the whole answer in one write — raise the body of `/deferred/inline/large`: {$evidence}"
      )
         ->expect($Probe->ceiling < 8_000_000)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The large answer arrives whole and says `Connection: close`',
         fallback: "The large answer is incomplete or did not announce the close: {$evidence}"
      )
         ->expect([
            preg_match('/\r\nConnection:[ \t]*close\r?$/im', $head),
            str_starts_with($body, str_repeat('x', 8_000_000))
         ])
         ->to->be([1, true])
         ->assert();

      yield new Assertion(
         description: 'Nothing follows it: the pipelined request is not answered',
         fallback: "A second answer followed the closing one: {$evidence}"
      )
         ->expect(strlen($body))
         ->to->be(8_000_000)
         ->assert();

      yield new Assertion(
         description: 'The connection closes once the answer drained',
         fallback: "The connection stayed open: {$evidence}"
      )
         ->expect($Probe->eof)
         ->to->be(true)
         ->assert();
   })
);
