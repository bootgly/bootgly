<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
   $agentEnvVars = [
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
      'OPENCODE_CLIENT',
      'OPENCODE',
      'REPL_ID',
   ];
   $agentDetected = false;
   foreach ($agentEnvVars as $var) {
      if (getenv($var) !== false) {
         $agentDetected = true;
         break;
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
      $self = [PHP_BINARY, $entry];
      foreach (array_slice($arguments, 1) as $arg) {
         $self[] = $arg;
      }

      $proc = proc_open($self, $descriptors, $pipes, null, $env);
      if (is_resource($proc)) {
         $buffer = '';
         while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk === false || $chunk === '')
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
