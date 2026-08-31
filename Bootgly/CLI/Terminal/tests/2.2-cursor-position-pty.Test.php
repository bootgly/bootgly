<?php

use Bootgly\ACI\Tests\Suite\Test;


// ! The property under test queries the process's own controlling terminal, so the case
//   must BE a pty master: it spawns the probe with proc_open pty descriptors and decides
//   itself whether to answer the Device Status Report. A pty is exactly what the entry
//   is about — a tty with nobody behind it (script-wrapped CI, docker run -t, expect).
$supported = false;
if (DIRECTORY_SEPARATOR !== '\\' && function_exists('proc_open') === true) {
   try {
      $Probe = @proc_open('exit 0', [0 => ['pty']], $pipes);
      if (is_resource($Probe) === true) {
         $supported = true;
         @proc_close($Probe);
      }
   }
   catch (Throwable) {
      // ? This PHP build carries no pty support
   }
}

return new Test(
   description: 'Terminal(Cursor): the position query is bounded and restores the terminal on a silent pty',
   skip: $supported === false
      || function_exists('shell_exec') === false
      || trim((string) @shell_exec('command -v stty timeout sh 2>/dev/null')) === ''
      || in_array(trim((string) @shell_exec('command -v php 2>/dev/null')), ['', '0'], true),
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-cursor-pty-' . bin2hex(random_bytes(4));
      @mkdir($dir, 0700);

      // ! The pty master: spawns $command on a fresh pty, drains everything the probe
      //   writes, optionally answers the DSR (whole or split), and never waits past
      //   its own deadline — a hung probe is killed and reported, not inherited.
      $drive = static function (array $command, null|array $reply, string $report) {
         $Process = proc_open(
            $command,
            [0 => ['pty'], 1 => ['pty'], 2 => ['pty']],
            $pipes
         );

         if (is_resource($Process) === false) {
            return ['error' => 'proc_open failed'];
         }

         stream_set_blocking($pipes[1], false);

         $seen = '';
         $answered = false;
         $deadline = microtime(true) + 8.0;

         // @@ Drain the pty; answer the query once when a reply is configured
         while (microtime(true) < $deadline) {
            $status = proc_get_status($Process);
            $bytes = @fread($pipes[1], 8192);

            if ($bytes !== false && $bytes !== '') {
               $seen .= $bytes;

               if ($reply !== null && $answered === false && str_contains($seen, "\e[6n")) {
                  foreach ($reply as $fragment) {
                     fwrite($pipes[0], $fragment);
                     // ! A pause between fragments forces the split-reply shape the
                     //   old single fread() misparsed
                     if (count($reply) > 1) {
                        usleep(60000);
                     }
                  }

                  $answered = true;
               }
            }

            if ($status['running'] === false) {
               $observed = json_decode(trim((string) @file_get_contents($report)), true);

               return [
                  'timed_out' => false,
                  'saw_query' => str_contains($seen, "\e[6n"),
                  'observed' => is_array($observed) ? $observed : null,
               ];
            }

            usleep(20000);
         }

         proc_terminate($Process, 9);
         proc_close($Process);

         return ['timed_out' => true, 'saw_query' => str_contains($seen, "\e[6n")];
      };
      $probe = __DIR__ . '/cursor-pty.php';
      $PHP = PHP_BINARY;

      try {
         // # A pty with no DSR responder: the query must come back, bounded, with the
         //   terminal restored — before the fix the read blocked forever in raw mode
         //   and an interrupt left the tty at -icrnl -icanon -echo
         $wrapper = "{$dir}/silent.sh";
         file_put_contents($wrapper, "#!/bin/sh\n"
            . "stty -a > '{$dir}/before.txt' 2>&1\n"
            . "timeout -s INT 4 '{$PHP}' -r 'require \$_SERVER[\"argv\"][1];'"
               . " '{$probe}' '{$dir}/silent.json'\n"
            . "stty -a > '{$dir}/after.txt' 2>&1\n");
         chmod($wrapper, 0700);

         $silent = $drive(['sh', $wrapper], null, "{$dir}/silent.json");
         $observed = $silent['observed'] ?? null;

         yield assert(
            assertion: ($silent['timed_out'] ?? null) === false
               && ($silent['saw_query'] ?? null) === true
               && is_array($observed),
            description: 'A query on a silent pty must produce a result: '
               . json_encode($silent)
         );

         if (is_array($observed) === false) {
            return;
         }

         yield assert(
            assertion: ($observed['position']['row'] ?? null) === 0
               && ($observed['position']['column'] ?? null) === 0
               && ($observed['elapsed'] ?? 99.0) < 2.0
               && ($observed['stdin_tty'] ?? null) === true,
            description: 'A silent pty degrades to 0,0 inside the bound, found: '
               . json_encode($observed)
         );

         // # …and the terminal modes survive the degraded query
         $modes = static function (string $file): array {
            $all = (string) @file_get_contents($file);
            $found = [];

            foreach (['icanon', 'echo', 'icrnl'] as $mode) {
               $found[$mode] = preg_match("/(-?){$mode}\b/", $all, $matches) === 1
                  ? ($matches[1] === '-' ? "-{$mode}" : $mode)
                  : '?';
            }

            return $found;
         };
         $before = $modes("{$dir}/before.txt");
         $after = $modes("{$dir}/after.txt");

         yield assert(
            assertion: $before === ['icanon' => 'icanon', 'echo' => 'echo', 'icrnl' => 'icrnl']
               && $after === $before,
            description: 'The terminal modes must be restored, found: '
               . json_encode(['before' => $before, 'after' => $after])
         );

         // # A pty whose master answers the report in one piece — the honest path
         $command = [
            $PHP, '-r', 'require $_SERVER["argv"][1];', $probe, "{$dir}/whole.json"
         ];
         $whole = $drive($command, ["\e[12;34R"], "{$dir}/whole.json");
         $observed = $whole['observed'] ?? null;

         yield assert(
            assertion: ($observed['position']['row'] ?? null) === 12
               && ($observed['position']['column'] ?? null) === 34
               && ($observed['elapsed'] ?? 99.0) < 2.0,
            description: 'An answered query must resolve the position, found: '
               . json_encode($whole)
         );

         // # …and a reply that arrives split across reads still parses — the old
         //   single fread() returned on the first fragment and misparsed to 0,0
         $command[4] = "{$dir}/split.json";
         $split = $drive($command, ["\e[12;", "34R"], "{$dir}/split.json");
         $observed = $split['observed'] ?? null;

         yield assert(
            assertion: ($observed['position']['row'] ?? null) === 12
               && ($observed['position']['column'] ?? null) === 34,
            description: 'A split reply must still parse, found: ' . json_encode($split)
         );
      }
      finally {
         foreach (['silent.sh', 'silent.json', 'whole.json', 'split.json', 'before.txt', 'after.txt'] as $file) {
            @unlink("{$dir}/{$file}");
         }

         @rmdir($dir);
      }
   }
);
