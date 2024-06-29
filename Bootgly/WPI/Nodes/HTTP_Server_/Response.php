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


use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\IO\FS\File;

use Bootgly\WPI\Modules\HTTP\Server\Response as Responsing;
use Bootgly\WPI\Modules\HTTP\Server\Response\Authenticable;
use Bootgly\WPI\Modules\HTTP\Server\Response\Bootable;
use Bootgly\WPI\Modules\HTTP\Server\Response\Extendable;
use Bootgly\WPI\Modules\HTTP\Server\Response\Redirectable;
use Bootgly\WPI\Modules\HTTP\Server\Response\Renderable;
use Bootgly\WPI\Nodes\HTTP_Server_ as Server;
use Bootgly\WPI\Nodes\HTTP_Server_\Response\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_\Response\Raw\Payload;


class Response extends Responsing
{
   use Authenticable;
   use Bootable;
   use Extendable;
   use Redirectable;
   use Renderable;


   // \
   private static $Server;

   // * Config
   // ...

   // * Data
   // @ Content
   public ? string $source;
   public ? string $type;

   // * Metadata
   // @ Content
   private ? string $resource;
   // @ Status
   public bool $initied = false;
   public bool $prepared;
   public bool $processed;
   public bool $sent;
   // @ State (sets)
   public bool $chunked;
   public bool $encoded;
   // @ Type
   #public bool $dynamic;
   #public bool $static;
   public bool $stream;

   // / HTTP
   // public readonly Raw $Raw;
   public readonly Header $Header;
   public readonly Payload $Payload;


   public function __construct (int $code = 200, ? array $headers = null, string $body = '')
   {
      // \
      self::$Server = Server::class;

      // * Config
      // ...

      // * Data
      $this->files = [];

      $this->source = null;
      $this->type = null;

      // * Metadata
      $this->resource = null;
      // @ Status
      $this->initied = false;
      $this->prepared = true;
      $this->processed = true;
      $this->sent = false;
      // @ State
      $this->chunked = false;
      $this->encoded = false;
      // @ Type
      #$this->dynamic = false;
      #$this->static = true;
      $this->stream = false;

      // / HTTP
      // $this->Raw = new Raw;
      $this->Header = new Header;
      $this->Payload = new Payload;

      // @
      if ($code !== 200) {
         $this->code($code);
      }
      if ($headers !== null) {
         $this->Header->prepare($headers);
      }
      if ($body !== '') {
         $this->Payload->raw = $body;
      }
   }
   public function __get (string $name)
   {
      switch ($name) {
         // ? Response Metadata
         case 'code':
            return $this->code = \http_response_code();
         // ? Response Headers
         case 'headers':
            return $this->Header->fields;
         // ? Response Body
         case 'chunked':
            if (! $this->chunked) {
               $this->chunked = true;
               $this->Header->append('Transfer-Encoding', 'chunked');
            }

            return $this->Payload->chunked;

         default: // @ Construct resource
            $this->resource = $name;

            $this->prepared = false;
            $this->processed = false;

            $this->prepare($this->resource);

            return $this;
      }
   }
   public function __set (string $name, $value)
   {
      switch ($name) {
         // ? Response Metadata
         case 'code':
            $this->code = \http_response_code($value);
            break;
      }
   }
   public function __invoke (int $code = 200, array $headers = [], string $body = '') : self
   {
      $this->code($code);
      $this->Header->prepare($headers);
      $this->Payload->raw = $body;

      return $this;
   }
   /**
    * Set the HTTP Server Response code.
    *
    * @param int $code 
    *
    * @return self The Response instance, for chaining 
    */
   #[\Override]
   public function code (int $code): self
   {
      $this->code = \http_response_code($code);

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
   public function send ($body = null, ...$options) : self
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

                  $body = Server::$Request->queries['callback'].'('.\json_encode($body).')';

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
                  $Request = &Server::$Request;
                  $Response = &Server::$Response;
                  $Route = &Server::$Router->Route;
                  $data = [
                     'Request' => $Request,
                     'Response' => $Response,
                     'Route' => $Route,
                  ];

                  // @ Extend variables
                  $data = $data + $this->uses;

                  // @ Output/Buffer start()
                  \ob_start();
                  // @ Isolate context with anonymous static function
                  (static function (string $__file__, array $__data__) {
                     \extract($__data__);
                     require $__file__;
                  })($File, $data);
                  // @ Output/Buffer clean()->get()
                  $body = \ob_get_clean();
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
   public function upload (string|File $file, int $offset = 0, ? int $length = null) : self
   {
      // ?!
      if ($file instanceof File) {
         $File = $file;
      }
      else {
         /**
          * @var ?\Bootgly\API\Project $Project
         */
         $Project = \BOOTGLY_PROJECT;
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
      \flush();

      // @ Send File Content
      $File->open();
      while ($File->EOF === false) {
         echo $File->read(
            method: $File::DEFAULT_READ_METHOD,
            offset: $offset,
            length: $length ?? 1024
         );
         \flush();
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
   public function end (? int $code = null) : void
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
