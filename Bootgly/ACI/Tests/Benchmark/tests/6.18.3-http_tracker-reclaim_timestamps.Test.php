<?php

use Bootgly\ACI\Tests\Benchmark\HTTP\Tracker;
use Bootgly\ACI\Tests\Benchmark\Latency\Histogram;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should reclaim the send-timestamp FIFO without waiting for a full drain',
   test: function () {
      $response = "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n";

      /**
       * Reads one private field of a tracker. The FIFO is an implementation
       * detail with no accessor, and its size is precisely what is under test.
       */
      $peek = static fn (Tracker $Tracker, string $property): mixed
         => new ReflectionProperty(Tracker::class, $property)->getValue($Tracker);

      /**
       * Holds a pipeline of $depth open for $rounds responses: one response
       * out, one request in, so the number outstanding never reaches zero.
       *
       * @return array{Tracker: Tracker, segments: int, head: int, bytes: int}
       */
      $steady = static function (int $depth, int $rounds) use ($peek, $response): array {
         $Tracker = new Tracker(Histogram: new Histogram);
         $stamp = 1_000_000;

         for ($i = 0; $i < $depth; $i++) {
            $stamp += 1_000;
            $Tracker->send(1, $stamp);
         }

         $before = memory_get_usage();

         for ($i = 0; $i < $rounds; $i++) {
            $stamp += 1_000;
            $Tracker->feed($response, $stamp);
            $stamp += 1_000;
            $Tracker->send(1, $stamp);
         }

         return [
            'Tracker'  => $Tracker,
            'segments' => count($peek($Tracker, 'timestamps')),
            'head'     => $peek($Tracker, 'timestampHead'),
            'bytes'    => memory_get_usage() - $before,
         ];
      };

      // @@ The filed regime: a long-lived connection at pipeline depth >= 2
      //    never drains to zero, so the full-drain reset never fires
      $rounds = 5_000;

      foreach ([2, 4, 16] as $depth) {
         $observed = $steady($depth, $rounds);

         yield assert(
            assertion: $observed['segments'] <= 128,
            description: "A pipeline of depth {$depth} retained {$observed['segments']} FIFO "
               . "segments across {$rounds} responses (head at {$observed['head']}); "
               . 'the FIFO holds one segment per distinct outstanding send timestamp, '
               . 'so this must stay near the depth and never near the round count',
         );

         // @ The filed symptom, measured independently of the representation:
         //   ~400 bytes per retained segment is what kills a load generator at
         //   a 128 M limit after ~320k responses on one connection.
         yield assert(
            assertion: $observed['bytes'] < 200_000,
            description: "A pipeline of depth {$depth} grew {$observed['bytes']} bytes across "
               . "{$rounds} responses on a single connection",
         );
      }

      // @@ Control — depth 1 consumes the scalar fast slot and never
      //    materializes the array at all
      $observed = $steady(1, $rounds);

      yield assert(
         assertion: $observed['segments'] === 0,
         description: 'Pipeline 1 must never materialize the generic FIFO, got '
            . $observed['segments'] . ' segments',
      );

      // @@ Control — compaction must not lose, reorder or mis-pair a single
      //    timestamp. Every response here is exactly 1000 ns after its own
      //    request, so any slippage moves min or max.
      $Histogram = new Histogram;
      $Tracker = new Tracker(Histogram: $Histogram);
      $stamp = 1_000_000;
      $Tracker->send(1, $stamp);
      $stamp += 1_000;
      $Tracker->send(1, $stamp);

      for ($i = 0; $i < 1_000; $i++) {
         $stamp += 1_000;
         $Tracker->feed($response, $stamp);
         $stamp += 1_000;
         $Tracker->send(1, $stamp);
      }

      $summary = $Histogram->inspect();

      yield assert(
         assertion: $summary['count'] === 1_000
            && $summary['min_ns'] === 2_000
            && $summary['max_ns'] === 3_000,
         description: 'Compaction preserved every request-to-response pairing: '
            . json_encode($summary),
      );

      // @@ Control — a pipeline that DOES drain still hands the scalar fast
      //    slot back, which is the branch the incremental reclaim sits beside
      $Drained = new Tracker(Histogram: new Histogram);
      $Drained->send(1, 1_000_000);
      $Drained->send(1, 2_000_000);
      $Drained->feed($response, 3_000_000);
      $Drained->feed($response, 4_000_000);
      $Drained->send(1, 5_000_000);

      yield assert(
         assertion: $peek($Drained, 'timestamps') === []
            && $peek($Drained, 'timestampHead') === 0
            && $peek($Drained, 'timestampFastCount') === 1,
         description: 'A fully drained FIFO returns to the scalar fast slot: '
            . json_encode([
               'timestamps' => $peek($Drained, 'timestamps'),
               'head' => $peek($Drained, 'timestampHead'),
               'fast' => $peek($Drained, 'timestampFastCount'),
            ]),
      );
   },
);
