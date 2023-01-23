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
use Bootgly\SAPI;
use Bootgly\Web;
use Bootgly\Web\HTTP;
use Bootgly\Web\HTTP\Server;
use Bootgly\Web\HTTP\Server\_\Connections\Data;
use Bootgly\Web\HTTP\Server\Request;
use Bootgly\Web\HTTP\Server\Response\Content;
use Bootgly\Web\HTTP\Server\Response\Meta;
use Bootgly\Web\HTTP\Server\Response\Header;
use Bootgly\Web\HTTP\Server\Router;


class Response
{
   // ! HTTP
   public object $Meta;
   public object $Header;
   public object $Content;

   // * Config
   public bool $debugger;
   // * Data
   public $body;

   public ? string $source; //! move to Content->source? join with type?
   public ? string $type;   //! move to Content->type or Header->Content->type?

   public ? array $resources;
   // * Meta
   private ? string $resource;
   // @ Buffer Status
   public bool $initied = false;
   public bool $prepared;
   public bool $processed;
   public bool $sent;


   public function __construct (? array $resources = null)
   {
      // $this->Web = &$Web;

      // ! HTTP
      $this->Meta = new Meta;
      $this->Content = new Content;
      $this->Header = new Header;

      // * Config
      $this->debugger = true;
      // * Data
      $this->body = null;

      $this->source = null; // TODO rename to resource?
      $this->type = null;

      // TODO rename to sources?
      $this->resources = $resources !== null ? $resources : ['JSON', 'JSONP', 'View', 'HTML/pre'];
      // * Meta
      $this->resource = null;

      $this->initied = false;
      $this->prepared = true;
      $this->processed = true;

      $this->sent = false;
   }
   public function __get ($name)
   {
      switch ($name) {
         case 'code':
            return http_response_code();
         case 'headers':
            return $this->Header->fields;
         default:
            $this->resource = $name; // TODO $this->Resource->set($name); ???

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
            return http_response_code($value);
         case 'resource':
            $this->resource = $value;

            $this->prepared = false;
            $this->processed = false;

            $this->prepare($this->resource);

            return true;
         default:
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
               $this->prepare('views');
               $this->process($view, 'views');
            }

            return $this->render($data, $callback);
         default:
            return $this->$name(...$arguments);
      }
   }
   public function __invoke ($x = null, string $raw = '', int $status = 200)
   {
      if ($x === null && $raw) {
         $this->resource = 'raw';
         return $this->process($raw);
      }

      $this->prepare();
      return $this->process($x);
   }

   public function output (Request $Request, Response $Response, Router $Router)
   {
      try {
         $this->Content->raw = (SAPI::$Handler)($Request, $Response, $Router);
      } catch (\Throwable) {
         // $this->Content->raw = '';
         $this->Meta->status = 500; // @ 500 HTTP Server Error
      }

      // ? Response Content
      $this->Content->length = strlen($this->Content->raw);
      // ? Response Header
      $this->Header->set('Content-Length', $this->Content->length);
      // ? Response Meta
      // ...

      return <<<HTTP_RAW
      {$this->Meta->raw}
      {$this->Header->raw}

      {$this->Content->raw}
      HTTP_RAW;;
   }
   public function reset ()
   {
      $this->Meta->__construct();
      $this->Header->__construct();
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

   public function process ($data, ?string $resource = null)
   {
      if ($resource === null) {
         $resource = $this->resource;
      } else {
         $resource = strtolower($resource);
      }

      switch ($resource) {
         // @ File
         case 'views':
            $File = new File(Bootgly::$Project->path . 'views/' . $data);
            $this->body   = $File;
            $this->source = 'file';
            $this->type   = $File->extension;
            break;

         // @ Content
         case 'json':
         case 'jsonp':
            if (is_array($data)) {
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
               // TODO inject Resource with custom process() created by user
            } else {
               switch (getType($data)) { // $_Data->type
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
   public function append ($body)
   {
      $this->initied = true;
      $this->body .= $body . "\n";
   }

   private function render (? array $data = null, ? \Closure $callback = null)
   {
      $File = $this->body;

      if ($File === null) {
         return;
      }

      // Set variables
      /**
       * @var \Bootgly\Web $Web
       */
      #$Web = &$this->Web;
      $Request = &Server::$Request;
      $Response = &Server::$Response;
      $Route = &Server::$Router->Route;
      // TODO add variables dinamically according to loaded modules and loaded web classes

      #$API = &$Web->API ?? null;
      #$App = &$Web->App ?? null;

      // Output/Buffer start()
      ob_start();

      try {
         // Isolate context with anonymous static function
         (static function (string $__file__, ?array $__data__)
            use ($Web, $Request, $Response, $Route) {
            if ($__data__ !== null) {
               extract($__data__);
            }
            require $__file__;
         })($File, $data);
      } catch (\Exception $Exception) {}

      // Set $Response properties
      $this->source = 'content';
      $this->type = '';
      $this->body = ob_get_clean(); // Output/Buffer clean()->get()

      // Call callback
      if ($callback !== null && $callback instanceof \Closure) {
         $callback($this->body, $Exception);
      }

      return $this;
   }
   public function send ($body = null, ...$options): void
   {
      if ($this->processed === false) {
         $this->process($body, $this->resource);
         $body = $this->body;
      }

      if ($body === null) {
         $body = $this->body;
      }

      switch ($this->source) {
         case 'content':
            switch ($this->type) {
               case 'application/json':
               case 'json':
                  header('Content-Type: application/json', true, $this->code); // TODO move to prepare or process
                  print json_encode($body, $options[0] ?? 0);
                  break;
               case 'jsonp':
                  header('Content-Type: application/json', true, $this->code); // TODO move to prepare or process
                  print Server::$Request->queries['callback'].'('.json_encode($body).')';
                  break;

               default:
                  print $body;
            }
            break;
         case 'file':
            if ($body === false || $body === null) {
               return;
            }

            if ($body instanceof File) {
               $File = $body;
            }

            if ($File->readable === false) {
               return;
            }

            switch ($this->type) {
               case 'image/x-icon':
               case 'ico':
                  header('Content-Type: image/x-icon'); // TODO move to prepare or process
                  header('Content-Length: ' . $File->size); // TODO move to prepare or process
                  print $File->contents;
                  $this->end();
                  break;

               default: // Dynamic (PHP)
                  #$Web = &$this->Web;

                  $Request = &Server::$Request;
                  $Response = &Server::$Response;
                  $Route = &Server::$Router->Route;

                  // TODO add variables dinamically according to loaded modules and loaded web classes
                  #$API = &$this->Web->API ?? null;
                  #$App = &$this->Web->App ?? null;

                  // Isolate context with anonymous static function
                  (static function (string $__file__)
                     use ($Request, $Response, $Route) {
                     require $__file__;
                  })($File);
            }
            break;
         default: // * HTTP Status Code || (string) $body
            if ($body === null) {
               $this->end();
            }

            if (is_int($body) && $body > 99 && $body < 600) {
               $code = $body;
               $this->body = null;
               $this->code = $code;
            } else {
               print $body;
            }
      }

      $this->end();
   }
   public function upload ($content = null)
   {
      if ($content === null) {
         $content = $this->body;
      }

      if ($content instanceof File) {
         $File = $content;
      } else {
         $File = new File(Bootgly::$Project->path . $content);
      }

      if ($File->readable) {
         header('Content-Description: File Transfer');
         header('Content-Type: application/octet-stream');
         header('Content-Disposition: attachment; filename="'.$File->basename.'"');
         header('Content-Length: ' . $File->size);
         header("Cache-Control: no-cache, must-revalidate");
         header("Expires: 0");
         header('Pragma: public');

         flush();

         $File->read();

         $this->end();
      }
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
      exit($status);
   }
}
