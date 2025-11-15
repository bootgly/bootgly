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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Body;


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
   public function encode (Packages $Package, ?int &$length): string
   {
      $Header  = &$this->Header;
      $Body = &$this->Body;

      if (! $this->stream && ! $this->chunked && ! $this->encoded) {
         $Header->set('Content-Length', (string) $Body->length);
      }

      $Header->build();

      if ($this->stream) {
         $length = strlen($this->response) + 1 + strlen($Header->raw) + 5;

         /** @var array<int, array<string, mixed>> $uploadQueue */
         $uploadQueue = $this->files;
         $Package->uploading = $uploadQueue;

         $this->files = [];
         $this->stream = false;
      }

      return <<<HTTP_RAW
      {$this->response}\r
      {$Header->raw}\r
      \r
      {$Body->raw}
      HTTP_RAW;
   }
}
