<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders;


use function ctype_xdigit;
use function hexdec;
use function ltrim;
use function rtrim;
use function strlen;
use function strpos;
use function substr;
use function trim;

use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoder;


class Decoder_Chunked extends Decoder
{
   /**
    * `BWS` (RFC 9110 §5.6.3) around the chunk-extension `;` — SP and HTAB.
    */
   private const string BWS = " \t";
   /**
    * Maximum SIGNIFICANT hexadecimal digits accepted in a chunk-size, matching
    * the server decoder. 15 digits cap a chunk just under 2^60, which keeps
    * `hexdec()` on the integer side of its float threshold — above it the
    * conversion collapses to a bogus `0` or a negative (HCLI-19). Any longer
    * token is orders of magnitude beyond every real response anyway.
    */
   private const int CHUNK_SIZE_DIGITS = 15;

   /** @var string Accumulated raw body from decoded chunks. */
   protected string $body = '';
   /** @var string Partial leftover buffer between reads. */
   protected string $leftover = '';
   /** @var int Maximum decoded body size (0 = unbounded); driven by HTTP_Client_CLI::$maxResponseBytes. */
   protected int $maxSize = 0;

   public function __construct (int $maxSize = 0)
   {
      $this->maxSize = $maxSize;
   }

   /**
    * Initialize/reset chunked decoder state.
    *
    * @return void
    */
   public function init (): void
   {
      $this->body = '';
      $this->leftover = '';
   }

   /**
    * Feed initial body data from the first read buffer.
    *
    * @param string $data
    *
    * @return void
    */
   public function feed (string $data): void
   {
      $this->leftover = $data;
   }

   /**
    * @return null|array{complete: true, body: string, bodyLength: int, consumed: int, leftover: string}|array{failed: true, status: string, consumed: int}
    */
   public function decode (string $buffer, int $size, null|string $method = null): null|array
   {
      // @ Append new data to leftover
      if ($buffer !== '') {
         $this->leftover .= $buffer;
      }

      $data = $this->leftover;

      // @ Parse chunks
      while (true) {
         // @ Find chunk-size line end
         $eol = strpos($data, "\r\n");
         if ($eol === false) {
            break; // Need more data
         }

         $chunkSizeHex = substr($data, 0, $eol);
         // @ Strip chunk-extension (RFC 9112 §7.1.1) before parsing size
         $semicolon = strpos($chunkSizeHex, ';');
         if ($semicolon !== false) {
            // ! BWS may precede the `;`, which puts it on the size side of the
            //   split — `1 ;foo=bar` is conformant. Only TRAILING BWS goes, so
            //   `5 garbage` still fails validation below.
            $chunkSizeHex = rtrim(substr($chunkSizeHex, 0, $semicolon), self::BWS);
         }

         // ? RFC 9112 §7.1 — chunk-size = 1*HEXDIG (no signs, no whitespace,
         //   no `0x` prefix). `hexdec()` silently ignores invalid characters,
         //   so `-1`, `0x10`, `5 garbage` and `0e0` would all convert to a
         //   plausible size, and `zz` would convert to 0 — read as the
         //   TERMINAL chunk, completing the response early with a truncated
         //   body (HCLI-19). One `ctype_xdigit` call rejects every variant.
         if ($chunkSizeHex === '' || ctype_xdigit($chunkSizeHex) === false) {
            $this->body     = '';
            $this->leftover = '';

            return [
               'failed'   => true,
               'status'   => 'Invalid Chunked Encoding',
               'consumed' => $size,
            ];
         }

         // ? Leading zeros are legal, so significance — not token length —
         //   carries the magnitude. `hexdec()` returns a FLOAT from 2^63 up and
         //   the int cast collapses it to `0` or to `PHP_INT_MIN`, a negative
         //   size that slips past every guard and makes the slicing arithmetic
         //   below never advance — an endless loop (HCLI-19). Bound the
         //   significant digits BEFORE converting, so every accepted token
         //   converts exactly.
         $significant = ltrim($chunkSizeHex, '0');

         if (strlen($significant) > self::CHUNK_SIZE_DIGITS) {
            $this->body     = '';
            $this->leftover = '';

            return [
               'failed'   => true,
               'status'   => 'Response Too Large',
               'consumed' => $size,
            ];
         }

         $chunkSize = $significant === '' ? 0 : (int) hexdec($significant);

         // ? Terminal chunk (last-chunk: chunk-size = 0)
         if ($chunkSize === 0) {
            // @ Consume trailer-section + terminating CRLF (RFC 9112 §7.1.3)
            // Structure after last-chunk line: [trailer-field CRLF]* CRLF
            // We look for "\r\n\r\n" starting at $eol (from "0\r\n"):
            //   - No trailers:   "0\r\n\r\n"     → found at $eol  (offset 0 from CRLF)
            //   - With trailers: "0\r\nX: v\r\n\r\n" → found after trailer fields
            $trailerTermPos = strpos($data, "\r\n\r\n", $eol);
            if ($trailerTermPos === false) {
               // Trailer section not yet complete, need more data
               break;
            }

            $bodyLength = strlen($this->body);
            $body = $this->body;

            // @ Any bytes after the trailer terminator belong to the next response
            $leftover = substr($data, $trailerTermPos + 4);

            // @ Reset decoder state
            $this->body     = '';
            $this->leftover = '';

            return [
               'complete'    => true,
               'body'        => $body,
               'bodyLength'  => $bodyLength,
               'consumed'    => $size,
               'leftover'    => $leftover,
            ];
         }

         // ? Guard: the DECLARED chunk would push the decoded body past the
         //   cap — fail fast with a distinguishable overflow record, never a
         //   fake completion (HCLI-11); the chunk data need not have arrived
         if ($this->maxSize > 0 && strlen($this->body) + $chunkSize > $this->maxSize) {
            $this->body     = '';
            $this->leftover = '';

            return [
               'failed'   => true,
               'status'   => 'Response Too Large',
               'consumed' => $size,
            ];
         }

         // @ Check if we have enough data for this chunk + trailing CRLF
         $needed = $eol + 2 + $chunkSize + 2;
         if (strlen($data) < $needed) {
            break; // Need more data
         }

         // @ Extract chunk data
         $this->body .= substr($data, $eol + 2, $chunkSize);

         // @ Advance past chunk-size line + CRLF + chunk-data + CRLF
         $data = substr($data, $needed);
      }

      $this->leftover = $data;

      return null; // Need more data
   }
}
