<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */
// @ Agent-mode stdout redirection for `bootgly test`
// When an AI agent drives `bootgly test`, the consumer expects a single JSON
// document on stdout — nothing else. We can't reliably silence every
// fwrite(STDOUT) performed by CLI destructors or by child processes spawned
// by E2E tests, so we reopen fd 1 onto a pipe at the process level before
// the PHP app boots. The parent process drains the pipe and emits only the
// last valid JSON document (the one produced by Results::toJSON()).
if (
   PHP_SAPI === 'cli'
   && ($argv[1] ?? null) === 'test'
   && ($argv[2] ?? null) !== 'benchmark'
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

   if ($agentDetected && function_exists('proc_open')) {
      $descriptors = [
         0 => STDIN,
         1 => ['pipe', 'w'],
         2 => STDERR,
      ];
      $env = getenv();
      $env['BOOTGLY_AGENT_STDOUT_REDIRECTED'] = '1';
      $self = [PHP_BINARY, __FILE__];
      foreach (array_slice($argv, 1) as $arg) {
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
