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


use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\IO\FS\File;

use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Modules\HTTP\Server\Response as Responsing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw;


class Response implements Responsing
{
   // ! HTTP
   public Raw $Raw;

   // * Config
   // ...

   // * Data
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


   public function __construct (int $code = 200, ? array $headers = null, string $body = '')
   {
      // ! HTTP
      $this->Raw = new Raw;

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
         // ? Response Meta
         case 'status':
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
         // ? Response Meta
         case 'status':
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

   protected function prepare (? string $resource = null) : self
   {
      if ($this->initied === false) {
         $this->body   = null;
         $this->source = null;
         $this->type   = null;
      }

      $this->initied = true;

      if ($resource === null) {
         $resource = $this->resource;
      }
      else {
         $resource = \strtolower($resource);
      }

      switch ($resource) {
         // @ Resource File
         case 'view':
            $this->source = 'file';
            $this->type = 'php';
            break;

         // @ Resource Content
         case 'json':
            $this->source   = 'content';
            $this->type     = 'json';
            break;
         case 'jsonp':
            $this->source   = 'content';
            $this->type     = 'jsonp';
            break;
         case 'pre':
         case 'raw':
            $this->source   = 'content';
            $this->type     = '';
            break;

         default:
            if ($resource) {
               // TODO inject Resource with custom prepare()
               // $prepared = $this->resources[$resource]->prepare();
               // $this->source = $prepared['source'];
               // $this->type = $prepared['type'];
            }
      }

      $this->prepared = true;

      return $this;
   }
   /**
    * Appends the provided data to the body of the response.
    *
    * @param mixed $body The data that should be appended to the response body.
    *
    * @return Response The Response instance, for chaining
    */
   public function append ($body) : self
   {
      $this->initied = true;
      $this->body .= $body . "\n";

      return $this;
   }

   // TODO move to resource files
   protected function process ($data, ? string $resource = null) : self
   {
      if ($resource === null) {
         $resource = $this->resource;
      }
      else {
         $resource = \strtolower($resource);
      }

      switch ($resource) {
         // @ Response Content
         case 'json':
         case 'jsonp':
            if ( \is_array($data) ) {
               $this->body = $data;
               break;
            }

            $this->body = \json_decode($data, true);

            break;
         case 'pre':
            if ($data === null) {
               $data = $this->body;
            }

            $this->body = '<pre>'.$data.'</pre>';

            break;

         // @ Response File
         case 'view':
            $File = new File(BOOTGLY_PROJECT?->path . 'views/' . $data);

            $this->body   = $File;
            $this->source = 'file';
            $this->type   = $File->extension;

            break;

         // @ Response Raw
         case 'raw':
            $this->body = $data;

            break;

         default:
            if ($resource) {
               // TODO Inject resource with custom process() created by user
            }
            else {
               switch ( \getType($data) ) { // _()->type
                  case 'string':
                     // TODO check if string is a valid path
                     $File = match ($data[0]) {
                        #!
                        '/' => new File(BOOTGLY_WORKING_DIR . 'projects' . $data),
                        '@' => new File(BOOTGLY_WORKING_DIR . 'projects/' . $data),
                        default => new File(BOOTGLY_PROJECT?->path . $data)
                     };

                     $this->body   = &$File;
                     $this->source = 'file';
                     $this->type   = $File->extension;

                     break;
                  case 'object':
                     if ($data instanceof File) {
                        $File = $data;

                        $this->body   = $File;
                        $this->source = 'file';
                        $this->type   = $File->extension;
                     }

                     break;
               }
            }
      }

      $this->resource = null;

      $this->processed = true;

      return $this;
   }
   /**
    * Renders the specified view with the provided data.
    *
    * @param string $view The view to render.
    * @param array|null $data The data to provide to the view.
    * @param Closure|null $callback Optional callback.
    *
    * @return Response Returns Response
    */
   public function render (string $view, ? array $data = null, ? \Closure $callback = null) : self
   {
      // !
      $this->prepare('view');
      $this->process($view . '.template.php', 'view');

      // ?
      $File = $this->body;
      if ($File === null || $File->exists === false) {
         throw new \Exception(message: 'Template file not found!');
         return $this;
      }

      // @
      // @ Set variables
      $Request = &Server::$Request;
      $Response = &Server::$Response;

      // @ Output/Buffer start()
      \ob_start();

      try {
         // @ Isolate context with anonymous static function
         (static function (string $__file__, ? array $__data__)
         use ($Request, $Response) {
            if ($__data__ !== null) {
               \extract($__data__);
            }

            require $__file__;
         })($File, $data);
      }
      catch (\Throwable $Throwable) {
         Throwables::report($Throwable);
      }

      // @ Set $Response properties
      $this->source = 'content';
      $this->type = '';
      // @ Output/Buffer clean()->get()
      $this->body = \ob_get_clean();

      // @ Call callback
      if ($callback !== null && $callback instanceof \Closure) {
         $callback($this->body, $Throwable);
      }

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
      if ($this->processed === false) {
         $this->process($body, $this->resource);
         $body = $this->body;
      }

      if ($body === null) {
         $body = $this->body;
      }

      // TODO refactor. Use Content.
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
            if ($body === false || $body === null) {
               return $this;
            }

            if ($body instanceof File) {
               $File = $body;
            }

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

                  // @ Isolate context with anonymous static function
                  (static function (string $__file__)
                     use ($Request, $Response) {
                     require $__file__;
                  })($File);

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
      if ($file instanceof File) {
         $File = $file;
      }
      else {
         $File = new File(BOOTGLY_PROJECT?->path . Path::normalize($file));
      }

      if ($File->readable === false) {
         $this->status = 403; // Forbidden
         return $this;
      }

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
   public function output (Packages $Package, ? string &$length) : string
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
    * Sets the authentication headers for basic authentication with 401 (Unauthorized) HTTP status code.
    *
    * @param string $realm The realm string to set in the WWW-Authenticate header. Default is "Protected area".
    *
    * @return Response Returns Response.
    */
   public function authenticate (string $realm = 'Protected area') : self
   {
      $this->code = 401;
      $this->Raw->Header->set('WWW-Authenticate', 'Basic realm="'.$realm.'"');
      $this->sent = true;

      return $this;
   }
   /**
    * Redirects to a new URI. Default return is 307 for GET (Temporary Redirect) and 303 (See Other) for POST.
    *
    * @param string $URI The new URI to redirect to.
    * @param ? int $code The HTTP status code to use for the redirection.
    *
    * @return Response Returns Response.
    */
   public function redirect (string $URI, ? int $code = null) : self
   {
      // @ Set default code
      if ($code === null) {
         $code = match (Server::$Request->method) {
            'POST' => 303, // See Other
            'GET'  => 307, // Temporary Redirect
            default => null
         };
      }

      switch ($code) {
         case 300: // Multiple Choices
         case 301: // Moved Permanently
         case 302: // Found (or Moved Temporarily)
         case 303: // See Other
         case 307: // Temporary Redirect
         case 308: // Permanent Redirect
            $this->code = $code;
            break;
         default:
            // TODO throw invalid code;
            return $this;
      }

      $this->Raw->Header->set('Location', $URI);

      $this->end();

      return $this;
   }

   /**
    * Definitively terminates the HTTP Response.
    *
    * @param int|string|null $status The status of the response.
    * @param string|null $context The context for additional information.
    *
    * @return void
    */
   public function end (int|string|null $status = null, ? string $context = null) : void
   {
      if ($this->sent) {
         return;
      }

      // @
      if ($status) {
         // @ Preset
         switch ($status) {
            case 400: // Bad Request
            case 416: // Range Not Satisfiable
               $this->Raw->Meta->status = 416;
               // Clean prepared headers / header fields already set
               $this->Raw->Header->clean();
               $this->Raw->Body->raw = ' '; // Needs body non-empty
               break;
            default:
               $this->status = $status;
         }

         // @ Contextualize
         switch ($status) {
            case 416: // Range Not Satisfiable
               if ($context) {
                  $this->Raw->Header->set('Content-Range', 'bytes */' . $context);
               }
               break;
         }
      }

      $this->sent = true;
   }
}
