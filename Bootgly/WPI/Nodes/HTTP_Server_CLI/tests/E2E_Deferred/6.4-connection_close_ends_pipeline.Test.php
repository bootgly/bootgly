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
 * H-HSC-6 / RFC 9112 §9.6: a parked deferral whose request asked
 * `Connection: close` is the last exchange on its connection. Its answer is
 * written, then the connection closes — the request pipelined behind it,
 * held while it parked, is never answered.
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
      $answers[] = is_array($decoded) ? $decoded : [];
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
         // @ A parking deferral that asks to close, and a request pipelined
         //   behind it in the same write
         $parked = Client::request('/deferred/queue?tag=D', $testIndex, ['Connection' => 'close']);
         $behind = Client::request('/deferred/tag?tag=E', $testIndex);
         @fwrite($Socket, "{$parked}{$behind}");

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

   test: new Assertions(Case: function (string $response) use ($Probe, $Split): Generator {
      $tags = array_map(static fn (array $answer): string => (string) ($answer['tag'] ?? '?'), $Split($Probe->wire));
      $evidence = json_encode(['error' => $Probe->error, 'eof' => $Probe->eof, 'wire' => $Probe->wire]);

      yield new Assertion(
         description: 'The deferral that asked to close is answered and nothing behind it is',
         fallback: "Wrong answers on a closing connection: {$evidence}"
      )
         ->expect($tags)
         ->to->be(['D'])
         ->assert();

      yield new Assertion(
         description: 'The connection closes after that answer',
         fallback: "The connection stayed open: {$evidence}"
      )
         ->expect($Probe->eof)
         ->to->be(true)
         ->assert();
   })
);
