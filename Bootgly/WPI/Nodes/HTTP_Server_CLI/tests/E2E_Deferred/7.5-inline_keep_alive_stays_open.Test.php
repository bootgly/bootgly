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
 * The keep-alive control of `7.1`: an inline deferral on a request that did
 * not ask to close keeps its connection — no `Connection: close`, the
 * request pipelined behind it is answered in order, and the connection stays
 * open afterwards.
 */
$Probe = new class {
   public string $wire = '';
   public bool $eof = false;
   public string $error = '';
};

// ! Split the wire into its complete Content-Length framed answers
$Split = static function (string $wire): array {
   $answers = [];
   $rest = $wire;
   // @@
   while (($separator = strpos($rest, "\r\n\r\n")) !== false) {
      $head = substr($rest, 0, $separator);
      $matches = [];
      // ? Every fixture answer is Content-Length framed; stop at a partial one
      if (preg_match('/\r\nContent-Length:[ \t]*(\d+)/i', $head, $matches) !== 1) {
         break;
      }
      $length = (int) $matches[1];
      if (strlen($rest) - $separator - 4 < $length) {
         break;
      }
      $decoded = json_decode(substr($rest, $separator + 4, $length), true);
      $answers[] = [
         'head' => $head,
         'tag' => is_array($decoded) ? (string) ($decoded['tag'] ?? '?') : '?'
      ];
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
         $first = Client::request('/deferred/inline?tag=A', $testIndex);
         $behind = Client::request('/deferred/tag?tag=B', $testIndex);
         @fwrite($Socket, "{$first}{$behind}");

         // @@ Read for one second: both answers, then no close
         $deadline = microtime(true) + 1.0;
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

   test: new Assertions(Case: function (string $response) use ($Probe, $Split): Generator {
      $Answers = $Split($Probe->wire);
      $evidence = json_encode(['error' => $Probe->error, 'eof' => $Probe->eof, 'wire' => $Probe->wire]);

      yield new Assertion(
         description: 'Both requests are answered, in order',
         fallback: "Wrong answers on a keep-alive connection: {$evidence}"
      )
         ->expect(array_column($Answers, 'tag'))
         ->to->be(['A', 'B'])
         ->assert();

      yield new Assertion(
         description: 'No answer says `Connection: close`',
         fallback: "A keep-alive answer announced a close: {$evidence}"
      )
         ->expect(preg_match('/\r\nConnection:[ \t]*close\r?$/im', $Probe->wire))
         ->to->be(0)
         ->assert();

      yield new Assertion(
         description: 'The connection stays open',
         fallback: "The keep-alive connection closed: {$evidence}"
      )
         ->expect($Probe->eof)
         ->to->be(false)
         ->assert();
   })
);
