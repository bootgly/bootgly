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


use Bootgly;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\IO\FS\File;

use Bootgly\WPI\Modules\HTTP\Server\Response as Responsing;
use Bootgly\WPI\Nodes\HTTP_Server_ as Server;
use Bootgly\WPI\Nodes\HTTP_Server_\Response\Body;
use Bootgly\WPI\Nodes\HTTP_Server_\Response\Meta;
use Bootgly\WPI\Nodes\HTTP_Server_\Response\Header;


class Response implements Responsing
{
   // ! HTTP
   public Meta $Meta;
   public Header $Header;
   public Body $Body;

   // * Config
   // ...

   // * Data
   public string $raw;
   public $body;

   public array $files;

   public ? array $resources;
   public ? string $source;
   public ? string $type;

   protected array $uses;

   // * Metadata
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


   public function __construct (int $code = 200, ? array $headers = null, string $body = '')
   {
      // ! HTTP
      $this->Meta = new Meta;
      $this->Body = new Body;
      $this->Header = new Header;


      // * Config
      // ...

      // * Data
      $this->raw = '';
      $this->body = null;

      $this->files = [];

      $this->resources = ['JSON', 'JSONP', 'View', 'HTML/pre'];
      $this->source = null;
      $this->type = null;

      $this->uses = [];

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


      // @
      if ($code !== 200) {
         $this->code = $code;
      }
      if ($headers !== null) {
         $this->Header->prepare($headers);
      }
      if ($body !== '') {
         $this->Body->raw = $body;
      }
   }
   public function __get (string $name)
   {
      switch ($name) {
         // ? Response Meta
         case 'status':
         case 'code':
            return http_response_code();
         // ? Response Headers
         case 'headers':
            return $this->Header->fields;
         // ? Response Body
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
   public function __set (string $name, $value)
   {
      switch ($name) {
         case 'status':
         case 'code':
            http_response_code($value);
            break;
      }
   }
   public function __invoke (int $code = 200, array $headers = [], string $body = '') : self
   {
      $this->code = $code;
      $this->Header->prepare($headers);
      $this->Body->raw = $body;

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
   /**
    * Appends the provided data to the body of the response.
    *
    * @param mixed $body The data that should be appended to the response body.
    *
    * @return Response The Response instance, for chaining
    */
   public function append ($body)
   {
      $this->initied = true;
      $this->body .= $body . "\n";
   }
   public function use (string $name, $var)
   {
      $this->uses[$name] = $var;
   }

   protected function process ($data, ? string $resource = null)
   {
      if ($resource === null) {
         $resource = $this->resource;
      }
      else {
         $resource = strtolower($resource);
      }

      switch ($resource) {
         // @ File
         case 'view':
            $File = new File(BOOTGLY_PROJECT?->path . 'views/' . $data);

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
            }
            else {
               switch ( getType($data) ) {
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

      $this->processed = true;

      $this->resource = null;

      return $this;
   }

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

      // @ Set variables
      /**
       * @var \Bootgly\WPI $WPI
       */
      $Request = &Server::$Request;
      $Response = &Server::$Response;
      $Route = &Server::$Router->Route;

      $uses = $this->uses;

      // @ Output/Buffer start()
      ob_start();

      try {
         // @ Isolate context with anonymous static function
         (static function (string $__file__, array $__vars__, ? array $__data__)
         use ($Request, $Response, $Route) {
            extract($__vars__);

            if ($__data__ !== null) {
               extract($__data__);
            }

            require $__file__;
         })($File, $uses, $data);
      }
      catch (\Throwable $Throwable) {}

      // @ Set $Response properties
      $this->source = 'content';
      $this->type = '';
      // @ Output/Buffer clean()->get()
      $this->body = ob_get_clean();

      // @ Call callback
      if ($callback !== null && $callback instanceof \Closure) {
         $callback($this->body, $Throwable);
      }

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
                  $this->Header->set('Content-Length', $File->size);

                  $body = $File->contents;

                  break;

               default: // Dynamic (PHP)
                  // @ Output/Buffer start()
                  ob_start();

                  $Request = &Server::$Request;
                  $Response = &Server::$Response;
                  $Route = &Server::$Router->Route;

                  $uses = $this->uses;

                  // @ Isolate context with anonymous static function
                  (static function (string $__file__, array $__vars__)
                  use ($Request, $Response, $Route) {
                     extract($__vars__);
                     require $__file__;
                  })($File, $uses);

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
      print $body ?? $this->body;

      $this->end();

      return $this;
   }

   /**
    * Start a file upload from the Server to the Client
    *
    * @param string|File $file The file to be uploaded
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
      $this->Header->set('Content-Length', $File->size);
      $this->Header->build();

      flush();

      $File->read(); // FIX MEMORY RAM USAGE OR LIMIT FILE SIZE TO UPLOAD

      $this->end();

      return $this;
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
      if (Server::$Request->headers['x-requested-with'] !== 'XMLHttpRequest') {
         header('WWW-Authenticate: Basic realm="' . $realm . '"');
      }

      $this->code = 401;
      // header('HTTP/1.0 401 Unauthorized');

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
   public function redirect (string $URI, ? int $code = null) : self // Code 302 = temporary; 301 = permanent;
   {
      // $this->code = $code;
      header('Location: '. $URI, true, $code ?? 302);

      $this->end();

      return $this;
   }

   /**
    * Definitively terminates the HTTP Response.
    *
    * @param int|string|null $status The status of the response.
    *
    * @return void
    */
   public function end (int|string|null $status = null) : void
   {
      if ($this->sent) {
         return;
      }

      $this->sent = true;

      // @
      exit($status);
   }
}
