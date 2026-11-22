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
 * RFC 9112 §9.6 on the error path: an inline deferral that throws on a
 * request that asked to close is answered by the Catcher's 500 — which says
 * `Connection: close` too — and the connection closes after it; the request
 * pipelined behind it is never answered.
 */
$Probe = new class {
   public string $wire = '';
   public bool $eof = false;
   public string $error = '';
};

// ! The status code of every complete Content-Length framed answer
$Codes = static function (string $wire): array {
   $answers = [];
   $rest = $wire;
   // @@
   while (($separator = strpos($rest, "\r\n\r\n")) !== false) {
      $head = substr($rest, 0, $separator);
      $matches = [];
      // ? Stop at a partial (or unframed) answer
      if (preg_match('/\r\nContent-Length:[ \t]*(\d+)/i', $head, $matches) !== 1) {
         break;
      }
      $length = (int) $matches[1];
      if (strlen($rest) - $separator - 4 < $length) {
         break;
      }
      $answers[] = (int) substr($head, 9, 3);
      $rest = substr($rest, $separator + 4 + $length);
   }

   // :
   return $answers;
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $closing = Client::request('/deferred/inline/throw', $testIndex, ['Connection' => 'close']);
         $behind = Client::request('/deferred/tag?tag=B', $testIndex);
         @fwrite($Socket, "{$closing}{$behind}");

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

   test: new Assertions(Case: function (string $response) use ($Probe, $Codes): Generator {
      $separator = strpos($Probe->wire, "\r\n\r\n");
      $head = $separator === false ? $Probe->wire : substr($Probe->wire, 0, $separator);
      $evidence = json_encode(['error' => $Probe->error, 'eof' => $Probe->eof, 'head' => $head, 'bytes' => strlen($Probe->wire)]);

      yield new Assertion(
         description: 'Only the 500 is answered; the pipelined request is not',
         fallback: "Wrong answers on a closing connection: {$evidence}"
      )
         ->expect($Codes($Probe->wire))
         ->to->be([500])
         ->assert();

      yield new Assertion(
         description: 'The 500 says `Connection: close`',
         fallback: "The 500 did not announce the close: {$evidence}"
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
