<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_VERSION;
use const PHP_BINARY;
use function array_diff;
use function assert;
use function bin2hex;
use function count;
use function explode;
use function fclose;
use function file_get_contents;
use function getenv;
use function getmypid;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function json_decode;
use function json_encode;
use function mkdir;
use function proc_close;
use function proc_open;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_contains;
use function stream_get_contents;
use function sys_get_temp_dir;
use function trim;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;


/**
 * The swap is the last thing the process does with the kit's files: once
 * the kit's HEAD is the release, nothing may autoload — the files on disk
 * are another framework's. Proven in a fresh process, where only what the
 * run really needs is resident, with the version footer running after the
 * command as it does for every `bootgly` invocation.
 */

return new Test(
   description: 'no class is autoloaded after the kit\'s files are swapped — closing lines and version footer included',
   test: function () {
      $base = sys_get_temp_dir() . '/bootgly-kit-swap-' . getmypid() . '-' . bin2hex(random_bytes(4));
      mkdir($base, 0775, true);
      $erase = function (string $target) use (&$erase): void {
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);

            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
            $erase("{$target}/{$entry}");
         }
         rmdir($target);
      };

      try {
         // ! A human environment: the footer renders only for people
         $environment = getenv();
         foreach ([
            'AI_AGENT', 'AMP_CURRENT_THREAD_ID', 'ANTIGRAVITY_AGENT', 'AUGMENT_AGENT', 'CLAUDECODE', 'CLAUDE_CODE',
            'CODEX_SANDBOX', 'CODEX_THREAD_ID', 'COPILOT_CLI', 'CURSOR_AGENT', 'GEMINI_CLI', 'OPENCODE',
            'OPENCODE_CLIENT', 'REPL_ID', 'BOOTGLY_AGENT_STDOUT_REDIRECTED',
         ] as $variable) {
            unset($environment[$variable]);
         }
         $environment['KIT_PROBE_ROOT'] = BOOTGLY_ROOT_DIR;

         $spawn = static function (string $mode) use ($environment, $base): array {
            $where = "{$base}/{$mode}";
            mkdir($where, 0775, true);
            $environment['KIT_PROBE_BASE'] = $where;
            $environment['KIT_PROBE_MODE'] = $mode;

            $pipes = [];
            $process = proc_open(
               [PHP_BINARY, '-d', 'opcache.jit=0', '-r', 'require $argv[1];', '--', __DIR__ . '/fixtures/kit_swap_probe.php'],
               [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
               $pipes,
               $where,
               $environment
            );
            if (is_resource($process) === false) {
               return [null, 'proc_open failed'];
            }
            stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            // ! Written by the probe's shutdown function — destructors included
            $report = is_file("{$where}/report.json") ? json_decode((string) file_get_contents("{$where}/report.json"), true) : null;

            return [is_array($report) ? $report : null, $errors];
         };

         // # Human mode: plan lines render before the swap, the footer after it
         [$report, $errors] = $spawn('human');

         yield assert(
            assertion: $report !== null && $report['result'] === true && $report['moved'] === true,
            description: 'the fresh process moved the kit and reported it' . ($errors !== '' ? " — stderr: {$errors}" : '')
         );
         yield assert(
            assertion: $report !== null && $report['after'] === [] && $report['loads'] > 0,
            description: 'not one class was autoloaded after the swap (the recorder saw the run\'s loads) — loaded after: ' . json_encode($report['after'] ?? null)
         );
         yield assert(
            assertion: $report !== null && str_contains($report['output'], 'The kit is on')
               && str_contains($report['output'], 'Bootgly') && str_contains($report['output'], 'PHP') && str_contains($report['output'], BOOTGLY_VERSION),
            description: 'the closing line and the version footer both rendered from resident code'
         );

         // # JSON mode: nothing renders before the swap and nothing after it either (the
         //   footer is skipped) — the document is the only write, from resident code
         [$report, $errors] = $spawn('json');
         $document = $report === null ? null : json_decode(trim($report['output']), true);

         yield assert(
            assertion: $report !== null && $report['result'] === true && $report['moved'] === true && $report['after'] === []
               && is_array($document) && $document['status'] === 'moved' && count(explode("\n", trim($report['output']))) === 1,
            description: 'under --json the run is one document, and still nothing autoloads after the swap — loaded after: ' . json_encode($report['after'] ?? null) . ($errors !== '' ? " — stderr: {$errors}" : '')
         );
      }
      finally {
         $erase($base);
      }
   }
);
