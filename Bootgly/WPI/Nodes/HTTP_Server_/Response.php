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
use Bootgly\WPI\Modules\HTTP\Server\Response\Authenticable;
use Bootgly\WPI\Modules\HTTP\Server\Response\Extendable;
use Bootgly\WPI\Modules\HTTP\Server\Response\Redirectable;
use Bootgly\WPI\Nodes\HTTP_Server_ as Server;
use Bootgly\WPI\Nodes\HTTP_Server_\Response\Raw;
use Bootgly\WPI\Nodes\HTTP_Server_\Response\Raw\Body;
use Bootgly\WPI\Nodes\HTTP_Server_\Response\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_\Response\Raw\Meta;


class Response extends Responsing
{
   use Extendable;
   use Redirectable;
   use Authenticable;


   // \
   private static $Server;

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
   private null|int|bool $code;
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
   public readonly Raw $Raw;
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

      // / HTTP
      $this->Raw = new Raw;
         $this->Meta = $this->Raw->Meta;
         $this->Header = $this->Raw->Header;
         $this->Body = $this->Raw->Body;

      // @
      if ($code !== 200) {
         $this->__set('code', $code);
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
            return $this->code = \http_response_code();
         // ? Response Headers
         case 'headers':
            return $this->Raw->Header->fields;
         // ? Response Body
         case 'chunked':
            if (! $this->chunked) {
               $this->chunked = true;
               $this->Raw->Header->append('Transfer-Encoding', 'chunked');
            }

            return $this->Raw->Body->chunked;

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
      $this->__set('code', $code);
      $this->Raw->Header->prepare($headers);
      $this->Raw->Body->raw = $body;

      return $this;
   }

   protected function prepare (? string $resource = null) : self
   {
      if ($this->initied === false) {
         $this->source = null;
         $this->type   = null;

         $this->body   = null;

         $this->initied = true;
      }

      if ($resource === null) {
         $resource = $this->resource;
      }
      else {
         $resource = \strtolower($resource);
      }

      switch ($resource) {
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

         // @ File
         case 'view':
            $this->source = 'file';
            $this->type = 'php';
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

   protected function process ($data, ? string $resource = null) : self
   {
      if ($resource === null) {
         $resource = $this->resource;
      }
      else {
         $resource = \strtolower($resource);
      }

      switch ($resource) {
         // @ File
         case 'view':
            $File = new File(BOOTGLY_PROJECT?->path . 'views/' . $data);

            $this->source = 'file';
            $this->type   = $File->extension;

            $this->body   = $File;

            break;

         // @ Content
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
         case 'raw':
            $this->body = $data;

            break;

         default:
            if ($resource) {
               // TODO Inject resource with custom process() created by user
            }
            else {
               switch ( \getType($data) ) {
                  case 'string':
                     // TODO check if string is a valid path
                     $File = match ($data[0]) {
                        #!
                        '/' => new File(BOOTGLY_WORKING_DIR . 'projects' . $data),
                        '@' => new File(BOOTGLY_WORKING_DIR . 'projects/' . $data),
                        default => new File(BOOTGLY_PROJECT?->path . $data)
                     };

                     $this->source = 'file';
                     $this->type   = $File->extension;

                     $this->body   = &$File;

                     break;
                  case 'object':
                     if ($data instanceof File) {
                        $File = $data;

                        $this->source = 'file';
                        $this->type   = $File->extension;

                        $this->body   = $File;
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
      $File = $this->body ?? null;
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
      \ob_start();

      try {
         // @ Isolate context with anonymous static function
         (static function (string $__file__, array $__vars__, ? array $__data__)
         use ($Request, $Response, $Route) {
            \extract($__vars__);

            if ($__data__ !== null) {
               \extract($__data__);
            }

            require $__file__;
         })($File, $uses, $data);
      }
      catch (\Throwable $Throwable) {}

      // @ Set $Response properties
      $this->source = 'content';
      $this->type = '';
      // @ Output/Buffer clean()->get()
      $this->body = \ob_get_clean();

      // @ Call callback
      if ($callback !== null && $callback instanceof \Closure) {
         $callback($this->body, $Throwable ?? null);
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
                  $this->Raw->Header->set('Content-Length', $File->size);

                  $body = $File->contents;

                  break;

               default: // Dynamic (PHP)
                  // @ Output/Buffer start()
                  \ob_start();

                  $Request = &Server::$Request;
                  $Response = &Server::$Response;
                  $Route = &Server::$Router->Route;

                  $uses = $this->uses;

                  // @ Isolate context with anonymous static function
                  (static function (string $__file__, array $__vars__)
                  use ($Request, $Response, $Route) {
                     \extract($__vars__);
                     require $__file__;
                  })($File, $uses);

                  $body = \ob_get_clean(); // @ Output/Buffer clean()->get()
            }

            break;
         default: // * HTTP Status Code || (string) $body
            if ($body === null) {
               $this->end();
               return $this;
            }

            if (\is_int($body) && $body > 99 && $body < 600) {
               $code = $body;

               $body = '';
               $this->body = '';

               $this->__set('code', $code);
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
         $this->__set('code', 403); // Forbidden
         return $this;
      }

      // @ Set HTTP headers
      $this->Raw->Header->prepare([
         'Content-Type' => 'application/octet-stream',
         'Content-Disposition' => 'attachment; filename="'.$File->basename.'"',

         'Last-Modified' => gmdate('D, d M Y H:i:s', $File->modified) . ' GMT',
         // Cache
         'Cache-Control' => 'no-cache, must-revalidate',
         'Expires' => '0',
      ]);

      // @ Send File Content
      $this->Raw->Header->set('Content-Length', $File->size);
      $this->Raw->Header->build();

      \flush();

      $File->read(); // FIX MEMORY RAM USAGE OR LIMIT FILE SIZE TO UPLOAD

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
      $this->__set('code', $code);

      $this->sent = true;

      exit($code);
   }
}
