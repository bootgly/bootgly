<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\nodes\HTTP\Server\CLI;


use Bootgly\ABI\IO\FS\File;

use Bootgly\WPI\nodes\HTTP\Server\CLI as Server;
use Bootgly\WPI\nodes\HTTP\Server\CLI\Response\Content;
use Bootgly\WPI\nodes\HTTP\Server\CLI\Response\Meta;
use Bootgly\WPI\nodes\HTTP\Server\CLI\Response\Header;


class Response
{
   // ! HTTP
   public Meta $Meta;
   public Header $Header;
   public Content $Content;

   // * Config
   public bool $debugger;

   // * Data
   public string $raw;

   public $body;
   public array $files;

   public ? string $source;
   public ? string $type;

   public ? array $resources;

   // * Meta
   private ? string $resource;
   // @ Status
   public bool $initied = false;
   public bool $prepared;
   public bool $processed;
   public bool $sent;
   // @ Type
   #public bool $static;
   public bool $chunked;
   public bool $encoded;
   public bool $stream;


   public function __construct ()
   {
      // ! HTTP
      $this->Meta = new Meta;
      $this->Content = new Content;
      $this->Header = new Header;

      // * Config
      $this->debugger = true;
      // * Data
      $this->raw = '';

      $this->body = null;
      $this->files = [];

      $this->source = null;
      $this->type = null;

      $this->resources = ['JSON', 'JSONP', 'View', 'HTML/pre'];
      // * Meta
      $this->resource = null;
      // @ Status
      $this->initied = false;
      $this->prepared = true;
      $this->processed = true;
      $this->sent = false;
      // @ Type
      #$this->static = false;
      $this->stream = false;
      $this->chunked = false;
      $this->encoded = false;
   }
   public function __get ($name)
   {
      switch ($name) {
         // ? Response Meta
         case 'status':
         case 'code':
            return $this->Meta->code;
         // ? Response Headers
         case 'headers':
            return $this->Header->fields;
         // ? Response Content
         case 'chunked':
            if (! $this->chunked) {
               $this->chunked = true;
               $this->Header->append('Transfer-Encoding', 'chunked');
            }

            return $this->Content->chunked;

         default: // @ Construct resource
            $this->resource = $name;

            $this->prepared = false;
            $this->processed = false;

            $this->prepare($this->resource);

            return $this;
      }
   }
   public function __set ($name, $value)
   {
      switch ($name) {
         case 'status':
         case 'code':
            if (is_int($value) && $value > 99 && $value < 600) {
               $this->Meta->status = $value;
            }

            return $this;
         default: // @ Set custom resource
            // $this->resources[$name] = $value; // TODO
      }
   }
   public function __call (string $name, array $arguments)
   {
      switch ($name) {
         case 'render':
            $view = null;
            $data = null;
            $callback = null;

            foreach ($arguments as $argument) {
               switch (gettype($argument)) {
                  case 'string':
                     $view = $argument;
                     break;
                  case 'array':
                     $data = $argument;
                     break;
                  case 'object':
                     $callback = $argument;
                     break;
               }
            }

            if ($view !== null) {
               $this->prepare('view');
               $this->process($view, 'view');
            }

            return $this->render($data, $callback);
         default:
            return null;
      }
   }
   public function __invoke (
      $x = null,
      ? int $status = null,
      ? array $headers = null,
      ? string $content = null
   )
   {
      if ($x === null) {
         if ($status !== null) {
            $this->code = $status;
         }

         if ($content !== null) {
            $this->Content->raw = $content;
         }

         return $this;
      }

      $this->prepare();

      return $this->process($x);
   }

   public function prepare (? string $resource = null)
   {
      if ($this->initied === false) {
         $this->body   = null;
         $this->source = null;
         $this->type   = null;
      }

      $this->initied = true;

      if ($resource === null) {
         $resource = $this->resource;
      } else {
         $resource = strtolower($resource);
      }

      switch ($resource) {
         // @ File
         case 'view':
            $this->source = 'file';
            $this->type = 'php';
            break;

         // @ Content
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
   public function append ($body)
   {
      $this->initied = true;
      $this->body .= $body . "\n";
   }

   // TODO move to resource files
   public function process ($data, ? string $resource = null)
   {
      if ($resource === null) {
         $resource = $this->resource;
      } else {
         $resource = strtolower($resource);
      }

      switch ($resource) {
         // @ File
         case 'view':
            $File = new File;
            $File->pathify(\Bootgly::$Project->path . 'views/' . $data);

            $this->body   = $File;
            $this->source = 'file';
            $this->type   = $File->extension;

            break;

         // @ Content
         case 'json':
         case 'jsonp':
            if ( is_array($data) ) {
               $this->body = $data;
               break;
            }

            $this->body = json_decode($data, true);

            break;
         case 'pre':
            if ($data === null) {
               $data = $this->body;
            }

            $this->body = '<pre>'.$data.'</pre>';

            break;
         case 'raw':
            $this->body = $data;

            break;

         default:
            if ($resource) {
               // TODO Inject resource with custom process() created by user
            } else {
               switch ( getType($data) ) { // _()->type
                  case 'string':
                     // TODO check if string is a valid path
                     $File = new File;

                     match ($data[0]) {
                        #!
                        '/' => $File->pathify(BOOTGLY_WORKING_DIR . 'projects' . $data),
                        '@' => $File->pathify(BOOTGLY_WORKING_DIR . 'projects/' . $data),
                        default => $File->pathify(\Bootgly::$Project->path . $data)
                     };

                     $this->body   = $File;
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

      $this->processed = true;

      $this->resource = null;

      return $this;
   }
   private function render (? array $data = null, ? \Closure $callback = null)
   {
      $File = $this->body;

      if ($File === null) {
         return null;
      }

      // @ Set variables
      /**
       * @var \Bootgly\WPI $WPI
       */
      $Request = &Server::$Request;
      $Response = &Server::$Response;

      // @ Output/Buffer start()
      ob_start();

      try {
         // @ Isolate context with anonymous static function
         (static function (string $__file__, ?array $__data__)
         use ($Request, $Response) {
            if ($__data__ !== null) {
               extract($__data__);
            }
            require $__file__;
         })($File, $data);
      } catch (\Exception $Exception) {}

      // @ Set $Response properties
      $this->source = 'content';
      $this->type = '';
      $this->body = ob_get_clean(); // Output/Buffer clean()->get()

      // @ Call callback
      if ($callback !== null && $callback instanceof \Closure) {
         $callback($this->body, $Exception);
      }

      return $this;
   }
   public function compress (string $raw, string $method = 'gzip', int $level = 9, ? int $encoding = null)
   {
      $encoded = false;
      $deflated = false;
      $compressed = false;

      try {
         switch ($method) {
            case 'gzip':
               $encoded = @gzencode($raw, $level, $encoding);
               break;
            case 'deflate':
               $deflated = @gzdeflate($raw, $level, $encoding);
               break;
            case 'compress':
               $compressed = @gzcompress($raw, $level, $encoding);
               break;
         }
      } catch (\Throwable) {
         // ...
      }

      if ($encoded) {
         $this->encoded = true;
         $this->Header->set('Content-Encoding', 'gzip');
         return $encoded;
      } else if ($deflated) {
         $this->encoded = true;
         $this->Header->set('Content-Encoding', 'deflate');
         return $deflated;
      } else if ($compressed) {
         $this->encoded = true;
         $this->Header->set('Content-Encoding', 'gzip');
         return $compressed;
      }

      return false;
   }

   public function send ($body = null, ...$options) : self
   {
      if ($this->processed === false) {
         $this->process($body, $this->resource);
         $body = $this->body;
      }

      if ($body === null) {
         $body = $this->body;
      }

      // TODO refactor. Use file resources.
      switch ($this->source) {
         case 'content':
            // @ Set body/content
            switch ($this->type) {
               case 'application/json':
               case 'json':
                  // TODO move to prepare or process
                  $this->Header->set('Content-Type', 'application/json');

                  $body = json_encode($body, $options[0] ?? 0);

                  break;
               case 'jsonp':
                  // TODO move to prepare or process
                  $this->Header->set('Content-Type', 'application/json');

                  $body = Server::$Request->queries['callback'].'('.json_encode($body).')';

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
                  $this->Header->set('Content-Type', 'image/x-icon');

                  $body = $File->contents;

                  break;

               default: // Dynamic (PHP)
                  // @ Output/Buffer start()
                  ob_start();

                  $Request = &Server::$Request;
                  $Response = &Server::$Response;

                  // @ Isolate context with anonymous static function
                  (static function (string $__file__)
                     use ($Request, $Response) {
                     require $__file__;
                  })($File);

                  $body = ob_get_clean(); // @ Output/Buffer clean()->get()
            }

            break;
         default:
            if ($body === null) {
               $this->sent = true;
               return $this;
            }
      }

      // @ Output
      $this->Content->raw = $body ?? $this->body;

      $this->sent = true;

      return $this;
   }
   public function upload ($content = null, int $offset = 0, ? int $length = null, bool $close = true) : self
   {
      if ($content === null) {
         $content = $this->body;
      }

      // TODO REFACTOR
      // FIX REVIEW SECURITY
      if ($content instanceof File) {
         $File = $content;
      } else {
         $File = new File;
         $File->pathify(\Bootgly::$Project->path . $content);
      }

      if ($File->readable === false) {
         $this->status = 403; // Forbidden
         return $this;
      }

      $size = $File->size;

      // @ Prepare HTTP headers
      $this->Header->prepare([
         'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', $File->modified),
         // Cache
         'Cache-Control' => 'no-cache, must-revalidate',
         'Expires' => '0',
      ]);

      // @ Return null Response if client Purpose === prefetch
      if (Server::$Request->Header->get('Purpose') === 'prefetch') {
         $this->Meta->status = 204;
         $this->Header->set('Cache-Control', 'no-store');
         $this->Header->set('Expires', '0');
         return $this;
      }

      $ranges = [];
      $parts = [];
      if ( $Range = Server::$Request->Header->get('Range') ) {
         // @ Parse Client range requests
         $ranges = Server::$Request->range($size, $Range);

         switch ($ranges) {
            case -2: // Malformed Range header string
               return $this->end(400);
            case -1:
               return $this->end(416, $size);
            default:
               $type = array_pop($ranges);
               // @ Check Range type
               if ($type !== 'bytes') {
                  return $this->end(416, $size);
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
      } else {
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
      $rangesCount = count($ranges);
      // @ Set Content Length Header
      if ($rangesCount === 1) {
         $this->Header->set('Content-Length', $parts[0]['length']);
      }
      // @ Set HTTP range requests Headers
      $pads = [];
      if ($ranges[0]['end'] !== null || $ranges[0]['start']) {
         // @ Set Response status
         $this->Meta->status = 206; // 206 Partial Content

         if ($rangesCount > 1) { // @ HTTP Multipart ranges
            $boundary = str_pad(++Server::$Request::$multiparts, 20, '0', STR_PAD_LEFT);

            $this->Header->set('Content-Type', 'multipart/byteranges; boundary=' . $boundary);

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
               $length += strlen($prepend);
               $length += strlen($append ?? '');

               $pads[] = [
                  'prepend' => $prepend,
                  'append' => $append
               ];
            }

            $this->Header->set('Content-Length', $length);
         } else { // @ HTTP Single part ranges
            $start = $ranges[0]['start'];
            $end = $ranges[0]['end'];

            if ($end > $size - 1) $end += 1;

            $this->Header->set('Content-Range', "bytes {$start}-{$end}/{$size}");
         }
      } else {
         $this->Header->set('Accept-Ranges', 'bytes');
      }
      // @ Set Content-Disposition Header
      if ($rangesCount === 1) {
         $this->Header->set('Content-Type', 'application/octet-stream');
         $this->Header->set('Content-Disposition', 'attachment; filename="'.$File->basename.'"');
      }
      // @ Build Response Header
      #$this->Header->build();

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
   public function output ($Package, &$length)
   {
      $Meta    = &$this->Meta;
      $Content = &$this->Content;
      $Header  = &$this->Header;

      if (! $this->stream && ! $this->chunked && ! $this->encoded) {
         // ? Response Content
         $Content->length = strlen($Content->raw);
         // ? Response Header
         $Header->set('Content-Length', $Content->length);
         // ? Response Meta
         // ...
      }

      $Header->build();

      $this->raw = <<<HTTP_RAW
      {$Meta->raw}\r
      {$Header->raw}\r
      \r
      {$Content->raw}
      HTTP_RAW;

      if ($this->stream) {
         $length = strlen($Meta->raw) + 1 + strlen($Header->raw) + 5;

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
    * @return self Returns Response.
    */
   public function authenticate (string $realm = 'Protected area') : self
   {
      $this->code = 401;
      $this->Header->set('WWW-Authenticate', 'Basic realm="'.$realm.'"');
      $this->sent = true;

      return $this;
   }
   /**
    * Redirects to a new URI. Default return is 307 for GET (Temporary Redirect) and 303 (See Other) for POST.
    *
    * @param string $uri The new URI to redirect to.
    * @param int $code The HTTP status code to use for the redirection.
    *
    *
    * @return self Returns Response.
    */
   public function redirect (string $uri, ? int $code = null): self
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

      $this->Header->set('Location', $uri);
      $this->sent = true;

      return $this;
   }

   public function end (int|string|null $status = null, ? string $context = null) : self
   {
      if ($status) {
         // @ Preset
         switch ($status) {
            case 400: // Bad Request
            case 416: // Range Not Satisfiable
               $this->Meta->status = 416;
               // Clean prepared headers / header fields already set
               $this->Header->clean();
               $this->Content->raw = ' '; // Needs body non-empty
               break;
            default:
               $this->status = $status;
         }

         // @ Contextualize
         switch ($status) {
            case 416: // Range Not Satisfiable
               if ($context) {
                  $this->Header->set('Content-Range', 'bytes */' . $context);
               }
               break;
         }
      }

      $this->sent = true;

      return $this;
   }
}
