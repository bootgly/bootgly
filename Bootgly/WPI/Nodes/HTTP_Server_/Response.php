<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_;


use function extract;
use function flush;
use function ob_start;
use function ob_get_clean;
use function http_response_code;

use const BOOTGLY_PROJECT;
use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\IO\FS\File;

use const Bootgly\WPI;
use Bootgly\WPI\Modules\HTTP\Server;
use Bootgly\WPI\Nodes\HTTP_Server_\Response\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_\Response\Raw\Body;


class Response extends Server\Response
{
   // * Config
   // ...

   // * Data
   // ..
   // # Resource
   // @ Content
   public ?string $source;
   public ?string $type;

   // * Metadata
   // ..
   // @ State (sets)
   public bool $chunked;
   public bool $encoded;
   // @ Type
   #public bool $dynamic;
   #public bool $static;
   public bool $stream;
   // # Resource
   // @ Content
   private ?string $resource;
   // @ Status
   public bool $initied = false;
   public bool $prepared;
   public bool $processed;
   public bool $sent;

   // / HTTP
   public readonly Header $Header;
   public readonly Body $Body;

   /**
    * Construct a new Response instance.
    *
    * @param int $code The status code of the response.
    * @param array<string>|null $headers The headers of the response.
    * @param string $body The body of the response.
    */
   public function __construct (int $code = 200, ? array $headers = null, string $body = '')
   {
      // * Config
      // ...

      // * Data
      $this->files = [];
      // # Resource
      $this->source = null;
      $this->type = null;

      // * Metadata
      // @ State
      $this->chunked = false;
      $this->encoded = false;
      // @ Type
      #$this->dynamic = false;
      #$this->static = true;
      $this->stream = false;
      // # Resource
      $this->resource = null;
      // @ Status
      $this->initied = false;
      $this->prepared = true;
      $this->processed = true;
      $this->sent = false;

      // / HTTP
      $this->Header = new Header;
      $this->Body = new Body;

      // @
      if ($code !== 200) {
         $this->code($code);
      }
      if ($headers !== null) {
         $this->Header->prepare($headers);
      }
      if ($body !== '') {
         $this->Body->raw = $body;
      }
   }
   /**
    * Get the specified property from the Response or Response Resource.
    *
    * @param string $name The name of the property or Response Resource to get.
    *
    * @return bool|string|int|array<mixed>|self The value of the property or the Response instance, for chaining.
    */
   public function __get (string $name): bool|string|int|array|self
   {
      switch ($name) {
         // TODO: move to property hooks
         // # Response Metadata
         case 'code':
            return $this->code = http_response_code();
         // # Response Headers
         case 'headers':
            return $this->Header->fields;
         // # Response Body
         case 'chunked':
            if (! $this->chunked) {
               $this->chunked = true;
               $this->Header->append('Transfer-Encoding', 'chunked');
            }

            return $this->Body->chunked;

         default: // @ Construct resource
            $this->resource = $name;

            $this->prepared = false;
            $this->processed = false;

            $this->prepare($this->resource);

            return $this;
      }
   }
   public function __set (string $name, mixed $value): void
   {
      switch ($name) {
         // ? Response Metadata
         case 'code':
            $this->code = http_response_code($value);
            break;
      }
   }
   /**
    * Prepare the response for sending.
    *
    * @param int $code The status code of the response.
    * @param array<string> $headers The headers of the response.
    * @param string $body The body of the response.
    *
    * @return self The Response instance, for chaining 
    */
   public function __invoke (int $code = 200, array $headers = [], string $body = ''): self
   {
      $this->code($code);
      $this->Header->prepare($headers);
      $this->Body->raw = $body;

      return $this;
   }

   /**
    * Set the HTTP Server Response code.
    *
    * @param int $code 
    *
    * @return self The Response instance, for chaining 
    */
   public function code (int $code): self
   {
      $this->code = http_response_code($code);

      return $this;
   }
   /**
    * Send the response
    *
    * @param mixed|null $body The body of the response.
    * @param mixed ...$options Additional options for the response
    *
    * @return Response The Response instance, for chaining
    */
   public function send ($body = null, ...$options): self
   {
      // ?
      if ($this->sent === true) {
         return $this;
      }
      if ($this->processed === false) {
         $this->process($body, $this->resource);
      }

      // TODO move to Resource
      switch ($this->source) {
         case 'content':
            // @ Set body/content
            switch ($this->type) {
               case 'application/json':
               case 'json':
                  // TODO move to prepare or process
                  $this->Header->set('Content-Type', 'application/json');

                  $body = \json_encode($body, $options[0] ?? 0);

                  break;
               case 'jsonp':
                  // TODO move to prepare or process
                  $this->Header->set('Content-Type', 'application/json');

                  $body = WPI->Request->queries['callback'].'('.\json_encode($body).')';

                  break;
            }

            break;
         case 'file':
            if ($body === false || $body === null || $body instanceof File === false) {
               return $this;
            }

            $File = $body;

            if ($File->readable === false) {
               return $this;
            }

            // @ Set body/content
            switch ($this->type) {
               case 'image/x-icon':
               case 'ico':
                  $this->Header->set('Content-Type', 'image/x-icon');
                  $this->Header->set('Content-Length', (string) $File->size);

                  $body = $File->contents;

                  break;

               default: // Dynamic (PHP)
                  // @ Set variables
                  $data = [
                     'Request' => WPI->Request,
                     'Response' => WPI->Response,
                     'Route' => WPI->Router->Route,
                  ];

                  // @ Extend variables
                  $data = $data + $this->uses;

                  // @ Output/Buffer start()
                  ob_start();
                  // @ Isolate context with anonymous static function
                  (static function (string $__file__, array $__data__) {
                     extract($__data__);
                     require $__file__;
                  })($File, $data);
                  // @ Output/Buffer clean()->get()
                  $body = ob_get_clean();
            }

            break;
         default:
            if ($body === null) {
               $this->sent = true;

               return $this;
            }
      }

      // @ Output
      echo $body;

      $this->sent = true;

      return $this;
   }

   /**
    * Start a file upload from the Server to the Client
    *
    * @param string|File $file The file to be uploaded
    * @param int $offset The data offset.
    * @param int|null $length The length of the data to upload.
    * 
    * @return Response The Response instance, for chaining
    */
   public function upload (string|File $file, int $offset = 0, ? int $length = null): self
   {
      // ?!
      if ($file instanceof File) {
         $File = $file;
      }
      else {
         /**
          * @var ?\Bootgly\API\Project $Project
         */
         $Project = BOOTGLY_PROJECT;
         if ($Project === null) {
            $this->code(500); // Internal Server Error
            return $this;
         }
         $File = new File($Project->path . Path::normalize($file));
      }

      if ($File->readable === false) {
         $this->code(403); // Forbidden
         return $this;
      }

      // @ Set HTTP headers
      $this->Header->prepare([
         'Content-Type' => 'application/octet-stream',
         'Content-Disposition' => 'attachment; filename="'.$File->basename.'"',

         'Last-Modified' => gmdate('D, d M Y H:i:s', $File->modified) . ' GMT',
         // Cache
         'Cache-Control' => 'no-cache, must-revalidate',
         'Expires' => '0',
      ]);

      // @ Send File Content
      $this->Header->set('Content-Length', (string) $File->size);
      $this->Header->build();
      $this->Header->send();

      // @ Flush HTTP Headers
      flush();

      // @ Send File Content
      $File->open();
      while ($File->EOF === false) {
         echo $File->read(
            method: $File::DEFAULT_READ_METHOD,
            offset: $offset,
            length: $length ?? 1024
         );
         flush();
      }

      $this->end();

      return $this;
   }

   /**
    * Definitively terminates the HTTP Response.
    *
    * @param int|null $code The status code of the response.
    *
    * @return void
    */
   public function end (? int $code = null): void
   {
      // ?
      if ($this->sent === true) {
         return;
      }

      // @
      $this->code($code);

      $this->sent = true;

      exit($code);
   }
}
