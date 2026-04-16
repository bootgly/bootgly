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

      // HTTP/1.0 backward compatibility (RFC 9110 §2.5)
      $Request = Server::$Request;
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
