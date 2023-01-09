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


use Bootgly\Web;
use Bootgly\Web\HTTP;

use Bootgly\File;
use const Bootgly\HOME_DIR;


class Response
{
   public Web $Web;
   public HTTP\Server $Server;

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


   public function __construct (Web $Web, HTTP\Server $Server, ? array $resources = null)
   {
      $this->Web = &$Web;
      $this->Server = &$Server;

      // ! HTTP
      // TODO move to file class
      $this->Meta = new class ()
      {
         private const PHRASES = [
            100 => 'Continue',
            101 => 'Switching Protocols',
            102 => 'Processing',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            207 => 'Multi-status',
            208 => 'Already Reported',
            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            306 => 'Switch Proxy',
            307 => 'Temporary Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Time-out',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Request Entity Too Large',
            414 => 'Request-URI Too Large',
            415 => 'Unsupported Media Type',
            416 => 'Requested range not satisfiable',
            417 => 'Expectation Failed',
            418 => 'I\'m a teapot',
            422 => 'Unprocessable Entity',
            423 => 'Locked',
            424 => 'Failed Dependency',
            425 => 'Unordered Collection',
            426 => 'Upgrade Required',
            428 => 'Precondition Required',
            429 => 'Too Many Requests',
            431 => 'Request Header Fields Too Large',
            451 => 'Unavailable For Legal Reasons',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Time-out',
            505 => 'HTTP Version not supported',
            506 => 'Variant Also Negotiates',
            507 => 'Insufficient Storage',
            508 => 'Loop Detected',
            511 => 'Network Authentication Required',
         ];

         // * Config
         // * Data
         private string $protocol;
         private int|string $status;
         // * Meta
         private string $raw;


         public function __construct ()
         {
            // * Config
            // * Data
            $this->protocol = 'HTTP/1.1';
            $this->status = '200 OK';
            // * Meta
            // @ raw
            $this->reset();
         }
         public function __get (string $name)
         {
            return $this->$name;
         }
         public function __set (string $name, $value)
         {
            switch ($name) {
               case 'raw':
                  break;
               case 'status':
                  $this->status = match ($value) {
                     (int) $value => $value . ' ' . self::PHRASES[$value],
                     (string) $value => array_search($value, self::PHRASES) . ' ' . $value
                  };

                  $this->reset();

                  break;
               default:
                  $this->$name = $value;
            }
         }

         public function reset () // @ raw
         {
            $this->raw = $this->protocol . ' ' . $this->status;
         }
      };
      $this->Content = new class ()
      {
         // * Config
         // * Data
         private string $raw;
         // * Meta
         private string $length;


         public function __construct ()
         {
            // * Config
            // * Data
            $this->raw = '';
            // * Meta
            $this->length = 0;
         }
         public function __get ($name)
         {
            switch ($name) {
               case 'raw':
                  return $this->raw;
               case 'length':
                  return $this->length;
            }
         }
         public function __set ($name, $value)
         {
            switch ($name) {
               case 'raw':
                  $this->raw = $value;
                  break;
               case 'length':
                  $this->length = (string) $value;
                  break;
            }
         }
      };
      $this->Header = new class ()
      {
         // * Data
         private array $fields;
         // * Meta
         private string $raw;


         public function __construct ()
         {
            // * Data
            $this->fields = [
               'Server' => 'Bootgly',
               'Content-Type' => 'text/html; charset=UTF-8'
            ];
            // * Meta
            $this->raw = '';
         }
         public function __get (string $name)
         {
            switch ($name) {
               case 'fields':
               case 'headers':
                  if (\PHP_SAPI !== 'cli') {
                     $this->fields = apache_response_headers();
                  }

                  return $this->fields;

               case 'raw':
                  if ($this->raw !== '') {
                     return $this->raw;
                  }

                  $this->reset();

                  return $this->raw;

               case 'sent': // TODO refactor
                  if (\PHP_SAPI !== 'cli') {
                     return headers_sent();
                  }

                  return null;

               default:
                  return $this->get($name);
            }
         }
         public function __set ($name, $value)
         {
            $this->$name = $value;
         }

         public function reset () // @ raw
         {
            foreach ($this->fields as $name => $value) {
               $this->raw .= "$name: $value\r\n";
            }

            $this->raw = rtrim($this->raw);
         }

         public function get (string $name)
         {
            return @$this->fields[$name];
         }
         public function set (string $field, string $value = '') // TODO refactor
         {
            $this->fields[$field] = $value;

            if (\PHP_SAPI !== 'cli')
               header($field . ': ' . $value, true);
         }
         public function append (string $field, string $value = '') // TODO refactor
         {
            $this->fields[$field] = $value;

            if (\PHP_SAPI !== 'cli')
               header($field . ': ' . $value, false);
         }
         public function list (array $headers)
         {
            foreach ($headers as $field => $value) {
               if ( is_int($field) ) {
                  $this->set($value);
               } else {
                  $this->set($field, $value);
               }
            }
         }
      };

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

   public function parse ()
   {
      // ? Response Content
      $this->Content->length = strlen($this->Content->raw);
      // ? Response Header
      $this->Header->set('Content-Length', $this->Content->length);
      // ? Response Meta
      // ...
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
            $File = new File($this->Web->Project->path . 'views/' . $data);
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
                        $File = new File($this->Web->Project->path . $data);
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
      $Web = &$this->Web;
      $Request = &$this->Server->Request;
      $Response = &$this->Server->Response;
      $Route = &$this->Server->Router->Route;
      // TODO add variables dinamically according to loaded modules and loaded web classes

      $API = &$Web->API ?? null;
      $App = &$Web->App ?? null;
      $System = &$Web->System ?? null;

      // Output/Buffer start()
      ob_start();

      try {
         // Isolate context with anonymous static function
         (static function (string $__file__, ?array $__data__)
            use ($Web, $Request, $Response, $Route, $API, $App, $System) {
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
                  print $this->Server->Request->queries['callback'].'('.json_encode($body).')';
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
                  $Web = &$this->Web;

                  $Request = &$this->Server->Request;
                  $Response = &$this->Server->Response;
                  $Route = &$this->Server->Router->Route;

                  // TODO add variables dinamically according to loaded modules and loaded web classes
                  $API = &$this->Web->API ?? null;
                  $App = &$this->Web->App ?? null;
                  $System = &$this->Web->System ?? null;

                  // Isolate context with anonymous static function
                  (static function (string $__file__)
                     use ($Web, $Request, $Response, $Route, $API, $App, $System) {
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
         $File = new File($this->Web->Project->path . $content);
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
      if ($this->Server->Request->headers['x-requested-with'] !== 'XMLHttpRequest') {
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
