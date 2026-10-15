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
 * H-HSC-6 / ENC-3: an HTTP/1.1 connection answers in request order (RFC 9112
 * §9.3.2). A synchronous request pipelined behind a parked deferral — in the
 * same write, or in a later write while the deferral still parks — waits for
 * it: the deferral's answer leaves first, then the synchronous one.
 *
 * Each leg checks its own timing first, and fails as inconclusive when it
 * did not hold: the deferral's answer arrived at least 0.25 s after its write
 * (it parked), and the later request was written before anything arrived and
 * at least 0.25 s before the deferral's answer (it reached the server while
 * the deferral still parked). Otherwise a slow host could answer the deferral
 * before the request behind it exists, and the order would prove nothing.
 */
$Probe = new class {
   public string $same = '';
   public string $later = '';
   /** @var array<string,float> Seconds from the first write to each answer, by tag */
   public array $sameArrivals = [];
   /** @var array<string,float> Seconds from the first write to each answer, by tag */
   public array $laterArrivals = [];
   /** Seconds from the parked deferral's write to the later write; -1 before it. */
   public float $written = -1.0;
   /** Whether anything had arrived before the later write. */
   public bool $early = false;
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
// ! Read the wire until `$count` answers arrived, the peer closed or
//   `$timeout` seconds passed — and when each answer arrived, in seconds
//   after `$start`, by its tag
$Read = static function ($Socket, int $count, float $timeout, float $start) use ($Split): array {
   $wire = '';
   $arrivals = [];
   $deadline = microtime(true) + $timeout;
   // @@
   while (microtime(true) < $deadline && count($Split($wire)) < $count) {
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
      $wire .= $chunk;

      $elapsed = round(microtime(true) - $start, 3);
      foreach ($Split($wire) as $answer) {
         $arrivals[(string) ($answer['tag'] ?? '?')] ??= $elapsed;
      }
   }

   // :
   return [$wire, $arrivals];
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $Read): string {
      try {
         // @ Same write: a parked deferral and a synchronous request behind it
         $Socket = Client::open($hostPort);
         $parked = Client::request('/deferred/queue?tag=D', $testIndex);
         $behind = Client::request('/deferred/tag?tag=E', $testIndex);
         $start = microtime(true);
         @fwrite($Socket, "{$parked}{$behind}");
         [$Probe->same, $Probe->sameArrivals] = $Read($Socket, 2, 3.0, $start);
         Client::close($Socket);

         // @ Later write: the synchronous request arrives while the deferral
         //   (600 ms) still parks
         $Socket = Client::open($hostPort);
         $start = microtime(true);
         @fwrite($Socket, Client::request('/deferred/queue?tag=D&ms=600', $testIndex));
         usleep(100_000);
         // ! Nothing may have arrived before the later write
         $read = [$Socket];
         $write = null;
         $except = null;
         $Probe->early = @stream_select($read, $write, $except, 0, 0) === 1;
         $Probe->written = round(microtime(true) - $start, 3);
         @fwrite($Socket, Client::request('/deferred/tag?tag=E', $testIndex));
         [$Probe->later, $Probe->laterArrivals] = $Read($Socket, 2, 3.0, $start);
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
      $same = array_map(static fn (array $answer): string => (string) ($answer['tag'] ?? '?'), $Split($Probe->same));
      $later = array_map(static fn (array $answer): string => (string) ($answer['tag'] ?? '?'), $Split($Probe->later));
      $evidence = json_encode([
         'error' => $Probe->error,
         'same' => $Probe->same,
         'sameArrivals' => $Probe->sameArrivals,
         'later' => $Probe->later,
         'laterArrivals' => $Probe->laterArrivals,
         'written' => $Probe->written,
         'early' => $Probe->early,
      ]);

      // @ Timing preconditions: inconclusive unless they held
      yield new Assertion(
         description: 'Precondition: the deferral of the same write parked (answered at least 0.25 s after the write)',
         fallback: "Inconclusive — the deferral did not park: {$evidence}"
      )
         ->expect(($Probe->sameArrivals['D'] ?? 0.0) >= 0.25)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Precondition: the later request was written while the deferral still parked (nothing arrived before it, the deferral answered at least 0.25 s after it)',
         fallback: "Inconclusive — the later request was not written while the deferral parked: {$evidence}"
      )
         ->expect(
            $Probe->early === false
            && $Probe->written >= 0.0
            && ($Probe->laterArrivals['D'] ?? 0.0) - $Probe->written >= 0.25
         )
         ->to->be(true)
         ->assert();

      // @ Order
      yield new Assertion(
         description: 'A request pipelined in the same write is answered after the parked deferral',
         fallback: "Answered out of request order: {$evidence}"
      )
         ->expect($same)
         ->to->be(['D', 'E'])
         ->assert();

      yield new Assertion(
         description: 'A request written while the deferral parks is answered after it',
         fallback: "Answered out of request order: {$evidence}"
      )
         ->expect($later)
         ->to->be(['D', 'E'])
         ->assert();
   })
);
