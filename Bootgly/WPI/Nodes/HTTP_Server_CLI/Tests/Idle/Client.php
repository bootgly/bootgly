<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle;


use const STREAM_CLIENT_CONNECT;
use function fclose;
use function fread;
use function fwrite;
use function is_resource;
use function microtime;
use function preg_match;
use function stream_context_create;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_client;
use function strlen;
use function strpos;
use function substr;
use RuntimeException;


/**
 * Raw HTTP/1.1 client for the idle-reaper specs.
 *
 * The harness driver reads every response with a 2 s cap and injects the
 * dispatch header only into the request string a spec closure returns, so a
 * multi-second park runs on a side socket opened by the spec itself. Every
 * request built here carries `X-Bootgly-Test` — without it the LAST installed
 * handler answers.
 */
final class Client
{
   /**
    * Open a keep-alive connection to the suite server.
    *
    * @return resource
    */
   public static function open (string $hostPort)
   {
      $Socket = stream_socket_client(
         "tcp://{$hostPort}",
         $code,
         $message,
         5,
         STREAM_CLIENT_CONNECT,
         stream_context_create(['socket' => ['tcp_nodelay' => true]])
      );
      if ($Socket === false) {
         throw new RuntimeException("Idle client connect failed: {$code} {$message}");
      }
      stream_set_blocking($Socket, false);

      return $Socket;
   }

   /**
    * Build one request carrying the harness dispatch header.
    *
    * @param array<string,string> $headers
    */
   public static function request (string $path, int $testIndex, array $headers = []): string
   {
      $request = "GET {$path} HTTP/1.1\r\nHost: localhost\r\nX-Bootgly-Test: {$testIndex}\r\n";
      foreach ($headers as $name => $value) {
         $request .= "{$name}: {$value}\r\n";
      }

      return $request . "\r\n";
   }

   /**
    * Write one request and read one complete response.
    *
    * `body` is the Content-Length-delimited body when the head carries one;
    * otherwise (chunked / event-stream) it is the RAW bytes that arrived with
    * the head, chunk framing included — the caller keeps reading the wire.
    *
    * @param resource $Socket
    *
    * @return array{code:int, head:string, body:string, elapsed:float, eof:bool}
    */
   public static function send ($Socket, string $request, float $timeout): array
   {
      $started = microtime(true);
      @fwrite($Socket, $request);

      // !
      $wire = '';
      $head = '';
      $body = '';
      $eof = false;
      $deadline = $started + $timeout;
      // @@
      while (microtime(true) < $deadline) {
         $read = [$Socket];
         $write = null;
         $except = null;
         $ready = @stream_select($read, $write, $except, 0, 50_000);
         if ($ready === 1) {
            $chunk = @fread($Socket, 65536);
            // ? The peer closed before (or while) answering
            if ($chunk === false || $chunk === '') {
               $eof = true;
               break;
            }
            $wire .= $chunk;
         }

         $separator = strpos($wire, "\r\n\r\n");
         if ($separator === false) {
            continue;
         }
         $head = substr($wire, 0, $separator);
         $body = substr($wire, $separator + 4);
         $matches = [];
         // ? No Content-Length (chunked / event-stream): stop at the head but
         //   keep every byte that arrived with it — the caller drains the rest
         if (preg_match('/\r\nContent-Length:[ \t]*(\d+)/i', $head, $matches) !== 1) {
            break;
         }
         $length = (int) $matches[1];
         if (strlen($body) >= $length) {
            $body = substr($body, 0, $length);
            break;
         }
      }

      $matches = [];
      $code = preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $head, $matches) === 1
         ? (int) $matches[1]
         : 0;

      // :
      return [
         'code' => $code,
         'head' => $head,
         'body' => $body,
         'elapsed' => microtime(true) - $started,
         'eof' => $eof
      ];
   }

   /**
    * Seconds until the peer closes the connection; null when it is still open.
    *
    * @param resource $Socket
    */
   public static function wait ($Socket, float $timeout): null|float
   {
      $started = microtime(true);
      $deadline = $started + $timeout;
      // @@
      while (microtime(true) < $deadline) {
         $read = [$Socket];
         $write = null;
         $except = null;
         $ready = @stream_select($read, $write, $except, 0, 50_000);
         if ($ready !== 1) {
            continue;
         }
         $chunk = @fread($Socket, 65536);
         if ($chunk === false || $chunk === '') {
            return microtime(true) - $started;
         }
      }

      return null;
   }

   /**
    * @param resource $Socket
    */
   public static function close ($Socket): void
   {
      if (is_resource($Socket)) {
         @fclose($Socket);
      }
   }
}
