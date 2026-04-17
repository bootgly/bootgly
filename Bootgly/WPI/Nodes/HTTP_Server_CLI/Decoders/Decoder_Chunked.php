<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


use function ctype_xdigit;
use function hexdec;
use function strlen;
use function strpos;
use function substr;
use function time;
use function trim;

use const Bootgly\WPI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


class Decoder_Chunked extends Decoders
{
   // * Config
   private const int MAX_BODY_SIZE = 10485760; // 10 MB

   // # States
   private const int READ_SIZE = 0;
   private const int READ_DATA = 1;

   // * Data
   private string $buffer = '';
   private string $body = '';

   // * Metadata
   private int $decoded = 0;
   private int $state = self::READ_SIZE;
   private int $chunkSize = 0;
   private int $chunkRead = 0;
   private int $totalSize = 0;


   public function init (): void
   {
      $this->buffer = '';
      $this->body = '';
      $this->decoded = time();
      $this->state = self::READ_SIZE;
      $this->chunkSize = 0;
      $this->chunkRead = 0;
      $this->totalSize = 0;
   }

   public function feed (string $data): void
   {
      $this->buffer .= $data;
   }

   public function decode (Packages $Package, string $buffer, int $size): int
   {
      $WPI = WPI;
      /** @var Server $Server */
      $Server = $WPI->Server;

      /** @var Server\Request $Request */
      $Request = $WPI->Request;
      $Body = $Request->Body;

      if (! $Body->waiting) {
         $Package->Decoder = null;
         return $Server::$Decoder->decode($Package, $buffer, $size); // @phpstan-ignore method.nonObject
      }

      // * Metadata
      $elapsed = time() - $this->decoded;
      if ($elapsed >= 30) {
         $Body->waiting = false;

         $this->body = '';
         $this->buffer = '';

         $Package->Decoder = null;

         return $Server::$Decoder->decode($Package, $buffer, $size); // @phpstan-ignore method.nonObject
      }

      // @ Append incoming data
      $this->buffer .= $buffer;

      // @ Update last decoded time
      $this->decoded = time();

      // @ Process chunks
      while (true) {
         switch ($this->state) {
            case self::READ_SIZE:
               // @ Find the chunk size line (\r\n terminated)
               $pos = strpos($this->buffer, "\r\n");
               if ($pos === false) {
                  return 0; // Need more data
               }

               $sizeLine = substr($this->buffer, 0, $pos);
               $this->buffer = substr($this->buffer, $pos + 2);

               // @ Strip chunk extensions (RFC 9112 §7.1.1)
               $semiPos = strpos($sizeLine, ';');
               if ($semiPos !== false) {
                  $sizeLine = substr($sizeLine, 0, $semiPos);
               }

               // ! RFC 9112 §7.1 — chunk-size = 1*HEXDIG (no signs, no
               //   whitespace, no `0x` prefix). `hexdec` silently truncates
               //   on invalid chars, accepting `-1`, `0x10`, `5 garbage`,
               //   `0e0`. `ctype_xdigit` on the trimmed line is a single
               //   C-call that rejects every such variant at near-zero cost.
               $sizeLine = trim($sizeLine);
               if ($sizeLine === '' || ! ctype_xdigit($sizeLine)) {
                  $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                  $Body->waiting = false;
                  $this->body = '';
                  $this->buffer = '';
                  $Package->Decoder = null;
                  return 0;
               }

               $chunkSize = (int) hexdec($sizeLine);

               if ($chunkSize === 0) {
                  // @ Last chunk: body complete
                  $Body->raw = $this->body;
                  $Body->length = $this->totalSize;
                  $Body->downloaded = $this->totalSize;
                  $Body->waiting = false;

                  // @ Clean up
                  $this->body = '';
                  $this->buffer = '';

                  $Package->Decoder = null;

                  return $this->totalSize;
               }

               // @ Validate total size
               if ($this->totalSize + $chunkSize > self::MAX_BODY_SIZE) {
                  $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
                  $Body->waiting = false;

                  // @ Clean up instance state to prevent cross-request leakage
                  $this->body = '';
                  $this->buffer = '';

                  $Package->Decoder = null;

                  return 0;
               }

               $this->chunkSize = $chunkSize;
               $this->chunkRead = 0;
               $this->state = self::READ_DATA;
               break;

            case self::READ_DATA:
               $remaining = $this->chunkSize - $this->chunkRead;
               $available = strlen($this->buffer);

               if ($available === 0) {
                  return 0; // Need more data
               }

               $toRead = ($available < $remaining) ? $available : $remaining;
               $this->body .= substr($this->buffer, 0, $toRead);
               $this->buffer = substr($this->buffer, $toRead);
               $this->chunkRead += $toRead;
               $this->totalSize += $toRead;

               if ($this->chunkRead < $this->chunkSize) {
                  return 0; // Need more data for this chunk
               }

               // @ Consume trailing \r\n after chunk data (RFC 9112 §7.1 —
               //   `chunk = chunk-size [ext] CRLF chunk-data CRLF`). The
               //   previous code blindly skipped 2 bytes without asserting
               //   they were CRLF, letting attacker-chosen framing corrupt
               //   body boundaries.
               if (strlen($this->buffer) < 2) {
                  return 0; // Need the trailing CRLF
               }
               if ($this->buffer[0] !== "\r" || $this->buffer[1] !== "\n") {
                  $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                  $Body->waiting = false;
                  $this->body = '';
                  $this->buffer = '';
                  $Package->Decoder = null;
                  return 0;
               }
               $this->buffer = substr($this->buffer, 2);

               $this->state = self::READ_SIZE;
               break;
         }
      }
   }
}
