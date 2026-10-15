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
 * H-HSC-6: a deferral parks on connection A, connection B is answered by the
 * worker meanwhile, then a second deferral is written on A while the first
 * still parks. Both of A's deferrals are answered, in order — the second was
 * once dropped silently: B's answer cleared the worker's shared Response, so
 * A's live Request kept the first exchange and the second `defer()` found a
 * foreign lease and scheduled nothing.
 *
 * The first deferral parks 600 ms. The case fails as inconclusive unless the
 * second was written while the first still parked: nothing had arrived on A
 * before it, and the first answer arrived at least 0.25 s after it. Otherwise
 * a slow host could answer the first deferral before the second exists, and
 * no stale lease would be met.
 */
$Probe = new class {
   public string $first = '';
   /** @var array<string,mixed> */
   public array $other = [];
   /** Seconds from A1's write to A2's write; -1 before it. */
   public float $written = -1.0;
   /** Seconds from A1's write to A1's answer; -1 until it arrives. */
   public float $answered = -1.0;
   /** Whether anything had arrived on A before A2's write. */
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

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $Split): string {
      try {
         // @ A: the first deferral parks (600 ms)
         $First = Client::open($hostPort);
         $start = microtime(true);
         @fwrite($First, Client::request('/deferred/queue?tag=A1&ms=600', $testIndex));
         usleep(50_000);

         // @ B: a synchronous exchange on another connection, answered
         $Other = Client::open($hostPort);
         $Probe->other = Client::send($Other, Client::request('/deferred/tag?tag=B', $testIndex), 2.0);
         Client::close($Other);
         usleep(50_000);

         // ! Nothing may have arrived on A before the second deferral
         $read = [$First];
         $write = null;
         $except = null;
         $Probe->early = @stream_select($read, $write, $except, 0, 0) === 1;
         // @ A: the second deferral, written while the first still parks
         $Probe->written = round(microtime(true) - $start, 3);
         @fwrite($First, Client::request('/deferred/queue?tag=A2', $testIndex));

         // @@ Read A until both answers arrived
         $deadline = microtime(true) + 3.0;
         while (microtime(true) < $deadline && count($Split($Probe->first)) < 2) {
            $read = [$First];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 50_000) !== 1) {
               continue;
            }
            $chunk = @fread($First, 65536);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $Probe->first .= $chunk;
            if ($Probe->answered < 0.0 && $Split($Probe->first) !== []) {
               $Probe->answered = round(microtime(true) - $start, 3);
            }
         }
         Client::close($First);
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
      $tags = array_map(static fn (array $answer): string => (string) ($answer['tag'] ?? '?'), $Split($Probe->first));
      $other = json_decode((string) ($Probe->other['body'] ?? ''), true);
      $evidence = json_encode([
         'error' => $Probe->error,
         'written' => $Probe->written,
         'answered' => $Probe->answered,
         'early' => $Probe->early,
         'first' => $Probe->first,
         'other' => $Probe->other,
      ]);

      // @ Timing precondition: inconclusive unless it held
      yield new Assertion(
         description: 'Precondition: the second deferral was written while the first still parked (nothing arrived before it, the first answered at least 0.25 s after it)',
         fallback: "Inconclusive — the second deferral was not written while the first parked: {$evidence}"
      )
         ->expect(
            $Probe->early === false
            && $Probe->written >= 0.0
            && $Probe->answered - $Probe->written >= 0.25
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The other connection is answered while the first deferral parks',
         fallback: "The other connection was not answered: {$evidence}"
      )
         ->expect($other)
         ->to->be(['tag' => 'B'])
         ->assert();

      yield new Assertion(
         description: 'Both deferrals on the first connection are answered, in order',
         fallback: "A deferral was dropped: {$evidence}"
      )
         ->expect($tags)
         ->to->be(['A1', 'A2'])
         ->assert();
   })
);
