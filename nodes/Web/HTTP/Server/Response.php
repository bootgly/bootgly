<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server;


use const Bootgly\HOME_DIR;
use Bootgly\File;
use Bootgly\Bootgly;
use Bootgly\Web\HTTP\Server;
use Bootgly\Web\HTTP\Server\Response\Content;
use Bootgly\Web\HTTP\Server\Response\Meta;
use Bootgly\Web\HTTP\Server\Response\Header;


class Response
{
   // ! HTTP
   public Meta $Meta;
   public Header $Header;
   public Content $Content;

   // * Config
   public bool $debugger;
   public bool $stream;
   public bool $close;
   // * Data
   public string $raw;

   public $body;
   public array $files;

   public ? string $source; //! move to Content->source? join with type?
   public ? string $type;   //! move to Content->type or Header->Content->type?

   public ? array $resources;
   // * Meta
   private ? string $resource;
   // @ Status
   public bool $initied = false;
   public bool $prepared;
   public bool $processed;
   public bool $sent;


   public function __construct (? array $resources = null)
   {
      // ! HTTP
      $this->Meta = new Meta;
      $this->Content = new Content;
      $this->Header = new Header;

      // * Config
      $this->debugger = true;
      $this->stream = false;
      $this->close = false;
      // * Data
      $this->raw = '';

      $this->body = null;
      $this->files = [];

      $this->source = null; // TODO rename to resource?
      $this->type = null;

      // TODO rename to sources?
      $this->resources = $resources !== null ? $resources : ['JSON', 'JSONP', 'View', 'HTML/pre'];
      // * Meta
      $this->resource = null;
      // @ Buffer Status
      $this->initied = false;
      $this->prepared = true;
      $this->processed = true;
      $this->sent = false;
   }
   public function __get ($name)
   {
      switch ($name) {
         case 'code':
            return \PHP_SAPI !== 'cli' ? http_response_code() : $this->Meta->code;
         case 'headers':
            return $this->Header->fields;
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
         case 'code':
            return \PHP_SAPI !== 'cli' ? http_response_code($value) : $this->Meta->status = $value;
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
            return $this->$name(...$arguments);
      }
   }
   public function __invoke
   ($x = null, ? int $status = 200, ? array $headers = [], ? string $content = '', ? string $raw = '')
   {
      if ($x === null && $raw && PHP_SAPI === 'cli') {
         $this->raw = $raw;

         return $this;
      } else if ($x === null && $content && PHP_SAPI === 'cli') {
         $this->Content->raw = $content;

         return $this;
      }

      $this->prepare();

      return $this->process($x);
   }

   public function reset ()
   {
      $this->raw = '';

      $this->Meta->__construct();
      $this->Header->__construct();
      #$this->Content->__construct();
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

   public function process ($data, ?string $resource = null)
   {
      if ($resource === null) {
         $resource = $this->resource;
      } else {
         $resource = strtolower($resource);
      }

      switch ($resource) {
         // @ File
         case 'view':
            $File = new File(Bootgly::$Project->path . 'views/' . $data);
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
                     if ($data[0] === '/') {
                        $File = new File(HOME_DIR . 'projects' . $data);
                     } else if ($data[0] === '@') {
                        $File = new File(HOME_DIR . 'projects/' . $data);
                     } else {
                        $File = new File(Bootgly::$Project->path . $data);
                     }

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
         return;
      }

      // @ Set variables
      /**
       * @var \Bootgly\Web $Web
       */
      $Request = &Server::$Request;
      $Response = &Server::$Response;
      $Route = &Server::$Router->Route;
      // TODO add variables dinamically according to loaded modules and loaded web classes

      $API = Server::$Web->API ?? null;
      $App = Server::$Web->App ?? null;

      // @ Output/Buffer start()
      ob_start();

      try {
         // @ Isolate context with anonymous static function
         (static function (string $__file__, ?array $__data__)
            use ($Request, $Response, $Route, $API, $App) {
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
   public function compress (string $raw, string $method = 'gzip', int $level = 9)
   {
      $encoded = false;
      $deflated = false;

      switch ($method) {
         case 'gzip':
            $encoded = gzencode($raw, $level);
            break;
         case 'deflate':
            $deflated = gzdeflate($raw, $level);
            break;
      }

      if ($encoded) {
         $this->Header->set('Content-Encoding', 'gzip');
         return $encoded;
      } else if ($deflated) {
         $this->Header->set('Content-Encoding', 'deflate');
         return $deflated;
      }

      return false;
   }

   public function send ($body = null, ...$options): self
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

                  if (\PHP_SAPI !== 'cli') {
                     $this->Header->set('Content-Length', $File->size);
                  }

                  $body = $File->contents;

                  break;

               default: // Dynamic (PHP)
                  // @ Output/Buffer start()
                  ob_start();

                  $Request = &Server::$Request;
                  $Response = &Server::$Response;
                  $Route = &Server::$Router->Route;

                  // TODO add variables dinamically according to loaded modules and loaded web classes
                  $API = Server::$Web->API ?? null;
                  $App = Server::$Web->App ?? null;

                  // @ Isolate context with anonymous static function
                  (static function (string $__file__)
                     use ($Request, $Response, $Route, $API, $App) {
                     require $__file__;
                  })($File);

                  $body = ob_get_clean(); // @ Output/Buffer clean()->get()
            }

            break;
         default: // * HTTP Status Code || (string) $body
            if ($body === null) {
               $this->end();
               return $this;
            }

            if (is_int($body) && $body > 99 && $body < 600) {
               $code = $body;

               $body = '';
               $this->body = '';

               $this->code = $code;
            }
      }

      // @ Output
      if (\PHP_SAPI !== 'cli') {
         print $body ?? $this->body;
      } else {
         $this->Content->raw = $body ?? $this->body;
         $this->output(null, null);
      }

      $this->end();

      return $this;
   }
   public function upload ($content = null, int $offset = 0, ? int $length = null, string $description = '') : self
   {
      // TODO support to upload multiple files

      if ($content === null) {
         $content = $this->body;
      }

      if ($content instanceof File) {
         $File = $content;
      } else {
         $File = new File(Bootgly::$Project->path . $content);
      }

      if ($File->readable) {
         // @ Set HTTP headers
         $this->Header->set('Content-Type', 'application/octet-stream');
         $this->Header->set('Content-Disposition', 'attachment; filename="'.$File->basename.'"');
         $this->Header->set('Content-Description', $description ? $description : 'File Transfer');
         $this->Header->set('Cache-Control', 'no-cache, must-revalidate');
         $this->Header->set('Expires', '0');
         $this->Header->set('Pragma', 'public');

         // @ Send File Content
         if (\PHP_SAPI !== 'cli') {
            // TODO refactor
            $this->Header->set('Content-Length', $File->size);
            flush();
            $File->read(); // ! FIX MEMORY RAM USAGE OR LIMIT FILE SIZE TO UPLOAD
         } else {
            if ($File->size <= 2 * 1024 * 1024) {
               $this->Content->raw = $File->read($File::CONTENTS_READ_METHOD);
               return $this;
            }

            // @ Stream if the file is larger than 2 MB
            $this->stream = true;

            // @ Set Request range
            // TODO move to Request
            $range = [];
            if ( $Range = Server::$Request->Header->get('Range') ) {
               $range = Server::$Request->range($File->size, $Range);

               switch ($range) {
                  case -2: // Malformed Range header string
                     $this->Meta->status = 400; // Bad Request
                     $this->Header->clean();
                     return $this;
                  case -1:
                     $this->Meta->status = 416; // Range Not Satisfiable
                     $this->Header->clean();
                     return $this;
                  default:
                     if ($range['type'] !== 'bytes') {
                        $this->Meta->status = 416; // Range Not Satisfiable
                        $this->Header->clean();
                        return $this;
                     }

                     // TODO support multiple ranges
                     // TODO support negative ranges

                     $range = $range[0];
                     $offset = $range['start'];
                     $length = $range['end'];
               }
            }

            #var_dump($length, $offset);
            // @ Set Content Length
            $this->Content->length = ($length > 0) ? ($length) : ($File->size - $offset);
            $this->Header->set('Content-Length', $this->Content->length);

            // @ Set User range
            if ( empty($range) ) {
               $range['start'] = $offset;
               $range['end'] = $this->Content->length;
            }

            // @ Set (HTTP/1.1): Range Requests Headers
            if ($offset || $length) {
               $this->Header->set('Accept-Ranges', 'bytes');
               $this->Header->set('Content-Range', "bytes {$range['start']}-{$range['end']}/{$File->size}");

               if ($this->Content->length !== $File->size)
                  $this->Meta->status = 206; // 206 Partial Content
            }

            #var_dump($this->Content->length, $range);

            // @ Prepare Response files
            $this->files[] = [
               'file' => $File->File, // @ Set file path to open handler
               'offset' => $range['start'],
               'length' => $range['end']
            ];
         }

         $this->end();
      }

      return $this;
   }
   public function output ($Package, &$length)
   {
      if ($this->Content->raw) {
         // ? Response Content
         $this->Content->length = strlen($this->Content->raw);
         // ? Response Header
         $this->Header->set('Content-Length', $this->Content->length);
         // ? Response Meta
         // ...
      }

      $this->raw = <<<HTTP_RAW
      {$this->Meta->raw}
      {$this->Header->raw}

      {$this->Content->raw}
      HTTP_RAW;

      if ($this->stream) {
         // TODO simplify
         $length = strlen($this->Meta->raw) + strlen($this->Header->raw) + strlen("\r\n\r\n");

         $Package->handlers = $this->files;
      }

      return $this->raw;
   }

   public function redirect (string $uri, $code = 302) // Code 302 = temporary; 301 = permanent;
   {
      // $this->code = $code;
      header('Location: '.$uri, true, $code);
      $this->end();
   }

   public function authenticate (string $realm = 'Protected area')
   {
      if (Server::$Request->headers['x-requested-with'] !== 'XMLHttpRequest') {
         header('WWW-Authenticate: Basic realm="'.$realm.'"');
      }

      $this->code = 401;
      // header('HTTP/1.0 401 Unauthorized');
   }

   public function end ($status = null)
   {
      $this->sent = true;

      if (\PHP_SAPI !== 'cli') {
         exit($status);
      }
   }
}
