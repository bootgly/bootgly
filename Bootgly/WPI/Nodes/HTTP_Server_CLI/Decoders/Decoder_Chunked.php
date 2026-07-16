<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


use const Bootgly\WPI;
use function ctype_xdigit;
use function explode;
use function hexdec;
use function preg_match;
use function strlen;
use function strpos;
use function substr;
use function time;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Feeding;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCP_Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


class Decoder_Chunked extends Decoders implements Feeding
{
   // * Config
   //   Absolute decode deadline in seconds (audit F-6): anchored to `$decoded`
   //   (set once in init()), never refreshed per packet — a slow-drip body
   //   cannot extend it.
   private const int BODY_DEADLINE = 30;
   private const int TRAILER_LIMIT = 16384;

   // # States
   private const int READ_SIZE = 0;
   private const int READ_DATA = 1;
   private const int READ_TRAILERS = 2;

   // * Data
   private string $buffer = '';
   private string $body = '';

   // * Metadata
   // Absolute decode start time (set once in init(); NOT refreshed per packet).
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

   /**
    * Has the absolute decode deadline been reached? (audit F-6)
    *
    * Anchored to `$decoded` (set once in `init()`), so it is a hard cap from the
    * start of the chunked body. Unlike a per-packet sliding window, a slow drip
    * cannot push the deadline back.
    */
   public function expire (): bool
   {
      return (time() - $this->decoded) >= self::BODY_DEADLINE;
   }

   public function decode (Packages $Package, string $buffer, int $size): States
   {
      /** @var TCP_Packages $Package */
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

      // ? Absolute decode deadline (audit F-6): anchored to the decode start,
      //   NOT refreshed per packet, so a slow drip cannot hold the worker
      //   buffer/connection indefinitely.
      if ($this->expire()) {
         $Body->waiting = false;

         $this->body = '';
         $this->buffer = '';

         $Package->Decoder = null;
         $Package->consumed = 0;
         $Package->reject("HTTP/1.1 408 Request Timeout\r\n\r\n");
         return States::Rejected;
      }

      // @ Append the current transport read. `$carried` belongs to earlier
      //   decode calls; only raw wire bytes after it may be reported through
      //   Package::$consumed when this call completes.
      $carried = strlen($this->buffer);
      $this->buffer .= $buffer;

      // @ Process chunks
      while (true) {
         switch ($this->state) {
            case self::READ_SIZE:
               // @ Find the chunk size line (\r\n terminated)
               $pos = strpos($this->buffer, "\r\n");
               if ($pos === false) {
                  $Package->consumed = $size;
                  return States::Incomplete; // Need more data
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
               //   `0e0`. `ctype_xdigit` on the exact size is a single
               //   C-call that rejects every such variant at near-zero cost.
               if ($sizeLine === '' || ! ctype_xdigit($sizeLine)) {
                  $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                  $Body->waiting = false;
                  $this->body = '';
                  $this->buffer = '';
                  $Package->Decoder = null;
                  $Package->consumed = 0;
                  return States::Rejected;
               }

               $chunkSize = (int) hexdec($sizeLine);

               if ($chunkSize === 0) {
                  // @ A zero chunk starts the terminal section; completion
                  //   still requires the optional trailers and final CRLF.
                  $this->state = self::READ_TRAILERS;
                  break;
               }

               // @ Validate total size against the configurable cap (audit F-6:
               //   honors `requestMaxBodySize`; was a hard-coded 10 MB constant).
               if ($this->totalSize + $chunkSize > Server\Request::$maxBodySize) {
                  $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
                  $Body->waiting = false;

                  // @ Clean up instance state to prevent cross-request leakage
                  $this->body = '';
                  $this->buffer = '';

                  $Package->Decoder = null;

                  $Package->consumed = 0;
                  return States::Rejected;
               }

               $this->chunkSize = $chunkSize;
               $this->chunkRead = 0;
               $this->state = self::READ_DATA;
               break;

            case self::READ_DATA:
               $remaining = $this->chunkSize - $this->chunkRead;
               $available = strlen($this->buffer);

               if ($available === 0) {
                  $Package->consumed = $size;
                  return States::Incomplete; // Need more data
               }

               $toRead = ($available < $remaining) ? $available : $remaining;
               $this->body .= substr($this->buffer, 0, $toRead);
               $this->buffer = substr($this->buffer, $toRead);
               $this->chunkRead += $toRead;
               $this->totalSize += $toRead;

               if ($this->chunkRead < $this->chunkSize) {
                  $Package->consumed = $size;
                  return States::Incomplete; // Need more data for this chunk
               }

               // @ Consume trailing \r\n after chunk data (RFC 9112 §7.1 —
               //   `chunk = chunk-size [ext] CRLF chunk-data CRLF`). The
               //   previous code blindly skipped 2 bytes without asserting
               //   they were CRLF, letting attacker-chosen framing corrupt
               //   body boundaries.
               if (strlen($this->buffer) < 2) {
                  $Package->consumed = $size;
                  return States::Incomplete; // Need the trailing CRLF
               }
               if ($this->buffer[0] !== "\r" || $this->buffer[1] !== "\n") {
                  $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                  $Body->waiting = false;
                  $this->body = '';
                  $this->buffer = '';
                  $Package->Decoder = null;
                  $Package->consumed = 0;
                  return States::Rejected;
               }
               $this->buffer = substr($this->buffer, 2);

               $this->state = self::READ_SIZE;
               break;

            case self::READ_TRAILERS:
               $length = strlen($this->buffer);
               if ($length < 2) {
                  $Package->consumed = $size;
                  return States::Incomplete;
               }

               if ($this->buffer[0] === "\r" && $this->buffer[1] === "\n") {
                  // @ Empty trailer section: the leading CRLF is its complete
                  //   terminator. Everything after it belongs to the pipeline.
                  $this->buffer = substr($this->buffer, 2);
               }
               else {
                  $trailerEnd = strpos($this->buffer, "\r\n\r\n");
                  if ($trailerEnd === false) {
                     if ($length > self::TRAILER_LIMIT) {
                        $Package->reject("HTTP/1.1 431 Request Header Fields Too Large\r\n\r\n");
                        $Body->waiting = false;
                        $this->body = '';
                        $this->buffer = '';
                        $Package->Decoder = null;
                        $Package->consumed = 0;
                        return States::Rejected;
                     }

                     $Package->consumed = $size;
                     return States::Incomplete;
                  }

                  if ($trailerEnd > self::TRAILER_LIMIT) {
                     $Package->reject("HTTP/1.1 431 Request Header Fields Too Large\r\n\r\n");
                     $Body->waiting = false;
                     $this->body = '';
                     $this->buffer = '';
                     $Package->Decoder = null;
                     $Package->consumed = 0;
                     return States::Rejected;
                  }

                  $trailers = substr($this->buffer, 0, $trailerEnd);
                  foreach (explode("\r\n", $trailers) as $line) {
                     // @ RFC 9110 field-line syntax. Requiring a token field
                     //   name prevents a request line from being accepted as
                     //   a trailer when the final empty line is missing.
                     if (preg_match(
                        "/\\A[!#\$%&'*+\\-.^_`|~0-9A-Za-z]+:[\\x09\\x20-\\x7E\\x80-\\xFF]*\\z/D",
                        $line,
                     ) !== 1) {
                        $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                        $Body->waiting = false;
                        $this->body = '';
                        $this->buffer = '';
                        $Package->Decoder = null;
                        $Package->consumed = 0;
                        return States::Rejected;
                     }
                  }

                  $this->buffer = substr($this->buffer, $trailerEnd + 4);
               }

               // @ Convert the raw parser cursor into a current-call cursor.
               //   Parsed bytes carried from previous reads are excluded;
               //   bytes after the terminal section stay in `$this->buffer`
               //   until the cursor is captured, then the TCP pipeline owns
               //   the untouched suffix from its original input.
               $wireConsumed = $carried + $size - strlen($this->buffer);
               $consumed = $wireConsumed > $carried
                  ? $wireConsumed - $carried
                  : 0;
               if ($consumed > $size) {
                  $consumed = $size;
               }

               $Body->raw = $this->body;
               $Body->length = $this->totalSize;
               $Body->downloaded = $this->totalSize;
               $Body->waiting = false;

               $this->body = '';
               $this->buffer = '';
               $Package->Decoder = null;
               $Package->consumed = $consumed;

               return States::Complete;
         }
      }
   }
}
