<?php

use function clearstatcache;
use function explode;
use function extension_loaded;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function mkdir;
use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wifsignaled;
use function posix_getpid;
use function posix_kill;
use function scandir;
use function str_contains;
use function str_repeat;
use function substr;
use function tempnam;
use function time;
use function touch;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC (realistic) — a worker that crashes mid-upload leaks its `Downloads`
 *   reservation + temp file; the per-(re)spawn recovery heals it (audit F-10).
 *
 * Unlike the direct-API probe in `29.01`, this exercises an actual process
 *   death over the wire. The handler forks a child that stands in for a worker
 *   crashing mid-upload: the child reserves bytes on the shared SHM counter and
 *   writes a temp file, then dies by **SIGKILL** — uncatchable, so neither
 *   `Request::__destruct()` nor `Downloads::discard()` runs and the reservation
 *   + file are leaked exactly as in a real OOM/segfault/SIGKILL crash. (A
 *   forked child stands in for the suite's single shared worker so the server
 *   stays up for the assertion; the leaked state on the *shared* counter is
 *   identical either way.)
 *
 * The parent then runs the exact recovery a respawned worker performs in
 *   `HTTP_Server_CLI::instance()` — `Downloads::sweep(ORPHAN_TTL)` +
 *   `Downloads::reconcile()` — and reports the counter before/after so the test
 *   can assert:
 *     (1) the child really died by signal (the crash is real),
 *     (2) the reservation was leaked (peek inflated, discard never ran),
 *     (3) reconcile()/sweep() healed it (peek back to the on-disk total, and
 *         the orphaned temp file is gone).
 */

return new Specification(
   description: 'A SIGKILLed worker leaks its Downloads reservation; sweep()+reconcile() heal it',
   Separator: new Separator(line: true),

   request: function (): string {
      return "GET /downloads-crash-recovery HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/downloads-crash-recovery', function (Request $Request, Response $Response) {
         if (
            ! extension_loaded('shmop')
            || ! extension_loaded('pcntl')
            || ! extension_loaded('posix')
         ) {
            return $Response->JSON->send(['skip' => true]);
         }

         $dir = BOOTGLY_STORAGE_DIR . 'temp/files/downloaded/';
         if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
         }

         // @ Clean, disk-backed baseline.
         Downloads::sweep(0);
         Downloads::reconcile();
         $base = Downloads::peek();

         $reservation = 500_000_000; // 500 MB

         // @ Fork the "crashing worker".
         $pid = pcntl_fork();
         if ($pid === 0) {
            // # Child — reserve on the shared counter, drop a temp file, then
            //   die uncleanly. No destructor / discard runs (SIGKILL).
            Downloads::reserve($reservation);

            $tmp = tempnam($dir, '');
            if ($tmp !== false) {
               file_put_contents($tmp, str_repeat('x', 4096));
               touch($tmp, time() - 300); // older than ORPHAN_TTL → orphaned
            }

            posix_kill(posix_getpid(), SIGKILL);
            exit(1); // unreachable
         }

         // # Parent — reap the dead child, observe the leak, then heal.
         $status = 0;
         pcntl_waitpid($pid, $status);
         $signaled = pcntl_wifsignaled($status);

         $leaked = Downloads::peek(); // base + reservation — discard never ran

         // @ Recovery: byte-for-byte what a respawned worker's instance() runs.
         Downloads::sweep(Downloads::ORPHAN_TTL);
         Downloads::reconcile();

         $healed = Downloads::peek();

         // @ Count leftover non-dotfile temp files (the orphan must be swept).
         clearstatcache();
         $orphans = 0;
         foreach (scandir($dir) ?: [] as $entry) {
            if ($entry[0] !== '.' && is_file($dir . $entry)) {
               $orphans++;
            }
         }

         return $Response->JSON->send([
            'skip'      => false,
            'signaled'  => $signaled,
            'base'      => $base,
            'leaked'    => $leaked,
            'healed'    => $healed,
            'reserved'  => $reservation,
            'orphans'   => $orphans,
         ]);
      }, GET);
   },

   test: function (string $response): bool|string {
      if (! str_contains($response, '200 OK')) {
         return 'Handler did not run (expected 200 OK). Response: ' . substr($response, 0, 200);
      }

      $parts = explode("\r\n\r\n", $response, 2);
      $decoded = json_decode($parts[1] ?? '', true);
      if (! is_array($decoded)) {
         Vars::$labels = ['Body:'];
         dump($parts[1] ?? '');
         return 'Response body is not JSON.';
      }

      if (($decoded['skip'] ?? false) === true) {
         return true; // shmop/pcntl/posix unavailable
      }

      // (1) The crash must be a real signal death.
      if (($decoded['signaled'] ?? false) !== true) {
         return 'Child did not die by signal — the crash was not simulated: ' . json_encode($decoded);
      }

      $base   = (int) ($decoded['base'] ?? 0);
      $leaked = (int) ($decoded['leaked'] ?? 0);
      $healed = (int) ($decoded['healed'] ?? 0);

      // (2) The reservation must have leaked (discard never ran on SIGKILL).
      if ($leaked - $base < 400_000_000) {
         return 'The SIGKILLed worker did NOT leak its reservation '
            . "(base={$base}, leaked={$leaked}) — the crash-leak was not reproduced.";
      }

      // (3) sweep()+reconcile() must heal it back to the on-disk total.
      if ($healed - $base >= 1_000_000) {
         return 'reconcile() did NOT heal the leaked reservation after the crash '
            . "(base={$base}, leaked={$leaked}, healed={$healed}).";
      }

      // (3b) The orphaned temp file must be swept.
      if (($decoded['orphans'] ?? -1) !== 0) {
         return 'The crash-orphaned temp file was not swept: orphans=' . json_encode($decoded['orphans'] ?? null);
      }

      return true;
   }
);
