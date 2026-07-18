<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — per-request upload temp files and shared reservations leak on worker
 *   crash (audit F-10).
 *
 * `Decoder_Downloading` streams parts to `storage/temp/files/downloaded/`
 *   and reserves bytes on the cross-worker `Downloads` counter. Cleanup
 *   (`Request::clean()` → `unlink` + `Downloads::discard`) runs on the normal
 *   encode path. If a worker dies mid-request, the temp file is left on disk
 *   AND the reservation is stranded on the shared counter (the worker's
 *   in-memory `$tracked` map dies with it), permanently shrinking the global
 *   `$maxBytesOnDisk` budget until the master restarts. Repeated crashes
 *   ratchet the aggregate cap toward zero, denying all uploads.
 *
 * Defense — the shared total is treated as a *cache* of the directory size:
 *   `Downloads::reconcile()` recomputes the counter from the bytes actually
 *   on disk (dropping stranded phantom reservations), and `Downloads::sweep()`
 *   deletes temp files orphaned by a crashed worker (mtime older than
 *   `ORPHAN_TTL` = 2× the 60 s download deadline, so a live in-flight upload
 *   is never touched). Both run per worker (re)spawn via `instance()`.
 *
 * This direct unit-style PoC drives the API to prove:
 *   (1) reconcile() drops a reservation not backed by on-disk bytes,
 *   (2) reconcile() reflects the real on-disk byte total,
 *   (3) sweep(TTL) deletes an orphaned (old-mtime) file but keeps a live one.
 */

return new Specification(
   description: 'Downloads must reconcile the shared counter to disk and sweep crash-orphaned temp files',
   Separator: new Separator(line: true),

   request: function (): string {
      return "GET /downloads-reconcile-sweep HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response) {
      $dir = BOOTGLY_STORAGE_DIR . 'temp/files/downloaded/';
      if (! is_dir($dir)) {
         mkdir($dir, 0700, true);
      }

      $orphan = $dir . '_f10_orphan_' . bin2hex(random_bytes(4));
      $fresh  = $dir . '_f10_fresh_'  . bin2hex(random_bytes(4));

      try {
         // @ Establish a clean, disk-backed baseline.
         Downloads::sweep(0);     // clear any prior temp files
         Downloads::reconcile();  // shared counter := actual on-disk bytes
         $base = Downloads::peek();

         // (1) A stranded reservation (no file written — i.e. the worker that
         //     reserved it died) must be dropped by reconcile().
         Downloads::reserve(50000);
         if (Downloads::peek() !== $base + 50000) {
            return $Response(code: 200, body: 'FAIL-RESERVE-NOEFFECT');
         }
         Downloads::reconcile();
         if (Downloads::peek() !== $base) {
            return $Response(code: 200, body: 'FAIL-RECONCILE-PHANTOM:' . Downloads::peek() . ':' . $base);
         }

         // (2) reconcile() counts the bytes actually held on disk.
         file_put_contents($fresh, str_repeat('x', 1234));
         Downloads::reconcile();
         if (Downloads::peek() !== $base + 1234) {
            return $Response(code: 200, body: 'FAIL-RECONCILE-DISKBYTES:' . Downloads::peek());
         }

         // (3) sweep(TTL) deletes an orphan (old mtime) but keeps a live file.
         file_put_contents($orphan, str_repeat('y', 10));
         touch($orphan, time() - 300); // 5 min old → orphaned by a dead worker
         Downloads::sweep(Downloads::ORPHAN_TTL);
         if (file_exists($orphan)) {
            return $Response(code: 200, body: 'FAIL-SWEEP-ORPHAN-KEPT');
         }
         if (! file_exists($fresh)) {
            return $Response(code: 200, body: 'FAIL-SWEEP-FRESH-DELETED');
         }

         return $Response(code: 200, body: 'PASS');
      }
      finally {
         // @ Clean up so downstream tests see a true on-disk reflection.
         @unlink($orphan);
         @unlink($fresh);
         Downloads::reconcile();
      }
   },

   test: function (string $response): bool|string {
      if (str_contains($response, 'PASS')) {
         return true;
      }

      Vars::$labels = ['HTTP Response'];
      dump($response);
      return 'Downloads reconcile/sweep PoC reported: ' . $response;
   }
);
