<?php

use function extension_loaded;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `Decoder_Downloading::writeFileChunk()` in isolation enforces
 *   the per-file cap (`Request::$maxFileSize`) but does not bound the
 *   *aggregate* bytes held in `workdata/temp/files/downloaded/` across
 *   workers. With N workers and per-file cap C, a coordinated client
 *   sustains an unbounded N×C × in-flight footprint until disk fills
 *   and every subsequent fwrite returns -ENOSPC.
 *
 * Defense — `Downloads` exposes a cross-worker shared-memory counter
 *   gated by an advisory file lock. `reserve()` performs an atomic
 *   read-modify-write check against `$maxBytesOnDisk` and rejects when
 *   the new total would breach the ceiling. `release()` decrements,
 *   saturating at zero. `track()`/`discard()` associate per-file
 *   tracked totals with their tmp paths so `Request::__destruct()` can
 *   release exactly what was reserved without ever risking a leak.
 *
 * This direct unit-style PoC drives the API to prove:
 *   (1) reserve() returns false at the boundary,
 *   (2) the counter is consistent under interleaved reserve/release,
 *   (3) discard() releases exactly the tracked total and is
 *       idempotent on a non-existent path.
 */

return new Specification(
   description: 'Aggregate downloads disk cap must atomically reject reservations beyond the ceiling',
   Separator: new Separator(line: true),

   request: function (): string {
      // @ Drive checks server-side; harness route only needs to reply 200.
      return "GET /downloads-aggregate-cap HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response) {
      if (! extension_loaded('shmop')) {
         return $Response(code: 200, body: 'SKIP-NO-SHMOP');
      }

      $oldCap = Downloads::$maxBytesOnDisk;
      $baseline = Downloads::peek();

      try {
         // @ Drop the cap to a small ceiling above the current baseline so
         //   we exercise the edge without disturbing concurrent workers.
         Downloads::$maxBytesOnDisk = $baseline + 1000;

         // (1) Within-cap reservation must succeed.
         if (Downloads::reserve(600) !== true) {
            return $Response(code: 200, body: 'FAIL-RESERVE-600');
         }

         // (2) Reservation that would overshoot the cap must be rejected
         //     atomically — the counter must NOT be advanced.
         $beforeOvershoot = Downloads::peek();
         if (Downloads::reserve(600) !== false) {
            return $Response(code: 200, body: 'FAIL-RESERVE-OVERSHOOT-ACCEPTED');
         }
         if (Downloads::peek() !== $beforeOvershoot) {
            return $Response(code: 200, body: 'FAIL-COUNTER-MUTATED-ON-REJECT');
         }

         // (3) After release(), reservation that fits the freed budget
         //     must succeed.
         Downloads::release(300);
         if (Downloads::reserve(300) !== true) {
            return $Response(code: 200, body: 'FAIL-RESERVE-AFTER-RELEASE');
         }

         // (4) track() + discard() drains exactly what was tracked.
         $tmp = '/tmp/_bootgly_downloads_test_' . bin2hex(random_bytes(4));
         Downloads::track($tmp, 200);
         $beforeDiscard = Downloads::peek();
         Downloads::discard($tmp);
         if (Downloads::peek() !== $beforeDiscard - 200) {
            return $Response(code: 200, body: 'FAIL-DISCARD-MISMATCH');
         }

         // (5) discard() on an unknown tmp_name is a no-op.
         $afterIdempotent = Downloads::peek();
         Downloads::discard('/tmp/_bootgly_downloads_unknown_' . bin2hex(random_bytes(4)));
         if (Downloads::peek() !== $afterIdempotent) {
            return $Response(code: 200, body: 'FAIL-DISCARD-NOT-IDEMPOTENT');
         }

         // @ Drain residual reservations made by this PoC so the counter
         //   returns exactly to baseline for downstream tests.
         Downloads::release(600);

         if (Downloads::peek() !== $baseline) {
            return $Response(code: 200, body: 'FAIL-COUNTER-NOT-RESTORED:' . Downloads::peek() . ':' . $baseline);
         }

         return $Response(code: 200, body: 'PASS');
      }
      finally {
         Downloads::$maxBytesOnDisk = $oldCap;
      }
   },

   test: function (string $response): bool|string {
      if (str_contains($response, 'SKIP-NO-SHMOP')) {
         return true;
      }
      if (str_contains($response, 'PASS')) {
         return true;
      }

      Vars::$labels = ['HTTP Response'];
      dump($response);
      return 'Aggregate downloads disk cap PoC reported: ' . $response;
   }
);
