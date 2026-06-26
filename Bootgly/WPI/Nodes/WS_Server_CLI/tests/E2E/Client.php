<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E;


use const STREAM_CLIENT_CONNECT;
use function base64_encode;
use function chr;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function intdiv;
use function microtime;
use function ord;
use function pack;
use function random_bytes;
use function str_contains;
use function str_repeat;
use function str_starts_with;
use function stream_context_create;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;
use function substr;
use function unpack;
use function usleep;


/**
 * Minimal RFC 6455 client used by the WS_Server_CLI E2E suite. Connects to the
 * live test-mode server, drives the handshake + frame exchange, and reads
 * server frames.
 */
class Client
{
   public static int $port = 8084;
   public static bool $tls = false;


   /**
    * Send a raw upgrade request and return the response head (up to CRLFCRLF).
    */
   public static function raw (string $request, float $timeout = 2.0): string
   {
      $Socket = self::dial();
      if ($Socket === false) {
         return '';
      }
      stream_set_timeout($Socket, 2);

      fwrite($Socket, $request);

      $response = '';
      $deadline = microtime(true) + $timeout;
      while (microtime(true) < $deadline) {
         $chunk = fread($Socket, 4096);
         if ($chunk === '' || $chunk === false) {
            if (feof($Socket)) {
               break;
            }
            usleep(10000);
            continue;
         }
         $response .= $chunk;
         if (str_contains($response, "\r\n\r\n")) {
            break;
         }
      }
      fclose($Socket);

      return $response;
   }

   /**
    * Open a connection and complete a valid handshake.
    *
    * @return resource|false The post-handshake socket, or false on failure.
    */
   public static function open (string $extensions = '', string $auth = '', string $origin = '')
   {
      $Socket = self::dial();
      if ($Socket === false) {
         return false;
      }
      stream_set_timeout($Socket, 3);

      $key = base64_encode(random_bytes(16));
      $request = "GET /e2e HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
         . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n";
      if ($extensions !== '') {
         $request .= "Sec-WebSocket-Extensions: {$extensions}\r\n";
      }
      if ($auth !== '') {
         $request .= "Authorization: {$auth}\r\n";
      }
      if ($origin !== '') {
         $request .= "Origin: {$origin}\r\n";
      }
      $request .= "\r\n";
      fwrite($Socket, $request);

      $response = '';
      $deadline = microtime(true) + 2.0;
      while (! str_contains($response, "\r\n\r\n") && microtime(true) < $deadline) {
         $chunk = fread($Socket, 4096);
         if ($chunk === '' || $chunk === false) {
            if (feof($Socket)) {
               break;
            }
            usleep(10000);
            continue;
         }
         $response .= $chunk;
      }

      if (! str_starts_with($response, 'HTTP/1.1 101')) {
         fclose($Socket);
         return false;
      }

      return $Socket;
   }

   /**
    * Build a masked client frame (as a browser sends).
    */
   public static function mask (int $opcode, string $payload, bool $rsv1 = false, bool $fin = true): string
   {
      $byte0 = ($fin ? 0x80 : 0x00) | ($rsv1 ? 0x40 : 0x00) | ($opcode & 0x0F);
      $length = strlen($payload);
      $key = random_bytes(4);

      $header = chr($byte0);
      if ($length < 126) {
         $header .= chr(0x80 | $length);
      }
      else if ($length < 65536) {
         $header .= chr(0x80 | 126) . pack('n', $length);
      }
      else {
         $header .= chr(0x80 | 127) . pack('J', $length);
      }
      $header .= $key;

      $masked = $payload ^ substr(str_repeat($key, intdiv($length, 4) + 1), 0, $length);

      return $header . $masked;
   }

   /**
    * Read one server frame.
    *
    * @param resource $Socket
    *
    * @return null|array{fin: bool, rsv1: bool, opcode: int, payload: string}
    */
   public static function read ($Socket, float $timeout = 2.0): null|array
   {
      $head = self::pull($Socket, 2, $timeout);
      if (strlen($head) < 2) {
         return null;
      }
      $byte0 = ord($head[0]);
      $byte1 = ord($head[1]);
      $length = $byte1 & 0x7F;

      if ($length === 126) {
         $extended = unpack('n', self::pull($Socket, 2, $timeout));
         $length = $extended === false ? 0 : (int) $extended[1];
      }
      else if ($length === 127) {
         $extended = unpack('J', self::pull($Socket, 8, $timeout));
         $length = $extended === false ? 0 : (int) $extended[1];
      }

      $payload = $length > 0 ? self::pull($Socket, $length, $timeout) : '';

      return [
         'fin' => ($byte0 & 0x80) !== 0,
         'rsv1' => ($byte0 & 0x40) !== 0,
         'opcode' => $byte0 & 0x0F,
         'payload' => $payload,
      ];
   }

   /**
    * Read a full message, reassembling fragments until FIN. A close frame
    * (opcode 0x8) is surfaced as-is so callers can detect a fail-fast close.
    *
    * @param resource $Socket
    *
    * @return null|array{opcode: int, payload: string, close: bool}
    */
   public static function message ($Socket, float $timeout = 2.0): null|array
   {
      $opcode = 0;
      $payload = '';
      while (true) {
         $frame = self::read($Socket, $timeout);
         if ($frame === null) {
            return null;
         }
         if ($frame['opcode'] === 0x8) {
            return ['opcode' => 0x8, 'payload' => $frame['payload'], 'close' => true];
         }
         if ($frame['opcode'] !== 0x0) {
            $opcode = $frame['opcode'];
         }
         $payload .= $frame['payload'];
         if ($frame['fin']) {
            break;
         }
      }

      return ['opcode' => $opcode, 'payload' => $payload, 'close' => false];
   }

   /**
    * Read the close code from the next frame (or -1 when it is not a close).
    *
    * @param resource $Socket
    */
   public static function close ($Socket, float $timeout = 2.0): int
   {
      $frame = self::read($Socket, $timeout);
      if ($frame === null || $frame['opcode'] !== 0x8) {
         return -1;
      }
      if (strlen($frame['payload']) < 2) {
         return 1005;
      }
      $code = unpack('n', substr($frame['payload'], 0, 2));

      return $code === false ? 1005 : (int) $code[1];
   }

   /**
    * Connect to the test server, retrying briefly to absorb the worker's
    * post-fork bind race.
    *
    * @return resource|false
    */
   private static function dial ()
   {
      $address = (self::$tls ? 'ssl://' : 'tcp://') . '127.0.0.1:' . self::$port;
      $Context = self::$tls
         ? stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])
         : stream_context_create();

      for ($attempt = 0; $attempt < 20; $attempt++) {
         $Socket = @stream_socket_client($address, $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $Context);
         if ($Socket !== false) {
            return $Socket;
         }
         usleep(50000);
      }

      return false;
   }

   /**
    * Read exactly `$n` bytes (best-effort within the deadline).
    *
    * @param resource $Socket
    */
   private static function pull ($Socket, int $n, float $timeout): string
   {
      $buffer = '';
      $deadline = microtime(true) + $timeout;
      while (strlen($buffer) < $n && microtime(true) < $deadline) {
         $chunk = fread($Socket, $n - strlen($buffer));
         if ($chunk === '' || $chunk === false) {
            if (feof($Socket)) {
               break;
            }
            usleep(5000);
            continue;
         }
         $buffer .= $chunk;
      }

      return $buffer;
   }
}
