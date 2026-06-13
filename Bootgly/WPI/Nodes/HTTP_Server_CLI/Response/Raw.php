<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


use function strlen;

use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
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

      // HTTP/1.0 backward compatibility (RFC 9110 §2.5)
      if ($Request->protocol === 'HTTP/1.0') {
         // Respond with HTTP/1.0 status-line for 1.0 clients
         $response = "HTTP/1.0 {$this->status}";

         // ? Disable chunked Transfer-Encoding for HTTP/1.0 responses
         if ($this->chunked) {
            $this->chunked = false;
            $Header->remove('Transfer-Encoding');
         }
      }

      // @ Prepare
      // ?! Content-Length inline (avoid Header->set to preserve cache)
      // ?! strlen($Body->raw) bypasses the $Body->length property hook dispatch (hot path)
      $contentLength = '';
      if (! $this->stream && ! $this->chunked && ! $this->encoded) {
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
