<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;


use AllowDynamicProperties;
use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\IO\FS\File;

use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Modules\HTTP\Server\Response as Responsing;
use Bootgly\WPI\Modules\HTTP\Server\Response\Authenticable;
use Bootgly\WPI\Modules\HTTP\Server\Response\Bootable;
use Bootgly\WPI\Modules\HTTP\Server\Response\Extendable;
use Bootgly\WPI\Modules\HTTP\Server\Response\Redirectable;
use Bootgly\WPI\Modules\HTTP\Server\Response\Renderable;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Body;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Meta;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;


/**
 * * Config
 * @property int $code
 */
#[AllowDynamicProperties]
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
   protected null|int $code;
   public string $raw;
   public $body;

   public array $files;

   public ? array $resources;
   public ? string $source;
   public ? string $type;

   // * Metadata
   private ? string $resource;
   // @ Status (sets ...)
   public bool $initied = false;
   public bool $prepared;
   public bool $processed;
   public bool $sent;
   // @ State (sets)
   public bool $chunked;
   public bool $encoded;
   // @ Type (set)
   #public bool $dynamic;
   #public bool $static;
   public bool $stream;


   // / HTTP
   public Raw $Raw;
      public readonly Meta $Meta;
      public readonly Header $Header;
      public readonly Body $Body;


   public function __construct (int $code = 200, ? array $headers = null, string $body = '')
   {
      // \
      self::$Server = Server::class;

      // * Config
      // ...

      // * Data
      $this->raw = '';
      $this->body = null;

      $this->files = [];

      $this->resources = ['JSON', 'JSONP', 'View', 'HTML/pre'];
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
      #$this->static = false;
      $this->stream = false;

      // / HTTP
      $this->Raw = new Raw;
         $this->Meta = $this->Raw->Meta;
         $this->Header = $this->Raw->Header;
         $this->Body = $this->Raw->Body;

      // @
      if ($code !== 200) {
         $this->Raw->Meta->code = $code;
      }

      if ($headers !== null) {
         $this->Raw->Header->prepare($headers);
      }

      if ($body !== '') {
         $this->Raw->Body->raw = $body;
      }
   }
   public function __get (string $name)
   {
      switch ($name) {
         // ? Response Metadata
         case 'code':
            return $this->Raw->Meta->code;
         // ? Response Headers
         case 'headers':
            return $this->Raw->Header->fields;
         // ? Response Body
         case 'chunked':
            if ($this->chunked === false) {
               $this->chunked = true;
               $this->Raw->Header->append('Transfer-Encoding', 'chunked');
            }

            return $this->Raw->Body->chunked;

         default: // @ Contruct Non-Raw Response
            $this->resource = $name;

            $this->prepared = false;
            $this->processed = false;

            $this->prepare($name);

            return $this;
      }
   }
   public function __set (string $name, $value)
   {
      switch ($name) {
         // ? Response Metadata
         case 'code':
            if (\is_int($value) && $value > 99 && $value < 600) {
               $this->Raw->Meta->status = $value;
            }
            break;
      }
   }
   public function __invoke (int $code = 200, array $headers = [], string $body = '') : self
   {
      $this->Raw->Meta->code = $code;
      $this->Raw->Header->prepare($headers);
      $this->Raw->Body->raw = $body;

      return $this;
   }

   /**
    * Compresses the response body using the specified method.
    *
    * @param string $raw The raw response content.
    * @param string $method The compression method to use (gzip, deflate, or compress).
    * @param int $level The level of compression.
    * @param int|null $encoding The optional encoding type.
    *
    * @return mixed The compressed content or false on failure.
    */
   public function compress (string $raw, string $method = 'gzip', int $level = 9, ? int $encoding = null)
   {
      $encoded = false;
      $deflated = false;
      $compressed = false;

      try {
         switch ($method) {
            case 'gzip':
               $encoded = @\gzencode($raw, $level, $encoding);
               break;
            case 'deflate':
               $deflated = @\gzdeflate($raw, $level, $encoding);
               break;
            case 'compress':
               $compressed = @\gzcompress($raw, $level, $encoding);
               break;
         }
      }
      catch (\Throwable) {
         // ...
      }

      if ($encoded) {
         $this->encoded = true;
         $this->Raw->Header->set('Content-Encoding', 'gzip');
         return $encoded;
      }
      else if ($deflated) {
         $this->encoded = true;
         $this->Raw->Header->set('Content-Encoding', 'deflate');
         return $deflated;
      }
      else if ($compressed) {
         $this->encoded = true;
         $this->Raw->Header->set('Content-Encoding', 'gzip');
         return $compressed;
      }

      return false;
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
         $body = $this->body;
      }

      if ($body === null) {
         $body = $this->body;
      }

      // TODO refactor.
      switch ($this->source) {
         case 'content':
            // @ Set body/content
            switch ($this->type) {
               case 'application/json':
               case 'json':
                  // TODO move to prepare or process
                  $this->Raw->Header->set('Content-Type', 'application/json');

                  $body = \json_encode($body, $options[0] ?? 0);

                  break;
               case 'jsonp':
                  // TODO move to prepare or process
                  $this->Raw->Header->set('Content-Type', 'application/json');

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
                  $this->Raw->Header->set('Content-Type', 'image/x-icon');

                  $body = $File->contents;

                  break;

               default: // Dynamic (PHP)
                  // @ Output/Buffer start()
                  \ob_start();

                  $Request = &Server::$Request;
                  $Response = &Server::$Response;
                  $__data__ = [
                     'Request' => $Request,
                     'Response' => $Response
                  ];

                  // @ Isolate context with anonymous static function
                  (static function (string $__file__, array $__data__) {
                     \extract($__data__);
                     require $__file__;
                  })($File, $__data__);

                  $body = \ob_get_clean(); // @ Output/Buffer clean()->get()
            }

            break;
         default:
            if ($body === null) {
               $this->sent = true;

               return $this;
            }
      }

      // @ Output
      $this->Raw->Body->raw = $body ?? $this->body;

      $this->sent = true;

      return $this;
   }
   /**
    * Start a file upload from the Server to the Client
    *
    * @param string|File $file The file to be uploaded
    * @param int $offset The data offset.
    * @param int|null $length The length of the data to upload.
    * @param bool $close Close the connection after sending.
    * 
    * @return Response The Response instance, for chaining
    */
   public function upload (string|File $file, int $offset = 0, ? int $length = null, bool $close = true) : self
   {
      // ?!
      if ($file instanceof File) {
         $File = $file;
      }
      else {
         $File = new File(BOOTGLY_PROJECT?->path . Path::normalize($file));
      }

      if ($File->readable === false) {
         $this->__set('code', 403);
         return $this;
      }

      // @
      $size = $File->size;

      // @ Prepare HTTP headers
      $this->Raw->Header->prepare([
         'Last-Modified' => \gmdate('D, d M Y H:i:s \G\M\T', $File->modified),
         // Cache
         'Cache-Control' => 'no-cache, must-revalidate',
         'Expires' => '0',
      ]);

      // @ Return null Response if client Purpose === prefetch
      if (Server::$Request->Raw->Header->get('Purpose') === 'prefetch') {
         $this->Raw->Meta->status = 204;
         $this->Raw->Header->set('Cache-Control', 'no-store');
         $this->Raw->Header->set('Expires', '0');
         return $this;
      }

      $ranges = [];
      $parts = [];
      if ( $Range = Server::$Request->Raw->Header->get('Range') ) {
         // @ Parse Client range requests
         $ranges = Server::$Request->range($size, $Range);

         switch ($ranges) {
            case -2: // Malformed Range header string
               $this->end(400);
               return $this;
            case -1:
               $this->end(416, $size);
               return $this;
            default:
               $type = \array_pop($ranges);
               // @ Check Range type
               if ($type !== 'bytes') {
                  $this->end(416, $size);
                  return $this;
               }

               foreach ($ranges as $range) {
                  $start = $range['start'];
                  $end = $range['end'];

                  $offset = $start;
                  $length = 0;
                  if ($end > $start) {
                     $length += ($end - $start);
                  }
                  $length += 1;

                  $parts[] = [
                     'offset' => $offset,
                     'length' => $length
                  ];
               }
         }
      }
      else {
         // @ Set User offset / length
         $ranges[] = [
            'start' => $offset,
            'end' => $length
         ];
         $parts[] = [
            'offset' => $offset,
            'length' => $length ?? $size - $offset
         ];
      }

      // ! Header
      $rangesCount = \count($ranges);
      // @ Set Content Length Header
      if ($rangesCount === 1) {
         $this->Raw->Header->set('Content-Length', $parts[0]['length']);
      }
      // @ Set HTTP range requests Headers
      $pads = [];
      if ($ranges[0]['end'] !== null || $ranges[0]['start']) {
         // @ Set Response status
         $this->Raw->Meta->status = 206; // 206 Partial Content

         if ($rangesCount > 1) { // @ HTTP Multipart ranges
            $boundary = \str_pad(++Server::$Request::$multiparts, 20, '0', \STR_PAD_LEFT);

            $this->Raw->Header->set('Content-Type', 'multipart/byteranges; boundary=' . $boundary);

            $length = 0;
            foreach ($ranges as $index => $range) {
               $start = $range['start'];
               $end = $range['end'];

               if ($end > $size - 1) $end += 1;

               $prepend = <<<HTTP_RAW
               \r\n--$boundary
               Content-Type: application/octet-stream
               Content-Range: bytes {$start}-{$end}/{$size}\r\n\r\n
               HTTP_RAW;

               $append = null;
               if ($index === $rangesCount - 1) {
                  $append = <<<HTTP_RAW
                  \r\n--$boundary--\r\n
                  HTTP_RAW;
               }

               $length += $parts[$index]['length'];
               $length += \strlen($prepend);
               $length += \strlen($append ?? '');

               $pads[] = [
                  'prepend' => $prepend,
                  'append' => $append
               ];
            }

            $this->Raw->Header->set('Content-Length', $length);
         }
         else { // @ HTTP Single part ranges
            $start = $ranges[0]['start'];
            $end = $ranges[0]['end'];

            if ($end > $size - 1) $end += 1;

            $this->Raw->Header->set('Content-Range', "bytes {$start}-{$end}/{$size}");
         }
      }
      else {
         $this->Raw->Header->set('Accept-Ranges', 'bytes');
      }
      // @ Set Content-Disposition Header
      if ($rangesCount === 1) {
         $this->Raw->Header->set('Content-Type', 'application/octet-stream');
         $this->Raw->Header->set('Content-Disposition', 'attachment; filename="'.$File->basename.'"');
      }
      // @ Build Response Header
      #$this->Raw->Header->build();

      // @ Prepare upstream
      $this->stream = true;
      // @ Prepare writing
      $this->files[] = [
         'file' => $File->file, // @ Set file path to open handler

         'parts' => $parts,
         'pads' => $pads,

         'close' => $close
      ];

      $this->sent = true;

      return $this;
   }
   /**
    * Construct the final output to send (used by the HTTP Server Encoder)
    *
    * @param Packages $Package TCP Package associated with the response
    * @param string &$length Reference to the variable receiving the length of the response
    *
    * @return string The Response Raw to be sent
    */
   public function output (Packages $Package, ? int &$length) : string
   {
      $Meta    = &$this->Raw->Meta;
      $Body    = &$this->Raw->Body;
      $Header  = &$this->Raw->Header;

      if (! $this->stream && ! $this->chunked && ! $this->encoded) {
         // ? Response Body
         $Body->length = \strlen($Body->raw);
         // ? Response Header
         $Header->set('Content-Length', $Body->length);
         // ? Response Meta
         // ...
      }

      $Header->build();

      $this->raw = <<<HTTP_RAW
      {$Meta->raw}\r
      {$Header->raw}\r
      \r
      {$Body->raw}
      HTTP_RAW;

      if ($this->stream) {
         $length = \strlen($Meta->raw) + 1 + \strlen($Header->raw) + 5;

         $Package->uploading = $this->files;

         $this->files = [];
         $this->stream = false;
      }

      return $this->raw;
   }

   /**
    * Definitively terminates the HTTP Response.
    *
    * @param int|null $code The status code of the response.
    * @param string|null $context The context for additional information.
    *
    * @return Response Returns Response.
    */
   public function end (? int $code = null, ? string $context = null) : self
   {
      // ?
      if ($this->sent === true) {
         return $this;
      }

      // @
      if ($code) {
         // @ Preset
         switch ($code) {
            case 400: // Bad Request
            case 416: // Range Not Satisfiable
               $this->Raw->Meta->status = 416;
               // Clean prepared headers / header fields already set
               $this->Raw->Header->clean();
               $this->Raw->Body->raw = ' '; // Needs body non-empty
               break;
            default:
               $this->__set('code', $code);
         }

         // @ Contextualize
         switch ($code) {
            case 416: // Range Not Satisfiable
               if ($context) {
                  $this->Raw->Header->set('Content-Range', 'bytes */' . $context);
               }
               break;
         }
      }

      $this->sent = true;

      return $this;
   }
}
