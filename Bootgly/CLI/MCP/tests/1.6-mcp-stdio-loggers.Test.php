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
use function assert;
use function count;
use function fclose;
use function file_get_contents;
use function fopen;
use function fwrite;
use function is_file;
use function json_encode;
use function proc_close;
use function proc_open;
use function rewind;
use function rmdir;
use function str_repeat;
use function stream_get_contents;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use Throwable;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Handlers\Memory;
use Bootgly\ACI\Logs\Handlers\Stream;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC;
use Bootgly\CLI\MCP;


/**
 * Loggers never reach the protocol: a fresh logger with its default stdout
 * handler, created inside a call as kit clients do, writes its record to
 * stderr — with or without a trailing line break — and stdout keeps only
 * the answer lines. A call that turns the display back on, or clears the
 * live tap, leaves neither for the next one. A logger's `File` handler keeps writing while serving. The
 * display mask and the live tap in place before `serving()` are restored at
 * end of input, when stdout is closed and when the loop throws.
 */
return new Test(
   description: 'CLI\MCP: loggers write to stderr while serving, never to stdout',
   test: function () {
      // !
      $call = '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo","arguments":{}}}';
      $answer = '{"jsonrpc":"2.0","id":3,"result":{"content":[{"type":"text","text":"{\"arguments\":[],\"context\":null}"}],"isError":false}}' . "\n";
      $fixture = __DIR__ . '/stdio.php';
      $run = static function (array $environment) use ($fixture, $call): array {
         $Process = proc_open(
            [PHP_BINARY, $fixture],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment
         );
         fwrite($pipes[0], "{$call}\n{$call}\n");
         fclose($pipes[0]);
         $stdout = (string) stream_get_contents($pipes[1]);
         $stderr = (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);

         return [proc_close($Process), $stdout, $stderr];
      };

      // @@ A record with a line break, one without, a display turned back on and a tap cleared
      foreach (['break' => 'Unable to connect!', 'inline' => 'Starting Client... ', 'flip' => 'Unable to connect!', 'untap' => 'Unable to connect!'] as $mode => $message) {
         [$status, $stdout, $stderr] = $run(['MCP_STDIO_LOG' => $mode]);
         $shown = json_encode([$stdout, $stderr]);
         yield assert(
            assertion: $status === 0 && $stdout === str_repeat($answer, 2),
            description: "stdout keeps only the answer lines ({$mode}, status {$status}): {$shown}"
         );
         yield assert(
            assertion: substr_count($stderr, "Kit.") === 2 && substr_count($stderr, $message) === 2,
            description: "every record lands on stderr ({$mode}): {$shown}"
         );
      }

      // @ A File handler on the logger keeps writing while serving
      $directory = sys_get_temp_dir() . '/bootgly-mcp-log-' . uniqid();
      $path = "{$directory}/app.log";
      [$status, $stdout, $stderr] = $run(['MCP_STDIO_LOG' => 'break', 'MCP_STDIO_FILE' => $path]);
      $written = is_file($path) ? (string) file_get_contents($path) : '';
      @unlink($path);
      @rmdir($directory);
      yield assert(
         assertion: $stdout === str_repeat($answer, 2) && substr_count($written, 'Unable to connect!') === 2,
         description: 'a File handler records every call while serving: ' . json_encode([$stdout, $written])
      );

      // # Restored when serving() returns (in process)
      $saved = Display::$segments;
      $savedTap = Logger::$Tap;
      $Sentinel = new Memory;
      $mask = Display::MESSAGE | Display::TIMESTAMP;
      $seen = [];
      $Server = new RPC\MCP(
         name: 'Stdio',
         version: '1',
         Lister: static fn (mixed $context): array => [],
         Caller: static function (string $name, array $arguments, mixed $context) use (&$seen): array {
            $seen[] = [Display::$segments, Logger::$Tap];
            // ? Pull the input from under the loop: the next read throws
            if ($name === 'throw') {
               fclose($context);
            }

            return ['content' => []];
         }
      );
      $tool = static fn (string $name): string => '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . $name . '"}}' . "\n";
      $restored = static fn (): bool => Display::$segments === $mask && Logger::$Tap === $Sentinel;

      try {
         // @ End of input
         Display::show($mask);
         Logger::$Tap = $Sentinel;
         $Input = fopen('php://memory', 'w+');
         fwrite($Input, $tool('t'));
         rewind($Input);
         $Output = fopen('php://memory', 'w+');
         $Error = fopen('php://memory', 'w+');
         $status = (new MCP($Server, input: $Input, output: $Output, error: $Error))->serving();
         yield assert(
            assertion: $status === 0
               && count($seen) === 1
               && $seen[0][0] === Display::NONE
               && $seen[0][1] instanceof Stream
               && $seen[0][1] !== $Sentinel,
            description: 'while serving, the display is off and the tap is the diagnostic stream'
         );
         yield assert(
            assertion: $restored(),
            description: 'the display mask and the tap are restored at end of input'
         );

         // @ Stdout closed
         $Input = fopen('php://memory', 'w+');
         fwrite($Input, $tool('t'));
         rewind($Input);
         $Closed = fopen('php://memory', 'r');
         $status = (new MCP($Server, input: $Input, output: $Closed, error: $Error))->serving();
         yield assert(
            assertion: $status === 1 && $restored(),
            description: "the display mask and the tap are restored when stdout is closed (status {$status})"
         );

         // @ The loop throws
         $Input = fopen('php://memory', 'w+');
         fwrite($Input, $tool('throw'));
         rewind($Input);
         $thrown = false;
         try {
            (new MCP($Server, context: $Input, input: $Input, output: $Output, error: $Error))->serving();
         }
         catch (Throwable) {
            $thrown = true;
         }
         yield assert(
            assertion: $thrown && $restored(),
            description: 'the display mask and the tap are restored when the loop throws'
         );
      }
      finally {
         Display::$segments = $saved;
         Logger::$Tap = $savedTap;
      }
   }
);
