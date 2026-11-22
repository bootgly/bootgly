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
 * RFC 9112 §9.6: a parked deferral whose request asked to close announces
 * it — its answer says `Connection: close`, as a synchronous answer does —
 * and the connection closes after it (`6.4` covers the pipelined tail).
 */
$Probe = new class {
   public string $wire = '';
   public bool $eof = false;
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         @fwrite($Socket, Client::request('/deferred/queue?tag=P&ms=50', $testIndex, ['Connection' => 'close']));

         // @@ Read until the server closes the connection
         $deadline = microtime(true) + 3.0;
         while (microtime(true) < $deadline) {
            $read = [$Socket];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 50_000) !== 1) {
               continue;
            }
            $chunk = @fread($Socket, 65536);
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
      $separator = strpos($Probe->wire, "\r\n\r\n");
      $head = $separator === false ? $Probe->wire : substr($Probe->wire, 0, $separator);
      $body = $separator === false ? '' : substr($Probe->wire, $separator + 4);
      $decoded = json_decode($body, true);
      $evidence = json_encode(['error' => $Probe->error, 'eof' => $Probe->eof, 'wire' => $Probe->wire]);

      yield new Assertion(
         description: 'The parked deferral is answered',
         fallback: "The parked deferral was not answered: {$evidence}"
      )
         ->expect(is_array($decoded) ? ($decoded['tag'] ?? null) : null)
         ->to->be('P')
         ->assert();

      yield new Assertion(
         description: 'Its answer says `Connection: close`',
         fallback: "The parked answer did not announce the close: {$evidence}"
      )
         ->expect(preg_match('/\r\nConnection:[ \t]*close\r?$/im', $head))
         ->to->be(1)
         ->assert();

      yield new Assertion(
         description: 'The connection closes after it',
         fallback: "The connection stayed open: {$evidence}"
      )
         ->expect($Probe->eof)
         ->to->be(true)
         ->assert();
   })
);
