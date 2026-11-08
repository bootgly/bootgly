<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


namespace Bootgly\CLI\MCP;


use const PHP_BINARY;
use function array_merge;
use function assert;
use function fclose;
use function fwrite;
use function json_encode;
use function proc_close;
use function proc_open;
use function str_contains;
use function str_repeat;
use function stream_get_contents;
use function strlen;

use Bootgly\ACI\Tests\Suite\Test;


/**
 * On the process's own stdout the output capture cannot be bypassed: a tool
 * that flushes, ends or cleans the output buffers — or ends the process
 * mid-call, or dies of memory exhaustion with `display_errors` on — still
 * prints to stderr only, and stdout keeps nothing but protocol lines. Errors
 * the process hides (`display_errors=0`) stay hidden, and a process where
 * `ini_set` is disabled still serves. A `\r\n` line of exactly `line` bytes (line ending
 * excluded) is served.
 */
return new Test(
   description: 'CLI\MCP: printed output cannot escape to stdout; CRLF at the bound',
   test: function () {
      // !
      $call = '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo","arguments":{}}}';
      $fixture = __DIR__ . '/stdio.php';
      $run = static function (array $environment, string $input, array $settings = ['display_errors=1']) use ($fixture): array {
         $command = [PHP_BINARY];
         foreach ($settings as $setting) {
            $command = array_merge($command, ['-d', $setting]);
         }
         $command[] = $fixture;
         $Process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment
         );
         fwrite($pipes[0], $input);
         fclose($pipes[0]);
         $stdout = (string) stream_get_contents($pipes[1]);
         $stderr = (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);

         return [proc_close($Process), $stdout, $stderr];
      };

      // @ Flushed, ended and cleaned buffers
      [$status, $stdout, $stderr] = $run(['MCP_STDIO_LEAK' => 'flush'], "{$call}\n");
      $shown = json_encode([$stdout, $stderr]);
      yield assert(
         assertion: $status === 0
            && $stdout === '{"jsonrpc":"2.0","id":3,"result":{"content":[{"type":"text","text":"{\"arguments\":[],\"context\":null}"}],"isError":false}}' . "\n",
         description: "stdout keeps only the answer line when a tool flushes or ends the buffers: {$shown}"
      );
      yield assert(
         assertion: str_contains($stderr, 'a stray echo from the tool')
            && str_contains($stderr, 'after ob_flush')
            && str_contains($stderr, 'after ob_end_flush')
            && str_contains($stderr, 'after ob_end_clean')
            && str_contains($stderr, 'after ob_get_clean'),
         description: "everything the tool printed lands on stderr: {$shown}"
      );

      // @ The process ends mid-call: the shutdown flush goes through the capture
      [$status, $stdout, $stderr] = $run(['MCP_STDIO_LEAK' => 'exit'], "{$call}\n");
      $shown = json_encode([$stdout, $stderr]);
      yield assert(
         assertion: $status === 3 && $stdout === '' && str_contains($stderr, 'before exit'),
         description: "output printed before the process ends lands on stderr (status {$status}): {$shown}"
      );

      // @ The process dies of memory exhaustion: the fatal error is displayed on stderr
      [$status, $stdout, $stderr] = $run(['MCP_STDIO_LEAK' => 'memory'], "{$call}\n");
      $shown = json_encode([$stdout, $stderr]);
      yield assert(
         assertion: $status === 255 && $stdout === '' && str_contains($stderr, 'Allowed memory size'),
         description: "a memory-exhaustion fatal error never reaches stdout (status {$status}): {$shown}"
      );

      // @ Hidden errors stay hidden
      [$status, $stdout, $stderr] = $run(['MCP_STDIO_LEAK' => 'memory'], "{$call}\n", ['display_errors=0', 'log_errors=0']);
      $shown = json_encode([$stdout, $stderr]);
      yield assert(
         assertion: $status === 255 && $stdout === '' && str_contains($stderr, 'Allowed memory size') === false,
         description: "with display_errors=0 the fatal error is displayed nowhere (status {$status}): {$shown}"
      );

      // @ A value PHP reads as off (neither a keyword nor a non-zero number)
      [$status, $stdout, $stderr] = $run(['MCP_STDIO_LEAK' => 'memory'], "{$call}\n", ['display_errors=foo', 'log_errors=0']);
      $shown = json_encode([$stdout, $stderr]);
      yield assert(
         assertion: $status === 255 && $stdout === '' && str_contains($stderr, 'Allowed memory size') === false,
         description: "display_errors=foo is off for PHP and stays off (status {$status}): {$shown}"
      );

      // @ ini_set disabled
      $ping = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
      [$status, $stdout] = $run([], "{$ping}\n", ['display_errors=1', 'disable_functions=ini_set']);
      $shown = json_encode($stdout);
      yield assert(
         assertion: $status === 0 && $stdout === '{"jsonrpc":"2.0","id":1,"result":{}}' . "\n",
         description: "a process with ini_set disabled still serves (status {$status}): {$shown}"
      );

      // @ CRLF at the bound
      [$status, $stdout] = $run(['MCP_STDIO_LINE' => (string) strlen($ping)], "{$ping}\r\n{$ping}\r\n");
      $shown = json_encode($stdout);
      yield assert(
         assertion: $status === 0 && $stdout === str_repeat('{"jsonrpc":"2.0","id":1,"result":{}}' . "\n", 2),
         description: "a CRLF line of exactly the bound is served: {$shown}"
      );
   }
);
