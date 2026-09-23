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


use function strlen;
use function substr;

use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoder;


/**
 * Collects a Content-Length or close-delimited response body across reads, once
 * the head is parsed. The head is never parsed again and every read appends only
 * its own bytes, so a large body costs linear time instead of one full re-parse
 * per read (H-HCLI-4).
 *
 * The collector owns its bytes (like `Decoder_Chunked`): the client hands them to
 * the Response body once, at the end — on completion or through `drain()`.
 */
class Decoder_Waiting extends Decoder
{
   // * Data
   /** @var null|int The declared Content-Length; `null` = close-delimited (ends at the connection close). */
   protected null|int $length;
   /** @var string The body bytes collected so far (seeded with the head read's body bytes). */
   protected string $body;


   /**
    * @param null|int $length The declared Content-Length, or `null` for a close-delimited body.
    * @param string $body The body bytes that arrived in the same read as the head.
    */
   public function __construct (null|int $length, string $body = '')
   {
      // * Data
      $this->length = $length;
      $this->body = $body;
   }

   /**
    * Append the body bytes of one read. A Content-Length body completes once its
    * length is reached — any bytes past it are returned as `leftover` (the next
    * pipelined response). A close-delimited body never completes here: the
    * connection close ends it (see `drain()`).
    *
    * @param string $buffer The bytes of this read.
    * @param int $size The byte length of `$buffer`.
    * @param null|string $method The request method (unused: the head decided the framing).
    *
    * @return null|array{complete: true, body: string, bodyLength: int, consumed: int, leftover: string}
    */
   public function decode (string $buffer, int $size, null|string $method = null): null|array
   {
      // ?: Close-delimited — every byte is body until the connection closes
      if ($this->length === null) {
         $this->body .= $buffer;

         return null;
      }

      // ! Only the bytes the declared length still expects belong to the body
      $remaining = $this->length - strlen($this->body);

      // ?: Still short of the declared length
      if ($size < $remaining) {
         $this->body .= $buffer;

         return null;
      }

      // @ Complete: slice the tail once, never out of the collected body
      if ($size === $remaining) {
         $this->body .= $buffer;
         $leftover = '';
      }
      else {
         $this->body .= substr($buffer, 0, $remaining);
         $leftover = substr($buffer, $remaining);
      }

      $body = $this->body;
      $this->body = '';

      // :
      return [
         'complete'   => true,
         'body'       => $body,
         'bodyLength' => $this->length,
         'consumed'   => $remaining,
         'leftover'   => $leftover,
      ];
   }

   /**
    * Hand over the bytes collected so far and release them — the end of a body
    * that never completed in `decode()`: a close-delimited body at the
    * connection close, or a Content-Length body cut short (truncation, timeout,
    * abort).
    *
    * @return array{body: string, length: null|int} The collected bytes and the declared length (`null` = close-delimited).
    */
   public function drain (): array
   {
      $body = $this->body;
      $this->body = '';

      // :
      return [
         'body'   => $body,
         'length' => $this->length,
      ];
   }
}
