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
use function explode;
use function fclose;
use function fwrite;
use function is_resource;
use function json_decode;
use function json_encode;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function trim;

use Bootgly\ACI\Tests\Suite\Test;


/**
 * An MCP client launches the server as a child process and completes
 * initialize → notifications/initialized → tools/list → tools/call over its
 * real stdin/stdout: one answer line per request, none for the
 * notification; what the tool printed lands on stderr, never on stdout; the
 * context reaches the tool; closing stdin ends the process with status 0.
 */
return new Test(
   description: 'CLI\MCP: a child process serves a full MCP session over stdio',
   test: function () {
      // !
      $Process = proc_open(
         [PHP_BINARY, __DIR__ . '/stdio.php'],
         [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
         $pipes,
         null,
         ['MCP_STDIO_USER' => 'alice']
      );
      yield assert(
         assertion: is_resource($Process),
         description: 'the server process starts'
      );

      // @ The whole session, then end of input
      $messages = [
         '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"spec","version":"1"}}}',
         '{"jsonrpc":"2.0","method":"notifications/initialized"}',
         '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
         '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo","arguments":{"n":1}}}'
      ];
      foreach ($messages as $message) {
         fwrite($pipes[0], "{$message}\n");
      }
      fclose($pipes[0]);
      $stdout = (string) stream_get_contents($pipes[1]);
      $stderr = (string) stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $status = proc_close($Process);

      // !
      $lines = explode("\n", trim($stdout));
      $answers = [];
      foreach ($lines as $line) {
         $answers[] = json_decode($line, true);
      }

      yield assert(
         assertion: $status === 0,
         description: "end of stdin ends the process cleanly (status {$status})"
      );
      yield assert(
         assertion: count($answers) === 3
            && ($answers[0]['result']['protocolVersion'] ?? null) === '2025-06-18'
            && ($answers[1]['result']['tools'][0]['name'] ?? null) === 'echo'
            && ($answers[2]['id'] ?? null) === 3,
         description: 'stdout carries exactly one JSON-RPC answer line per request, none for the notification: ' . json_encode($stdout)
      );
      $text = json_decode((string) ($answers[2]['result']['content'][0]['text'] ?? ''), true);
      yield assert(
         assertion: $text === ['arguments' => ['n' => 1], 'context' => 'alice'],
         description: 'the tool receives its arguments and the transport context'
      );
      yield assert(
         assertion: $stderr === "a stray echo from the tool\n",
         description: 'what the tool printed lands on stderr, not stdout: ' . json_encode($stderr)
      );
   }
);
