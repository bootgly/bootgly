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
 * H-HSC-6: the requests pipelined behind a parked deferral wait in the
 * transport carry. When that deferral hands its connection to an event
 * stream, the connection is write-only from then on (`Decoder_Streaming`):
 * the held requests are discarded, never decoded — no second response head
 * may appear inside the open stream, which stays open for at least a second.
 */
$Probe = new class {
   public string $wire = '';
   public bool $eof = false;
   /** Seconds from the stream head to the end of the connection. */
   public float $open = 0.0;
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         // @ A parking deferral that will hand off to an event stream, a
         //   request pipelined behind it in the same write and another one
         //   written while it parks
         $parked = Client::request('/deferred/handoff', $testIndex);
         $behind = Client::request('/deferred/tag?tag=E1', $testIndex);
         @fwrite($Socket, "{$parked}{$behind}");
         usleep(100_000);
         @fwrite($Socket, Client::request('/deferred/tag?tag=E2', $testIndex));

         // @@ Read the stream until the server ends it
         $headed = 0.0;
         $deadline = microtime(true) + 5.0;
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
            if ($headed === 0.0 && str_contains($Probe->wire, "\r\n\r\n")) {
               $headed = microtime(true);
            }
         }
         $Probe->open = $headed === 0.0 ? 0.0 : microtime(true) - $headed;
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
      $evidence = json_encode([
         'error' => $Probe->error,
         'eof' => $Probe->eof,
         'open' => $Probe->open,
         'wire' => $Probe->wire
      ]);

      yield new Assertion(
         description: 'The parked deferral handed off to an event stream that sent its event, stayed open and ended',
         fallback: "No complete event stream: {$evidence}"
      )
         ->expect([
            str_starts_with($Probe->wire, 'HTTP/1.1 200 '),
            stripos($Probe->wire, "\r\nContent-Type: text/event-stream") !== false,
            str_contains($Probe->wire, 'data: {"handoff":true}'),
            str_contains($Probe->wire, "\r\n0\r\n\r\n"),
            $Probe->eof,
            $Probe->open >= 0.3
         ])
         ->to->be([true, true, true, true, true, true])
         ->assert();

      yield new Assertion(
         description: 'The held requests are discarded: no second response head on the wire',
         fallback: "A held request was answered: {$evidence}"
      )
         ->expect([substr_count($Probe->wire, 'HTTP/1.1 '), str_contains($Probe->wire, '"tag"')])
         ->to->be([1, false])
         ->assert();
   })
);
