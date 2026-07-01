<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2;


use const STREAM_CLIENT_CONNECT;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function microtime;
use function ord;
use function stream_context_create;
use function stream_set_blocking;
use function stream_socket_client;
use function strlen;
use function substr;
use function usleep;

use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;


/**
 * Minimal raw-socket HTTP/2 test client.
 *
 * Speaks frames built with `Modules\HTTP2\Frame::pack()` over a cleartext
 * (prior-knowledge) or TLS connection and parses response frames into
 * `['type' => int, 'flags' => int, 'stream' => int, 'payload' => string]`.
 */
final class Client
{
   /** @var resource */
   public $Socket;

   // * Data
   public string $buffer = '';


   public function __construct (string $uri = 'tcp://127.0.0.1:8085', null|array $context = null)
   {
      $options = $context ?? [];
      $Socket = stream_socket_client(
         $uri,
         $errno,
         $error,
         5,
         STREAM_CLIENT_CONNECT,
         stream_context_create($options)
      );
      if ($Socket === false) {
         throw new \RuntimeException("h2 test client connect failed: $error");
      }

      stream_set_blocking($Socket, false);
      $this->Socket = $Socket;
   }

   public function preface (string $settings = ''): void
   {
      $this->send(HTTP2::PREFACE . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, $settings));
   }

   public function send (string $raw): void
   {
      @fwrite($this->Socket, $raw);
   }

   /**
    * Read one frame (or null on timeout / connection close).
    *
    * @return null|array{type: int, flags: int, stream: int, payload: string}
    */
   public function frame (float $timeout = 2.0): null|array
   {
      $deadline = microtime(true) + $timeout;

      while (true) {
         // ?: Complete frame already buffered?
         if (strlen($this->buffer) >= 9) {
            $word = (ord($this->buffer[0]) << 24) | (ord($this->buffer[1]) << 16)
               | (ord($this->buffer[2]) << 8) | ord($this->buffer[3]);
            $length = $word >> 8;
            $type = $word & 0xff;

            if (strlen($this->buffer) >= 9 + $length) {
               $flags = ord($this->buffer[4]);
               $stream = ((ord($this->buffer[5]) << 24) | (ord($this->buffer[6]) << 16)
                  | (ord($this->buffer[7]) << 8) | ord($this->buffer[8])) & 0x7fffffff;
               $payload = substr($this->buffer, 9, $length);
               $this->buffer = substr($this->buffer, 9 + $length);

               return [
                  'type' => $type,
                  'flags' => $flags,
                  'stream' => $stream,
                  'payload' => $payload
               ];
            }
         }

         // ? Timed out
         if (microtime(true) >= $deadline) {
            return null;
         }

         $chunk = @fread($this->Socket, 65536);
         if ($chunk !== false && $chunk !== '') {
            $this->buffer .= $chunk;
            continue;
         }
         if (@feof($this->Socket) && strlen($this->buffer) < 9) {
            return null;
         }

         usleep(10000);
      }
   }

   /**
    * Read frames until one of `$type` arrives (skipping others) or timeout.
    *
    * @return null|array{type: int, flags: int, stream: int, payload: string}
    */
   public function expect (int $type, float $timeout = 2.0): null|array
   {
      $deadline = microtime(true) + $timeout;

      while (microtime(true) < $deadline) {
         $frame = $this->frame($deadline - microtime(true));
         if ($frame === null) {
            return null;
         }
         if ($frame['type'] === $type) {
            return $frame;
         }
      }

      return null;
   }

   /**
    * Check whether the server closed the connection (EOF), within a timeout.
    */
   public function closed (float $timeout = 2.0): bool
   {
      $deadline = microtime(true) + $timeout;

      while (microtime(true) < $deadline) {
         $chunk = @fread($this->Socket, 65536);
         if ($chunk !== false && $chunk !== '') {
            $this->buffer .= $chunk;
            continue;
         }
         if (@feof($this->Socket)) {
            return true;
         }
         usleep(10000);
      }

      return false;
   }

   public function close (): void
   {
      try {
         @fclose($this->Socket);
      }
      catch (\Throwable) {
         // ...
      }
   }
}
