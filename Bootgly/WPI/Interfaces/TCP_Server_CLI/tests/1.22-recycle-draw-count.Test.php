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
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;


/**
 * The two halves of worker recycling inside one worker.
 *
 * `draw()` — each worker's own limit: `maxRequests` spread by ±`jitter`, one
 * band per worker, so the workers of a server never share a limit (they never
 * recycle together) and a reforked worker draws anew inside its band.
 *
 * `count()` — one dispatch: below the limit and under `maxMemory` it only
 * counts; the dispatch that reaches either ceiling stops the counting, marks
 * the worker recycling and raises SIGQUIT on it (the drain); after that, and
 * while recycling is off, it does nothing. A gated worker — its slot's
 * previous worker still drains — stops counting at a ceiling but neither
 * recycles nor raises SIGQUIT: it serves on until the master's go. Run in a
 * forked child that catches the SIGQUIT, so this runner never receives one.
 */
return new Test(
   description: 'Recycling: draw() spreads the workers\' limits, count() raises the drain at the limit',
   skip: function_exists('pcntl_fork') === false || function_exists('posix_kill') === false,
   test: new Assertions(Case: function (): Generator {
      /** @var Closure(int,float,int,int):int $Draw */
      $Draw = Closure::bind(
         static fn (int $maxRequests, float $jitter, int $index, int $workers): int
            => TCP_Server_CLI::draw($maxRequests, $jitter, $index, $workers), // @phpstan-ignore staticMethod.private
         null,
         TCP_Server_CLI::class
      );

      // @@ draw()
      $Bands = [];
      foreach (range(0, 3) as $index) {
         $Draws = [];
         for ($draw = 0; $draw < 300; $draw++) {
            $Draws[] = $Draw(1000, 0.1, $index, 4);
         }
         $Bands[$index] = [min($Draws), max($Draws), count(array_unique($Draws)), array_sum($Draws) / count($Draws)];
      }
      $Within = [];
      foreach ($Bands as $index => [$low, $high]) {
         // ! One band per worker: [900 + 50i, 950 + 50i]
         $Within[$index] = $low >= 900 + 50 * $index && $high <= 950 + 50 * $index;
      }
      $Wrapped = [];
      for ($draw = 0; $draw < 100; $draw++) {
         $Wrapped[] = $Draw(1000, 0.1, 5, 4);
      }
      $Ones = [];
      for ($draw = 0; $draw < 100; $draw++) {
         $Ones[] = $Draw(1, 1.0, 0, 2);
         $Ones[] = $Draw(100, 1.0, 0, 2);
      }
      $Observed = [
         'off: maxRequests 0 draws 0' => $Draw(0, 0.1, 0, 4) === 0 && $Draw(0, 1.0, 3, 4) === 0,
         'no jitter: every worker draws maxRequests' => array_unique(array_map(static fn (int $index): int => $Draw(1000, 0.0, $index, 4), range(0, 7))) === [1000],
         'each worker draws inside its own band' => $Within === [true, true, true, true],
         'bands are spread (means 40+ apart)' => $Bands[1][3] - $Bands[0][3] >= 40
            && $Bands[2][3] - $Bands[1][3] >= 40
            && $Bands[3][3] - $Bands[2][3] >= 40,
         'a worker draws anew each time (10+ distinct of 300)' => $Bands[0][2] >= 10 && $Bands[3][2] >= 10,
         'a slot past the worker count wraps to its band' => min($Wrapped) >= 950 && max($Wrapped) <= 1000,
         'never below 1' => min($Ones) >= 1,
      ];

      yield new Assertion(description: 'draw() gives each worker its own limit within ±jitter')
         ->expect($Observed, Op::Identical, [
            'off: maxRequests 0 draws 0' => true,
            'no jitter: every worker draws maxRequests' => true,
            'each worker draws inside its own band' => true,
            'bands are spread (means 40+ apart)' => true,
            'a worker draws anew each time (10+ distinct of 300)' => true,
            'a slot past the worker count wraps to its band' => true,
            'never below 1' => true,
         ])
         ->assert();

      // @@ count() — in a forked child: it raises SIGQUIT on its own process
      $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      if ($Pair === false) {
         throw new RuntimeException('Could not create the count() report channel.');
      }
      $PID = pcntl_fork();
      if ($PID === 0) {
         fclose($Pair[0]);
         $quits = 0;
         pcntl_signal(SIGQUIT, static function () use (&$quits): void {
            $quits++;
         });
         $State = static function () use (&$quits): array {
            pcntl_signal_dispatch();

            return [
               TCP_Server_CLI::$dispatches,
               TCP_Server_CLI::$counting,
               TCP_Server_CLI::$recycling,
               $quits,
            ];
         };
         $Report = [];

         // # Off: nothing counts
         TCP_Server_CLI::$counting = false;
         TCP_Server_CLI::$recycling = false;
         TCP_Server_CLI::$dispatches = 0;
         TCP_Server_CLI::$quota = 3;
         TCP_Server_CLI::$maxMemory = 0;
         TCP_Server_CLI::count();
         $Report['off'] = $State();

         // # The dispatch limit
         TCP_Server_CLI::$counting = true;
         TCP_Server_CLI::count();
         TCP_Server_CLI::count();
         $Report['under'] = $State();
         TCP_Server_CLI::count();
         $Report['limit'] = $State();
         TCP_Server_CLI::count();
         TCP_Server_CLI::count();
         $Report['after'] = $State();

         // # The memory ceiling, with no dispatch limit
         $quits = 0;
         TCP_Server_CLI::$counting = true;
         TCP_Server_CLI::$recycling = false;
         TCP_Server_CLI::$dispatches = 0;
         TCP_Server_CLI::$quota = 0;
         TCP_Server_CLI::$maxMemory = memory_get_usage(true) + 64 * 1024 * 1024;
         TCP_Server_CLI::count();
         $Report['memory under'] = $State();
         TCP_Server_CLI::$maxMemory = memory_get_usage(true);
         TCP_Server_CLI::count();
         $Report['memory passed'] = $State();

         // # Held: gated — its slot's previous worker still drains
         $quits = 0;
         TCP_Server_CLI::$gated = true;
         TCP_Server_CLI::$counting = true;
         TCP_Server_CLI::$recycling = false;
         TCP_Server_CLI::$dispatches = 0;
         TCP_Server_CLI::$quota = 2;
         TCP_Server_CLI::$maxMemory = 0;
         TCP_Server_CLI::count();
         $Report['held under'] = $State();
         TCP_Server_CLI::count();
         $Report['held at the limit'] = $State();
         TCP_Server_CLI::count();
         TCP_Server_CLI::count();
         $Report['held after'] = $State();
         TCP_Server_CLI::$counting = true;
         TCP_Server_CLI::$dispatches = 0;
         TCP_Server_CLI::$quota = 0;
         TCP_Server_CLI::$maxMemory = memory_get_usage(true);
         TCP_Server_CLI::count();
         $Report['held past the memory ceiling'] = $State();
         TCP_Server_CLI::$gated = false;

         fwrite($Pair[1], (string) json_encode($Report));
         fclose($Pair[1]);
         // ! Never this runner's shutdown functions in a forked copy of it
         posix_kill(posix_getpid(), SIGKILL);
      }
      fclose($Pair[1]);
      $JSON = (string) stream_get_contents($Pair[0]);
      fclose($Pair[0]);
      if ($PID > 0) {
         pcntl_waitpid($PID, $status);
      }
      $Report = json_decode($JSON, true);

      yield new Assertion(description: 'count() counts, and at the limit or the memory ceiling marks the worker recycling and raises SIGQUIT once — unless gated: then it stops counting and serves on')
         ->expect($Report, Op::Identical, [
            // [dispatches, counting, recycling, SIGQUITs]
            'off' => [0, false, false, 0],
            'under' => [2, true, false, 0],
            'limit' => [3, false, true, 1],
            'after' => [3, false, true, 1],
            'memory under' => [1, true, false, 0],
            'memory passed' => [2, false, true, 1],
            'held under' => [1, true, false, 0],
            'held at the limit' => [2, false, false, 0],
            'held after' => [2, false, false, 0],
            'held past the memory ceiling' => [1, false, false, 0],
         ])
         ->assert();
   }),
);
