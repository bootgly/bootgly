<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Observability\Metrics\Counter;

// ! The agent-output wrapper launches the real test runner as a child. Give
// that child its own POSIX session before any process-control suite boots, so
// a test-owned daemon's group signal cannot terminate the outer JSON collector.
if (
   getenv('BOOTGLY_AGENT_STDOUT_REDIRECTED') === '1'
   && function_exists('posix_setsid')
) {
   @posix_setsid();
}

// @ Agent-mode stdout redirection for `bootgly test`
// When an AI agent drives `bootgly test`, the consumer expects a single JSON
// document on stdout — nothing else. We can't reliably silence every
// fwrite(STDOUT) performed by CLI destructors or by child processes spawned
// by E2E tests, so we reopen fd 1 onto a pipe at the process level before
// the PHP app boots. The parent process drains the pipe and emits only the
// last valid JSON document (the one produced by Results::toJSON()).
// This file is included from inside Bootgly::autoboot(), where $argv is not
// in scope — CLI arguments come from the $_SERVER superglobal instead.
// Help requests (--help/-h) print raw text for the caller, so they bypass
// the redirection — like the `benchmark` subcommand already does.
$arguments = (array) ($_SERVER['argv'] ?? []);
if (
   PHP_SAPI === 'cli'
   && ($arguments[1] ?? null) === 'test'
   && ($arguments[2] ?? null) !== 'benchmark'
   && in_array('--help', $arguments, true) === false
   && in_array('-h', $arguments, true) === false
   && getenv('BOOTGLY_AGENT_STDOUT_REDIRECTED') !== '1'
) {
   // ! This list mirrors Bootgly\API\Environment\Agent::detect(), which the
   // child uses to decide whether to emit the JSON document. It is duplicated
   // rather than shared because ACI must not depend on API — but the two
   // PREDICATES have to agree, or the wrapper hijacks stdout for a child that
   // never produces a document. `AI_AGENT` therefore needs a non-empty trimmed
   // value (`AI_AGENT=` blanks a variable for one command and means "no"),
   // while the vendor markers count as present whatever they hold.
   $agentDetected = false;
   $aiAgent = getenv('AI_AGENT');
   if (is_string($aiAgent) && trim($aiAgent) !== '') {
      $agentDetected = true;
   }
   if (!$agentDetected) {
      $agentEnvVars = [
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
         'OPENCODE_CLIENT',
         'OPENCODE',
         'REPL_ID',
      ];
      foreach ($agentEnvVars as $var) {
         if (getenv($var) !== false) {
            $agentDetected = true;
            break;
         }
      }
   }
   if (!$agentDetected && file_exists('/opt/.devin')) {
      $agentDetected = true;
   }

   // ? Re-invoke the active CLI entry script — run standalone, this bootstrap
   //   fragment is a no-op, so the child must boot the real `bootgly` entry.
   $entry = $_SERVER['SCRIPT_FILENAME'] ?? ($arguments[0] ?? '');
   $entry = is_string($entry) && $entry !== '' ? realpath($entry) : false;

   if ($agentDetected && $entry !== false && is_file($entry) && function_exists('proc_open')) {
      $descriptors = [
         0 => STDIN,
         1 => ['pipe', 'w'],
         2 => STDERR,
      ];
      $env = getenv();
      $env['BOOTGLY_AGENT_STDOUT_REDIRECTED'] = '1';

      // ! Interpreter options — the CLI SAPI consumes `-d`, `-n` and `-c`
      // before $_SERVER['argv'] exists, so re-invoking from argv alone DROPPED
      // them and handed the child a different runtime than the caller asked
      // for. `php -d opcache.enable_cli=0 bootgly test <n> --coverage-driver=native`
      // is the canonical victim: the child lost the flag and died on the
      // driver's own precondition. Recover them from the real command line
      // where the platform exposes it; elsewhere this is a no-op.
      $options = [];
      $cmdline = is_file('/proc/self/cmdline') ? @file_get_contents('/proc/self/cmdline') : false;
      if (is_string($cmdline) && $cmdline !== '') {
         // ! Everything between the binary and the script is an interpreter
         //   option. Forward the ones that change the runtime the child boots
         //   into; skip the rest without forwarding (an option we do not model
         //   must never be guessed at), and stop at the first token that is not
         //   an option at all — that is the script.
         $valued = ['-d', '--define', '-c', '--php-ini'];
         $flags = ['-n', '--no-php-ini', '-C', '-e'];

         $parts = explode("\0", rtrim($cmdline, "\0"));
         $count = count($parts);
         for ($i = 1; $i < $count; $i++) {
            $part = $parts[$i];

            // : The script — the options end here
            if ($part === '' || $part[0] !== '-') {
               break;
            }

            // ? Value in the next element: `-d k=v`, `--define k=v`, `-c path`
            if (in_array($part, $valued, true)) {
               if (isset($parts[$i + 1])) {
                  $options[] = $part;
                  $options[] = $parts[$i + 1];
                  $i++;
               }
               continue;
            }
            // ? Standalone
            if (in_array($part, $flags, true)) {
               $options[] = $part;
               continue;
            }
            // ? `-n` clustered ahead of a value-taking short option: `-nd k=v`
            //   and `-ndk=v` both mean `-n` plus `-d …`
            if (str_starts_with($part, '-nd')) {
               $options[] = '-n';
               $rest = substr($part, 3);
               if ($rest === '') {
                  if (isset($parts[$i + 1])) {
                     $options[] = '-d';
                     $options[] = $parts[$i + 1];
                     $i++;
                  }
                  continue;
               }
               $options[] = "-d{$rest}";
               continue;
            }
            // ? Value attached: `-dk=v`, `-cpath`, `--define=k=v`
            if (
               str_starts_with($part, '-d')
               || str_starts_with($part, '-c')
               || str_starts_with($part, '--define')
               || str_starts_with($part, '--php-ini')
            ) {
               $options[] = $part;
               continue;
            }

            // ? An option we do not model (`-r`, `-f`, `-a`, `--`) — never
            //   guessed at, and never a reason to stop looking for the ones we do
         }
      }

      $self = [PHP_BINARY, ...$options, $entry];
      foreach (array_slice($arguments, 1) as $arg) {
         $self[] = $arg;
      }

      $proc = proc_open($self, $descriptors, $pipes, null, $env);
      if (is_resource($proc)) {
         // ! A blocking read is what makes this loop terminate on EOF and only
         // on EOF. proc_open() hands back blocking pipes, but say so rather
         // than depend on it: on a non-blocking stream `fread()` returns ''
         // long before EOF, and letting '' end the loop would drop everything
         // the child had not written yet — the JSON document included, since
         // it is written last.
         stream_set_blocking($pipes[1], true);

         $buffer = '';
         while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk === false)
               break;
            $buffer .= $chunk;
         }
         fclose($pipes[1]);
         $exit = proc_close($proc);

         // Extract the last valid JSON document from the captured output.
         // Results::toJSON() emits a single-line object ending with PHP_EOL.
         // Other writes (ANSI cursor escapes, child process banners) may be
         // interleaved, so we strip ANSI and scan backwards from every `{` for
         // a substring that parses as JSON.
         $json = '';
         if ($buffer !== '') {
            $clean = preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $buffer) ?? $buffer;
            $len = strlen($clean);
            for ($i = $len - 1; $i >= 0; $i--) {
               if ($clean[$i] !== '{')
                  continue;
               $candidate = trim(substr($clean, $i));
               if ($candidate === '' || $candidate[0] !== '{')
                  continue;
               $decoded = json_decode($candidate, true);
               if (is_array($decoded) && isset($decoded['result'])) {
                  $json = $candidate;
                  break;
               }
            }
         }
         // ? No JSON document — the child died before Results::toJSON(), or it
         //   never ran a suite at all. stdout belongs to the document and must
         //   stay parseable (empty is a valid "no document"), but emitting a
         //   lone PHP_EOL also DISCARDED every byte of diagnostic the child had
         //   written. Hand that output to STDERR, where a human and a CI log
         //   look, and say why the document is missing.
         if ($json === '') {
            fwrite(STDERR, 'Bootgly test: no JSON results document in the child output.' . PHP_EOL);

            // ? Bounded on purpose. A caller may hand this process a stderr pipe
            //   it does not drain (Bootgly's own subprocess test helper does),
            //   and an unbounded dump then blocks past the 64 KiB pipe buffer —
            //   wedging the run it was only supposed to explain. Head and tail
            //   carry the banner and the failure; the middle is never the part
            //   you need.
            $limit = 4096;
            if (strlen($buffer) > $limit * 2) {
               $elided = strlen($buffer) - $limit * 2;
               $buffer = substr($buffer, 0, $limit)
                  . PHP_EOL . "… {$elided} bytes elided …" . PHP_EOL
                  . substr($buffer, -$limit);
            }
            if ($buffer !== '') {
               fwrite(STDERR, $buffer);
            }

            // : A run that owed a document and produced none has failed, even
            //   when the process it was hijacked by reported success.
            exit($exit === 0 ? 1 : $exit);
         }

         fwrite(STDOUT, $json . PHP_EOL);
         exit($exit);
      }
   }
}
unset($arguments, $entry);

// @ Debugging reporters — ACI Observability hook
// Skipped when this file is executed standalone (no autoloader registered).
if (defined('BOOTGLY_VERSION') === true) {
   Throwables::$reporters[] = static function (Throwable $Throwable, array $context): void {
      // ? No registry configured — zero cost
      $Observability = Observability::$Instance;
      if ($Observability === null) {
         return;
      }

      static $Counter = null;
      if ($Counter === null) {
         $Counter = new Counter(
            name: 'exceptions_total',
            help: 'Throwables reported by the Bootgly exception handler'
         );
         $Observability->Metrics->push($Counter);
      }

      $Counter->increment();
   };
}
