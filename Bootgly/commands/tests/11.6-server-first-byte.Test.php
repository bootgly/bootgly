<?php
namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const PHP_BINARY;
use function assert;
use function fclose;
use function fread;
use function fsockopen;
use function function_exists;
use function fwrite;
use function getenv;
use function glob;
use function hrtime;
use function is_dir;
use function is_file;
use function is_resource;
use function max;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function str_contains;
use function str_starts_with;
use function stream_select;
use function stream_set_blocking;
use function stream_set_timeout;
use function unlink;
use function usleep;

use Bootgly\ACI\Tests\Suite\Test;


/**
 * A server started with stdin at /dev/null and stdout on a pipe — the shape of
 * `docker run -d` — prints its first byte and answers a request within a
 * budget, every time. The stall seen on v1.0.0 (12-20 s before "Starting
 * Server...") has no spec that would have caught it; this is that spec.
 */
return new Test(
   description: '`project <Name> start -f` detached from a terminal prints its first byte and answers within 5 s, three runs in a row',
   // ? Nothing to spawn, nothing to start, or no clean measurement on :8082
   skip: function_exists('proc_open') === false
      || is_file(BOOTGLY_ROOT_BASE . '/bootgly') === false
      || is_dir(BOOTGLY_ROOT_BASE . '/projects/Demo/HTTP_Server_CLI') === false
      || (static function (): bool {
         $busy = @fsockopen('127.0.0.1', 8082, $errno, $errstr, 0.2);
         if ($busy === false) {
            return false;
         }
         fclose($busy);

         return true;
      })(),
   test: function () {
      $launcher = BOOTGLY_ROOT_BASE . '/bootgly';
      $environment = getenv();
      unset($environment['BOOTGLY_TTY']);

      $budget = 5000;
      $runs = 3;
      $worst = 0.0;
      $Process = null;
      $pipes = [];
      // ! A master that had to be KILLed may leave workers on :8082 — its pid
      //   files then stay, so `project … stop` can still reach them
      $killed = false;
      try {
         for ($run = 1; $run <= $runs; $run++) {
            $Process = proc_open(
               [PHP_BINARY, $launcher, 'project', 'Demo/HTTP_Server_CLI', 'start', '-f'],
               [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
               $pipes,
               BOOTGLY_ROOT_BASE,
               $environment
            );
            if (is_resource($Process) === false) {
               yield assert(assertion: false, description: "run {$run}: the server could not be spawned");
               return;
            }
            stream_set_blocking($pipes[1], false);
            $started = hrtime(true);
            $first = null;
            $ready = null;
            $output = '';
            // @@ Wait for the first byte, then for an HTTP answer, up to the budget
            while ((hrtime(true) - $started) / 1e6 < $budget) {
               $read = [$pipes[1]];
               $write = null;
               $except = null;
               if (stream_select($read, $write, $except, 0, 100000) > 0) {
                  $chunk = (string) fread($pipes[1], 8192);
                  if ($chunk !== '') {
                     $first ??= (hrtime(true) - $started) / 1e6;
                     $output .= $chunk;
                  }
               }
               if ($first !== null) {
                  $Socket = @fsockopen('127.0.0.1', 8082, $errno, $errstr, 0.2);
                  if ($Socket !== false) {
                     // ! An accepted connection proves the backlog; a status
                     //   line proves a worker is serving
                     stream_set_timeout($Socket, 1);
                     fwrite($Socket, "GET / HTTP/1.0\r\nHost: localhost\r\n\r\n");
                     $answer = (string) fread($Socket, 16);
                     fclose($Socket);
                     if (str_starts_with($answer, 'HTTP/1.')) {
                        $ready = (hrtime(true) - $started) / 1e6;
                        break;
                     }
                  }
               }
               if (proc_get_status($Process)['running'] === false) {
                  break;
               }
            }
            $alive = proc_get_status($Process)['running'];
            // @ Stop it — TERM is the daemon's own stop signal
            proc_terminate($Process, 15);
            $deadline = hrtime(true) + 5e9;
            while (proc_get_status($Process)['running'] && hrtime(true) < $deadline) {
               usleep(50000);
            }
            if (proc_get_status($Process)['running']) {
               proc_terminate($Process, 9);
               $killed = true;
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($Process);
            $Process = null;

            yield assert(
               assertion: $first !== null && $ready !== null && $alive,
               description: "run {$run}: first byte at " . (int) ($first ?? -1) . " ms, HTTP answer at " . (int) ($ready ?? -1) . " ms, server still up — budget {$budget} ms"
            );
            yield assert(
               assertion: str_contains($output, 'Starting Server'),
               description: "run {$run}: what arrived is the server banner, not a refusal"
            );
            $worst = max($worst, (float) ($ready ?? $budget));
            // ! The next run needs the port back
            usleep(300000);
         }

         yield assert(
            assertion: $worst < $budget,
            description: 'worst time-to-answer over ' . $runs . ' runs: ' . (int) $worst . ' ms'
         );
      }
      finally {
         // @ A server still running here was abandoned by a throw — stop it
         if (is_resource($Process)) {
            proc_terminate($Process, 15);
            $deadline = hrtime(true) + 5e9;
            while (proc_get_status($Process)['running'] && hrtime(true) < $deadline) {
               usleep(50000);
            }
            if (proc_get_status($Process)['running']) {
               proc_terminate($Process, 9);
               $killed = true;
            }
            foreach ($pipes as $pipe) {
               if (is_resource($pipe)) {
                  fclose($pipe);
               }
            }
            proc_close($Process);
         }
         // @ The state the demo left in storage/pids is the spec's to remove —
         //   on every exit, a failed spawn included — unless a KILL may have
         //   left workers behind that only those files can name
         if ($killed === false) {
            foreach (glob(BOOTGLY_ROOT_BASE . '/storage/pids/Demo~HTTP_Server_CLI.8082.*') ?: [] as $left) {
               @unlink($left);
            }
         }
      }
   }
);
