<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


use function is_array;
use function is_int;
use function is_string;
use function strlen;

use Bootgly\ABI\Data\Language;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Body;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;


trait Raw
{
   public Header $Header;
   public Body $Body;

   // # Encode fast-lane cache: full wire bytes of the previous response,
   //   valid while every input it was derived from is identical. Inputs
   //   are pointer-stable on the hot path (status line untouched at 200,
   //   `Header->raw` reused until the per-second Date rebuild, body set
   //   from an interned string by the handler), so the three `===` guards
   //   cost O(1); any real change (new header block, different body,
   //   status transition) is a content mismatch and rebuilds.
   private string $wire = '';
   private string $wireStatus = '';
   private string $wireHeader = '';
   private string $wireBody = '';

   /**
    * Measure the exact HTTP/1 representation bytes queued by a streamed file
    * response: buffered prefix plus file ranges and multipart padding.
    */
   private function measure (): int
   {
      $size = strlen($this->Body->raw);

      foreach ($this->files as $queued) {
         $parts = is_array($queued['parts'] ?? null) ? $queued['parts'] : [];
         $pads = is_array($queued['pads'] ?? null) ? $queued['pads'] : [];

         foreach ($parts as $index => $part) {
            if (! is_array($part)) {
               continue;
            }

            $bytes = $part['length'] ?? null;
            if (is_int($bytes) && $bytes > 0) {
               $size += $bytes;
            }

            $pad = $pads[$index] ?? null;
            if (! is_array($pad)) {
               continue;
            }

            $prepend = $pad['prepend'] ?? null;
            if (is_string($prepend)) {
               $size += strlen($prepend);
            }

            $append = $pad['append'] ?? null;
            if (is_string($append)) {
               $size += strlen($append);
            }
         }
      }

      return $size;
   }

   /**
    * Encode the Response raw for sending.
    *
    * @param Packages $Package TCP Package associated with the response
    * @param int &$length Reference to the variable receiving the length of the response
    *
    * @return string The Response Raw to be sent
    */
   /**
    * @param int<0, max>|null $length
    * @param-out int<0, max>|null $length
    */
   public function encode (Packages $Package, null|int &$length): string
   {
      $Header  = &$this->Header;
      $Body = &$this->Body;

      $Request = $this->Request ?? Server::$Request;

      // @ Localized responses vary by language for external caches — the
      //   translation API is ambient (any handler may translate while
      //   catalogs are registered); one static read when i18n is off.
      //   encode() is the single funnel: normal, testing, deferred and
      //   HTTP/2 responses all pass here before header serialization.
      if (Language::$roots !== []) {
         $Header->vary('Accept-Language');
      }

      // ? HTTP/2 stream — wire serialization is frame-based (RFC 9113 §8.2).
      //   Single branch point: normal, testing and deferred (Fiber) responses
      //   all funnel through encode(), so they all serialize correctly.
      if ($Request->stream !== 0) {
         // ! HTTP/2 framing is exclusively encoder-owned. Remove every
         //   application-supplied CL/TE variant before HPACK serialization;
         //   Encoder_HTTP2 derives one content-length from DATA bytes and
         //   connection-specific Transfer-Encoding never exists in HTTP/2.
         $Header->own('Content-Length');
         $Header->own('Transfer-Encoding');

         // @ Upload queue: HTTP/2 materializes file parts into DATA payload —
         //   the raw `uploading[]` file pump would bypass framing.
         $files = [];
         if ($this->stream) {
            $files = $this->files;
            $this->files = [];
            $this->stream = false;
         }

         return Encoder_HTTP2::frame($this, $this->code, $Request, $files, $Package, $length);
      }

      // HTTP/1.0 backward compatibility (RFC 9110 §2.5)
      if ($Request->protocol === 'HTTP/1.0') {
         // Respond with HTTP/1.0 status-line for 1.0 clients
         $response = "HTTP/1.0 {$this->status}";

         // ? Chunked transfer coding does not exist in HTTP/1.0.
         $this->chunked = false;
      }

      // ! HTTP/1 framing is exclusively encoder-owned. own() removes every
      //   case variant from set/append/prepare/queue/preset sources. Buffered
      //   bodies receive their canonical Content-Length inline below; streamed
      //   files retain one canonical field calculated from the actual queue.
      if ($this->stream && ! $this->chunked) {
         $Header->own('Content-Length', (string) $this->measure());
      }
      else if ($Header->framing !== 0) {
         $Header->own('Content-Length');
      }
      if ($this->chunked) {
         $Header->own('Transfer-Encoding', 'chunked');
      }
      else if ($Header->framing !== 0) {
         // ? No framing header was sourced at all — own() would fast-return;
         //   skip its call frame + name classification on the ordinary case.
         $Header->own('Transfer-Encoding', null);
      }

      // @ Fast lane (typical case): plain buffered HTTP/1.1 response, no
      //   stream/chunked/pre-encoded body, not a HEAD request. Content-Length
      //   is derived from the body, so the cached bytes stay correct whenever
      //   status line + header block + body all match.
      if (
         $this->stream === false && $this->chunked === false && $this->encoded === false
         && $Request->method !== 'HEAD' && $Request->protocol !== 'HTTP/1.0'
      ) {
         $Header->build();

         if (
            $this->wire !== ''
            && $this->wireStatus === $this->response
            && $this->wireHeader === $Header->raw
            && $this->wireBody === $Body->raw
         ) {
            return $this->wire;
         }

         $raw = $Body->raw;
         $wire = "{$this->response}\r\n{$Header->raw}\r\nContent-Length: "
            . strlen($raw) . "\r\n\r\n{$raw}";

         $this->wireStatus = $this->response;
         $this->wireHeader = $Header->raw;
         $this->wireBody = $raw;
         $this->wire = $wire;

         return $wire;
      }

      // @ Prepare
      // ?! Content-Length inline (avoid Header->set to preserve cache)
      // ?! strlen($Body->raw) bypasses the $Body->length property hook dispatch (hot path)
      $contentLength = '';
      if (! $this->stream && ! $this->chunked) {
         $contentLength = "\r\nContent-Length: " . strlen($Body->raw);
      }

      // @ Build
      // Header
      $Header->build();
      // Body
      $response ??= $this->response;
      if ($this->stream) {
         $length = strlen($response) + 1 + strlen($Header->raw) + 5;

         $Package->uploading = $this->files;

         $this->files = [];
         $this->stream = false;
      }

      // :
      return "{$response}\r\n{$Header->raw}{$contentLength}\r\n\r\n"
         . ($Request->method === 'HEAD' ? '' : $Body->raw);
   }
}
