<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\MCP;


use const JSON_UNESCAPED_SLASHES;
use function explode;
use function fclose;
use function fread;
use function fwrite;
use function hexdec;
use function is_array;
use function json_decode;
use function json_encode;
use function microtime;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_client;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use RuntimeException;


/**
 * Raw HTTP/1.x client for the MCP Streamable HTTP specs (HTTP/1.1 unless
 * told otherwise).
 *
 * Every exchange runs on a fresh `Connection: close` connection, so the
 * kernel spreads the requests of one session across the server's workers.
 * Every request carries the harness dispatch header `X-Bootgly-Test`.
 */
final class Client
{
   /**
    * One request on a fresh connection: the answer read until the server
    * closes it or `$timeout` elapses (an open event stream).
    *
    * @param array<string,string> $headers
    *
    * @return array{code:int,headers:array<string,string>,body:string,closed:bool}
    */
   public static function exchange (
      string $hostPort,
      int $test,
      string $method,
      array $headers = [],
      string $body = '',
      float $timeout = 3.0,
      string $protocol = 'HTTP/1.1'
   ): array
   {
      // !
      $Socket = @stream_socket_client("tcp://{$hostPort}", $code, $message, 5);
      if ($Socket === false) {
         throw new RuntimeException("MCP spec client connect failed: {$code} {$message}");
      }
      $request = "{$method} /mcp {$protocol}\r\nHost: localhost\r\nX-Bootgly-Test: {$test}\r\nConnection: close\r\n";
      foreach ($headers as $name => $value) {
         $request .= "{$name}: {$value}\r\n";
      }
      if ($body !== '' || $method === 'POST') {
         $length = strlen($body);
         $request .= "Content-Length: {$length}\r\n";
      }
      $request .= "\r\n{$body}";

      // @ Write, then read until the peer closes or the deadline passes
      @fwrite($Socket, $request);
      stream_set_blocking($Socket, false);
      $wire = '';
      $closed = false;
      $deadline = microtime(true) + $timeout;
      // @@
      while (microtime(true) < $deadline) {
         $read = [$Socket];
         $write = null;
         $except = null;
         if (@stream_select($read, $write, $except, 0, 50_000) !== 1) {
            continue;
         }
         $chunk = @fread($Socket, 65536);
         if ($chunk === false || $chunk === '') {
            $closed = true;
            break;
         }
         $wire .= $chunk;
         // ? A complete answer ends the read (an open stream runs to the deadline)
         if (self::check($wire)) {
            break;
         }
      }
      @fclose($Socket);

      // ! Head and body
      $separator = strpos($wire, "\r\n\r\n");
      $head = $separator === false ? $wire : substr($wire, 0, $separator);
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $lines = explode("\r\n", $head);
      $matches = [];
      $code = preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $lines[0], $matches) === 1 ? (int) $matches[1] : 0;
      $fields = [];
      foreach ($lines as $index => $line) {
         if ($index === 0 || str_contains($line, ':') === false) {
            continue;
         }
         [$name, $value] = explode(':', $line, 2);
         $fields[strtolower(trim($name))] = trim($value);
      }
      if (($fields['transfer-encoding'] ?? '') === 'chunked') {
         $body = self::decode($body);
      }

      // :
      return ['code' => $code, 'headers' => $fields, 'body' => $body, 'closed' => $closed];
   }

   /**
    * One JSON-RPC message posted to the endpoint; `json` is the decoded
    * JSON body (or the `data` of the first `message` event of a stream).
    *
    * @param array<string,mixed> $message
    * @param array<string,string> $headers
    *
    * @return array{code:int,headers:array<string,string>,body:string,closed:bool,json:mixed}
    */
   public static function call (string $hostPort, int $test, array $message, array $headers = [], string $protocol = 'HTTP/1.1'): array
   {
      // !
      $headers += [
         'Content-Type' => 'application/json',
         'Accept' => 'application/json, text/event-stream'
      ];

      // @
      $Answer = self::exchange(
         $hostPort,
         $test,
         'POST',
         $headers,
         (string) json_encode($message, JSON_UNESCAPED_SLASHES),
         protocol: $protocol
      );
      $body = $Answer['body'];
      $matches = [];
      if (
         str_contains($Answer['headers']['content-type'] ?? '', 'text/event-stream')
         && preg_match('/^data: (.*)$/m', $body, $matches) === 1
      ) {
         $body = $matches[1];
      }
      $json = json_decode($body, true);

      // :
      return $Answer + ['json' => is_array($json) ? $json : null];
   }

   /**
    * Whether the wire holds one complete answer: its Content-Length body,
    * or a chunked body up to the terminal chunk.
    */
   public static function check (string $wire): bool
   {
      // !
      $separator = strpos($wire, "\r\n\r\n");

      // ? Head still incomplete
      if ($separator === false) {
         return false;
      }

      $head = substr($wire, 0, $separator);
      $body = substr($wire, $separator + 4);
      $matches = [];
      // ?:
      if (preg_match('/\r\nContent-Length:[ \t]*(\d+)/i', $head, $matches) === 1) {
         return strlen($body) >= (int) $matches[1];
      }

      // :
      return preg_match('/\r\nTransfer-Encoding:[ \t]*chunked/i', $head) === 1
         && (str_starts_with($body, "0\r\n\r\n") || str_contains($body, "\r\n0\r\n\r\n"));
   }

   /**
    * Decode a chunked body (an unterminated one up to its last whole chunk).
    */
   public static function decode (string $chunked): string
   {
      // !
      $body = '';

      // @@
      while (($line = strpos($chunked, "\r\n")) !== false) {
         $size = (int) hexdec(trim(substr($chunked, 0, $line)));
         if ($size === 0 || strlen($chunked) < $line + 2 + $size) {
            break;
         }
         $body .= substr($chunked, $line + 2, $size);
         $chunked = substr($chunked, $line + 2 + $size + 2);
      }

      // :
      return $body;
   }
}
