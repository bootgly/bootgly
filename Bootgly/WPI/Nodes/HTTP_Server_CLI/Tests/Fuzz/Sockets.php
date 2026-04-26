<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz;


use function fclose;
use function feof;
use function fread;
use function fwrite;
use function is_resource;
use function microtime;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;


/**
 * Side-socket round-trip helpers used by the fuzz specs.
 *
 * Each fuzz iteration must talk to the test server over its own socket so
 * the test driver's main connection stays untouched. The server's
 * "no-header fallback" path in `API\Workables\Server::boot()` reuses the
 * last-installed handler whenever an incoming request lacks the
 * `X-Bootgly-Test:` index header — that is exactly what these helpers do.
 */
class Sockets
{
   /**
    * Install the spec's handler at slot `$specIndex` by sending a single
    * primer request that carries the `X-Bootgly-Test` header. Must be
    * called once per spec before iterating.
    *
    * @param string $path The trigger route registered in the spec's `response:` callback.
    */
   public static function prime (string $hostPort, int $specIndex, string $path = '/fuzz-trigger'): void
   {
      $sock = @stream_socket_client("tcp://{$hostPort}", $errno, $errstr, timeout: 2);
      if (! is_resource($sock)) {
         return;
      }
      stream_set_timeout($sock, 2);
      @fwrite(
         $sock,
         "GET {$path} HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "X-Bootgly-Test: {$specIndex}\r\n"
         . "Connection: close\r\n\r\n"
      );
      $deadline = microtime(true) + 1.0;
      while (microtime(true) < $deadline) {
         $chunk = @fread($sock, 4096);
         if ($chunk === '' || $chunk === false) break;
      }
      @fclose($sock);
   }

   /**
    * Open a fresh TCP socket, write `$bytes`, read until the server closes
    * (or `$timeout` elapses), and return the response string.
    *
    * Callers SHOULD include `Connection: close` so the server's FIN is the
    * canonical end-of-message signal — otherwise this falls back to the
    * timeout window.
    *
    * @return string '' on connect/write failure or full timeout with no bytes.
    */
   public static function probe (string $hostPort, string $bytes, float $timeout = 2.0): string
   {
      $sock = @stream_socket_client("tcp://{$hostPort}", $errno, $errstr, timeout: 2);
      if (! is_resource($sock)) return '';
      // @ Per-read timeout — we drive the overall budget via $deadline below.
      stream_set_timeout($sock, 0, 200_000);

      $written = @fwrite($sock, $bytes);
      if ($written !== strlen($bytes)) {
         @fclose($sock);
         return '';
      }

      $response = '';
      $deadline = microtime(true) + $timeout;
      while (microtime(true) < $deadline) {
         $chunk = @fread($sock, 8192);
         if ($chunk === false) break;
         if ($chunk !== '') {
            $response .= $chunk;
            continue;
         }
         // @ EOF? FIN from server — we have the full response.
         if (feof($sock)) break;
         // @ Read timed out without bytes; check budget and retry.
      }
      @fclose($sock);
      return $response;
   }
}
