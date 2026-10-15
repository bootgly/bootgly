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
 * H-HSC-6: an HTTP/1.1 connection parks one deferral at a time. Fifty
 * deferrals pipelined in ONE write would all park at once if the transport
 * decoded them in a row — one connection could then fill the worker with
 * parked generations. Each waits, undecoded, until the one before it is
 * answered: never more than one is parked, and all fifty are answered in
 * request order.
 */
$Probe = new class {
   public string $wire = '';
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

   request: static function (string $hostPort, int $testIndex) use ($Probe, $Split): string {
      try {
         $Socket = Client::open($hostPort);
         // @ Fifty parking deferrals (20 ms each) pipelined in one write
         $requests = '';
         for ($index = 1; $index <= 50; $index++) {
            $requests .= Client::request("/deferred/queue?tag={$index}&ms=20", $testIndex);
         }
         @fwrite($Socket, $requests);

         // @@ Read until the fifty answers arrived
         $deadline = microtime(true) + 15.0;
         while (microtime(true) < $deadline && count($Split($Probe->wire)) < 50) {
            $read = [$Socket];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 50_000) !== 1) {
               continue;
            }
            $chunk = @fread($Socket, 65536);
            if ($chunk === false || $chunk === '') {
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
      $answers = $Split($Probe->wire);
      $tags = array_map(static fn (array $answer): string => (string) ($answer['tag'] ?? '?'), $answers);
      $depths = array_map(static fn (array $answer): int => (int) ($answer['depth'] ?? 0), $answers);
      $evidence = json_encode(['error' => $Probe->error, 'tags' => $tags, 'depths' => $depths]);

      yield new Assertion(
         description: 'All fifty pipelined deferrals are answered, in request order',
         fallback: "Missing or out-of-order answers: {$evidence}"
      )
         ->expect($tags)
         ->to->be(array_map(strval(...), range(1, 50)))
         ->assert();

      yield new Assertion(
         description: 'Never more than one of them is parked at once',
         fallback: "More than one deferral parked on the connection: {$evidence}"
      )
         ->expect($depths === [] ? 0 : max($depths))
         ->to->be(1)
         ->assert();
   })
);
