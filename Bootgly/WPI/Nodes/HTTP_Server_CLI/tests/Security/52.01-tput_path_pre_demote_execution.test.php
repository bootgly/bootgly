<?php

use const BOOTGLY_ROOT_DIR;
use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;
use const PATH_SEPARATOR;
use function bin2hex;
use function chmod;
use function count;
use function explode;
use function fclose;
use function file;
use function file_put_contents;
use function function_exists;
use function getenv;
use function getmypid;
use function in_array;
use function is_array;
use function is_int;
use function is_resource;
use function json_encode;
use function microtime;
use function mkdir;
use function posix_geteuid;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function random_bytes;
use function rmdir;
use function sort;
use function str_contains;
use function strlen;
use function stream_get_contents;
use function stream_set_blocking;
use function sys_get_temp_dir;
use function unlink;
use function usleep;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC L3 — Bootgly CLI startup must not execute a PATH-selected
 * terminal helper before a server can drop its launch privileges.
 *
 * A nested real `bootgly test --help` process receives invalid COLUMNS/LINES
 * values and a test-owned directory at the front of PATH. Its harmless fake
 * `tput` records process lineage, effective UID and arguments, then returns
 * numeric dimensions so normal CLI bootstrap and command dispatch continue.
 * The child exit/help output and this case's HTTP handler are independent
 * controls against mistaking a broken child or suite fixture for execution.
 *
 * The probe records the actual launch EUID. An ordinary-UID run proves the
 * PATH-execution primitive; an isolated root container can additionally prove
 * that the same bootstrap sink executes before privilege demotion as EUID 0.
 */
$probe = [
   'error' => '',
   'started' => false,
   'timed_out' => false,
   'exit_code' => null,
   'child_pid' => 0,
   'launch_euid' => null,
   'help_rendered' => false,
   'calls' => [],
   'malformed' => false,
];

return new Specification(
   description: 'CLI startup must not execute a PATH-selected tput before privilege demotion',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $directory = sys_get_temp_dir()
         . '/bootgly-security-l3-' . getmypid() . '-' . bin2hex(random_bytes(6));
      $fixturePath = $directory . '/tput';
      $evidencePath = $directory . '/evidence.log';
      $process = null;
      $pipes = [];

      try {
         if (
            function_exists('proc_open') === false
            || function_exists('posix_geteuid') === false
         ) {
            throw new RuntimeException('L3 requires proc_open and POSIX effective-UID support.');
         }
         if (! mkdir($directory, 0700)) {
            throw new RuntimeException('L3 could not create its isolated PATH directory.');
         }

         $fixture = <<<'SH'
#!/bin/sh
parent="$PPID"
grandparent=""
if [ -r "/proc/$parent/status" ]; then
   grandparent=$(/usr/bin/awk '/^PPid:/ { print $2; exit }' "/proc/$parent/status")
fi
uid=$(/usr/bin/id -u)
printf '%s|%s|%s|%s\n' "$parent" "$grandparent" "$uid" "$1" >> "$BOOTGLY_L3_EVIDENCE"
case "$1" in
   cols) printf '137\n' ;;
   lines) printf '53\n' ;;
   *) printf '0\n' ;;
esac
SH;
         if (
            file_put_contents($fixturePath, $fixture) !== strlen($fixture)
            || ! chmod($fixturePath, 0700)
         ) {
            throw new RuntimeException('L3 could not install its harmless tput fixture.');
         }

         $environment = getenv();
         if (! is_array($environment)) {
            throw new RuntimeException('L3 could not capture the child environment.');
         }
         foreach ([
            'AI_AGENT',
            'AMP_CURRENT_THREAD_ID',
            'ANTIGRAVITY_AGENT',
            'AUGMENT_AGENT',
            'CLAUDECODE',
            'CLAUDE_CODE',
            'CODEX_SANDBOX',
            'CODEX_THREAD_ID',
            'COPILOT_CLI',
            'CURSOR_AGENT',
            'GEMINI_CLI',
            'OPENCODE',
            'OPENCODE_CLIENT',
            'REPL_ID',
            'BOOTGLY_AGENT_STDOUT_REDIRECTED',
         ] as $variable) {
            unset($environment[$variable]);
         }
         $environment['PATH'] = $directory . PATH_SEPARATOR
            . '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
         $environment['COLUMNS'] = 'not-a-number';
         $environment['LINES'] = 'not-a-number';
         $environment['TERM'] = 'xterm';
         $environment['BOOTGLY_L3_EVIDENCE'] = $evidencePath;
         $environment['BOOTGLY_TEST_HELP_PROBE'] = '1';

         $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];
         $process = proc_open(
            [PHP_BINARY, BOOTGLY_ROOT_DIR . 'bootgly', 'test', '--help'],
            $descriptors,
            $pipes,
            BOOTGLY_ROOT_DIR,
            $environment,
         );
         if (! is_resource($process)) {
            throw new RuntimeException('L3 could not start the nested Bootgly CLI.');
         }
         $probe['started'] = true;
         $probe['launch_euid'] = posix_geteuid();

         $status = proc_get_status($process);
         $probe['child_pid'] = (int) ($status['pid'] ?? 0);
         stream_set_blocking($pipes[1], false);
         stream_set_blocking($pipes[2], false);
         $output = '';
         $error = '';
         $deadline = microtime(true) + 10.0;

         do {
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false) {
               $output .= $chunk;
            }
            $chunk = stream_get_contents($pipes[2]);
            if ($chunk !== false) {
               $error .= $chunk;
            }

            $status = proc_get_status($process);
            if (($status['running'] ?? false) === false) {
               break;
            }
            usleep(10000);
         }
         while (microtime(true) < $deadline);

         if (($status['running'] ?? false) === true) {
            $probe['timed_out'] = true;
            proc_terminate($process);
            usleep(100000);
            $status = proc_get_status($process);
         }

         foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($pipes[$index]);
            if ($chunk !== false) {
               if ($index === 1) {
                  $output .= $chunk;
               }
               else {
                  $error .= $chunk;
               }
            }
            fclose($pipes[$index]);
            unset($pipes[$index]);
         }

         $exitCode = (int) ($status['exitcode'] ?? -1);
         $closed = proc_close($process);
         $process = null;
         $probe['exit_code'] = $exitCode >= 0 ? $exitCode : $closed;
         $probe['help_rendered'] = str_contains("{$output}{$error}", 'Test usage');

         $lines = file($evidencePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
         if ($lines === false) {
            $lines = [];
         }
         foreach ($lines as $line) {
            $parts = explode('|', $line);
            if (count($parts) !== 4) {
               $probe['malformed'] = true;
               continue;
            }

            [$ParentPID, $GrandparentPID, $EUID, $argument] = $parts;
            $probe['calls'][] = [
               'parent_pid' => (int) $ParentPID,
               'grandparent_pid' => (int) $GrandparentPID,
               'euid' => (int) $EUID,
               'argument' => $argument,
            ];
         }
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
               fclose($pipe);
            }
         }
         if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
         }
         @unlink($fixturePath);
         @unlink($evidencePath);
         @rmdir($directory);
      }

      return "GET /l3/tput-path HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response): Response {
      return $Response(body: 'L3 handler control');
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (
         ! str_contains($response, 'HTTP/1.1 200 OK')
         || ! str_contains($response, 'L3 handler control')
      ) {
         return 'L3 fixture did not traverse the selected native HTTP handler.';
      }
      if ($probe['error'] !== '') {
         return 'L3 fixture error: ' . $probe['error'];
      }
      if (
         $probe['started'] !== true
         || $probe['timed_out'] !== false
         || $probe['exit_code'] !== 0
         || $probe['help_rendered'] !== true
         || $probe['child_pid'] <= 0
         || ! is_int($probe['launch_euid'])
      ) {
         return 'L3 nested Bootgly CLI control did not complete normally: '
            . json_encode($probe);
      }
      if ($probe['malformed'] === true) {
         return 'L3 tput fixture emitted malformed process evidence.';
      }

      $arguments = [];
      foreach ($probe['calls'] as $call) {
         $lineageMatches = $call['parent_pid'] === $probe['child_pid']
            || $call['grandparent_pid'] === $probe['child_pid'];
         if (
            $lineageMatches
            && $call['euid'] === $probe['launch_euid']
            && in_array($call['argument'], ['cols', 'lines'], true)
         ) {
            $arguments[] = $call['argument'];
         }
      }
      sort($arguments);

      if ($arguments === ['cols', 'lines']) {
         return 'CONFIRMED L3: Bootgly CLI startup executed attacker-selected tput twice '
            . '(cols/lines) with launch EUID ' . $probe['launch_euid']
            . ' before command dispatch or server demotion.';
      }
      if ($probe['calls'] !== []) {
         return 'L3 PATH fixture executed, but its lineage/UID/argument controls did not match: '
            . json_encode($probe);
      }

      return true;
   },
);
