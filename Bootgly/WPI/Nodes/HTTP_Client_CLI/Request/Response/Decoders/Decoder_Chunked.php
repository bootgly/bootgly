<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders;


use function hexdec;
use function strlen;
use function strpos;
use function substr;
use function trim;

use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoder;


class Decoder_Chunked extends Decoder
{
   /** @var string Accumulated raw body from decoded chunks. */
   protected string $body = '';
   /** @var string Partial leftover buffer between reads. */
   protected string $leftover = '';
   /** @var int Maximum decoded body size (10 MB). */
   protected int $maxSize = 10 * 1024 * 1024;

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
    * @return null|array{complete: true, body: string, bodyLength: int, consumed: int, leftover: string}
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
            $chunkSizeHex = substr($chunkSizeHex, 0, $semicolon);
         }
         $chunkSize = (int) hexdec(trim($chunkSizeHex));

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

         // @ Guard: maximum body size
         if (strlen($this->body) + $chunkSize > $this->maxSize) {
            $this->body     = '';
            $this->leftover = '';

            return [
               'complete'   => true,
               'body'       => '',
               'bodyLength' => 0,
               'consumed'   => $size,
               'leftover'   => '',
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
