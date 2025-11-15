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


use function ob_start;
use function strval;
use function strtolower;
use function json_decode;
use function is_array;
use function is_scalar;
use function is_object;
use function is_resource;
use function is_string;
use function json_encode;
use function method_exists;
use function getType;
use AllowDynamicProperties;
use Closure;
use Throwable;

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Templates\Template;
use const Bootgly\WPI;
use Bootgly\WPI\Modules\HTTP;
use Bootgly\WPI\Modules\HTTP\Server;
use Bootgly\WPI\Modules\HTTP\Server\Response\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Body;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;

/**
 * * Config
 * @property int $code
 */
#[AllowDynamicProperties]
class Response extends Server\Response
{
   use Raw;


   // * Config
   // ...

   // * Data
   // # Resource
   // @ Content
   public string|null $source;
   public string|null $type;

   // * Metadata
   // @ State (sets)
   public bool $chunked;
   public bool $encoded;
   // @ Type (set)
   #public bool $dynamic;
   #public bool $static;
   public bool $stream;
   // # Resource
   // @ Content
   private ?string $resource;
   /** @var array<string,mixed> */
   protected array $uses = [];
   /** @var array<int, array<string, mixed>> */
   protected array $files;
   // @ Status (sets ...)
   public bool $initied = false;
   public bool $prepared;
   public bool $processed;
   public bool $sent;

   // / HTTP
   public Header $Header;
   public Body $Body;

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
            return $this->code;
         // # Response Headers
         case 'headers':
            return $this->Header->fields;
         // # Response Body
         case 'chunked':
            if ($this->chunked === false) {
               $this->chunked = true;
               $this->Header->append('Transfer-Encoding', 'chunked');
            }

            return $this->Body->chunked;

         default: // @ Contruct Non-Raw Response
            $this->resource = $name;

            $this->prepared = false;
            $this->processed = false;

            $this->prepare($name);

            return $this;
      }
   }
   public function __set (string $name, mixed $value): void
   {
      switch ($name) {
         // @ Response Metadata
         case 'code':
            if (\is_int($value) && $value > 99 && $value < 600) {
               $this->code($value);
            }
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
      $this->Header->reset();

      $this->code($code);
      $this->Header->prepare($headers);
      $this->Body->raw = $body;

      return $this;
   }

   // # Authentication
   /**
    * Authenticate the user with the provided authentication method.
    *
    * @param Authentication $Method The authentication method to use.
    *
    * @return self The Response instance, for chaining
    */
   public function authenticate (Authentication $Method): self
   {
      $this->__set('code', 401);

      switch ($Method) {
         case $Method instanceof Authentication\Basic:
            $this->Header->set(
               'WWW-Authenticate',
               'Basic realm="' . $Method->realm . '"'
            );
            break;
      }

      return $this;
   }
   // # Bootable
   protected function prepare (?string $resource = null): self
   {
      if ($this->initied === false) {
         $this->source  = null;
         $this->type    = null;

         $this->content = "";

         $this->initied = true;
      }

      if ($resource === null) {
         $resource = $this->resource;
      }
      else {
         $resource = strtolower($resource);
      }

      switch ($resource) {
         // Content
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

         // File
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
   protected function process (mixed $data, ?string $resource = null): self
   {
      if ($resource === null) {
         $resource = $this->resource;
      }
      else {
         $resource = strtolower($resource);
      }

      /** @var Closure(mixed):string $convert */
      /** @var Closure(mixed):string $convert */
      $convert = static function (mixed $value): string {
         if (is_string($value)) {
            return $value;
         }

         if ($value === null || is_scalar($value)) {
            return strval($value);
         }

         if (is_object($value)) {
            if (method_exists($value, '__toString')) {
               return (string) $value;
            }

            $encodedObject = json_encode($value);
            return $encodedObject === false ? '' : $encodedObject;
         }

         if (is_array($value)) {
            $encodedArray = json_encode($value);
            return $encodedArray === false ? '' : $encodedArray;
         }

         if (is_resource($value)) {
            return '';
         }

         return '';
      };

      switch ($resource) {
         // Content
         case 'json':
         case 'jsonp':
            if ( is_array($data) ) {
               $this->content = $data;
               break;
            }

            if (is_string($data)) {
               $decoded = json_decode($data, true);
               $this->content = is_array($decoded) ? $decoded : [];
               break;
            }

            $this->content = [];

            break;
         case 'pre':
            $preData = $data;
            if ($preData === null) {
               $preData = $this->content;
            }

            /** @var string $preString */
            $preString = $convert($preData);
            $this->content = '<pre>' . $preString . '</pre>';

            break;

         // File
         case 'view':
            if (! is_string($data)) {
               break;
            }

            $File = new File(BOOTGLY_PROJECT->path . 'views/' . $data);

            $this->source = 'file';
            $extension = $File->extension;
            $this->type   = is_string($extension) ? $extension : '';

            $this->File   = $File;

            break;

         // Raw
         case 'raw':
            /** @var string $rawString */
            $rawString = $convert($data);
            $this->content = $rawString;

            break;

         default:
            if ($resource) {
               // TODO Inject resource with custom process() created by user
            }
            else {
               switch ( getType($data) ) {
                  case 'string':
                     if ($data === '') {
                        break;
                     }

                     $prefix = $data[0] ?? '';
                     // TODO check if string is a valid path
                     $File = match ($prefix) {
                        #!
                        '/' => new File(BOOTGLY_WORKING_DIR . 'projects' . $data),
                        '@' => new File(BOOTGLY_WORKING_DIR . 'projects/' . $data),
                        default => new File(BOOTGLY_PROJECT->path . $data)
                     };

                     $this->source = 'file';
                     $extension = $File->extension;
                     $this->type   = is_string($extension) ? $extension : '';

                     $this->File   = &$File;

                     break;
                  case 'object':
                     if ($data instanceof File) {
                        $File = $data;

                        $this->source = 'file';
                        $extension = $File->extension;
                        $this->type   = is_string($extension) ? $extension : '';

                        $this->File   = $File;
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
    * Appends the provided data to the body of the response.
    *
    * @param mixed $body The data that should be appended to the response body.
    *
    * @return self The Response instance, for chaining
    */
   public function append ($body): self
   {
      $this->initied = true;

      $convert = static function (mixed $value): string {
         if (is_string($value)) {
            return $value;
         }

         if ($value === null || is_scalar($value)) {
            return strval($value);
         }

         if (is_object($value)) {
            if (method_exists($value, '__toString')) {
               return (string) $value;
            }

            $encodedObject = json_encode($value);
            return $encodedObject === false ? '' : $encodedObject;
         }

         if (is_array($value)) {
            $encodedArray = json_encode($value);
            return $encodedArray === false ? '' : $encodedArray;
         }

         if (is_resource($value)) {
            return '';
         }

         return '';
      };

      $current = is_string($this->content) ? $this->content : '';
      $this->content = $current . $convert($body) . "\n";

      return $this;
   }

   /**
    * Export variables to the File Response.
    *
    * @param array<string, mixed> ...$variables Variables to be passed to the File Response.
    *
    * @return self The Response instance, for chaining
    */
   public function export (array ...$variables): self
   {
      foreach ($variables as $var) {
         foreach ($var as $key => $value) {
            $this->uses[$key] = $value;
         }
      }

      return $this;
   }
   /**
    * Renders the specified view with the provided data.
    *
    * @param string $view The view to render.
    * @param array<string,mixed>|null $data The data to provide to the view.
    * @param Closure|null $callback Optional callback.
    *
    * @return self The Response instance, for chaining
    */
   public function render (string $view, ?array $data = null, ?Closure $callback = null): self
   {
      // !
      $this->prepare('view');
      $this->process($view . '.template.php', 'view');

      // ?
      $File = $this->File ?? null;
      if ($File === null || $File->exists === false) {
         // throw new \Exception(message: 'Template file not found!');
         return $this;
      }

      // @ Set variables
      if ($data === null) {
         $data = [];
      }
      $data['Route'] = WPI->Router->Route;

      // @ Extend variables
      $data = $data + $this->uses;

      // @ Output/Buffer start()
      ob_start();
      // @ Render Template
      $Template = new Template($File);
      try {
         $rendered = $Template->render($data);
      }
      catch (Throwable $Throwable) {
         $rendered = '';
         Throwables::report($Throwable);
      }
      // @ Output/Buffer clean()->get()
      $this->content = is_string($rendered) ? $rendered : '';

      // @ Set $Response properties
      $this->source = 'content';
      $this->type = '';

      // @ Call callback
      if ($callback !== null) {
         $callback($this->content, $Throwable ?? null);
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
    * @return string|false The compressed content or false on failure.
    */
   public function compress (string $raw, string $method = 'gzip', int $level = 9, ? int $encoding = null): string|false
   {
      $encoded = false;
      $deflated = false;
      $compressed = false;

      try {
         switch ($method) {
            case 'gzip':
               $encoding ??= \ZLIB_ENCODING_GZIP;
               $encoded = @\gzencode($raw, $level, $encoding);
               break;
            case 'deflate':
               $encoding ??= \ZLIB_ENCODING_RAW;
               $deflated = @\gzdeflate($raw, $level, $encoding);
               break;
            case 'compress':
               $encoding ??= \ZLIB_ENCODING_DEFLATE;
               $compressed = @\gzcompress($raw, $level, $encoding);
               break;
         }
      }
      catch (\Throwable) {
         // ...
      }

      if ($encoded) {
         $this->encoded = true;
         $this->Header->set('Content-Encoding', 'gzip');
         return $encoded;
      }
      else if ($deflated) {
         $this->encoded = true;
         $this->Header->set('Content-Encoding', 'deflate');
         return $deflated;
      }
      else if ($compressed) {
         $this->encoded = true;
         $this->Header->set('Content-Encoding', 'gzip');
         return $compressed;
      }

      return false;
   }

   /**
    * Redirects to a new URI. Default return is 307 for GET (Temporary Redirect) and 303 (See Other) for POST.
    *
    * @param string $URI The new URI to redirect to.
    * @param ?int $code The HTTP status code to use for the redirection.
    *
    * @return self The Response instance, for chaining.
    */
   public function redirect (string $URI, int|null $code = null): self
   {
      // !?
      switch ($code) {
         case 300: // Multiple Choices
         case 301: // Moved Permanently
         case 302: // Found (or Moved Temporarily)
         case 303: // See Other
         case 307: // Temporary Redirect
         case 308: // Permanent Redirect

            break;
         default:
            $code = null;
      }

      // ? Set default code
      if ($code === null) {
         $code = match (WPI->Request->method) {
            'POST' => 303, // See Other
            default => 307 // Temporary Redirect
         };
      }

      // @
      $this->__set('code', $code);
      $this->Header->set('Location', $URI);
      $this->end();

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
      // * Data
      // @ status
      $this->code = $code;

      $status = $code . ' ' . HTTP::RESPONSE_STATUS[$code];

      @[$code, $message] = explode(' ', $status);

      if ($code && $message) {
         // * Metadata
         // @ status
         $this->message = $message;
         $this->status = $status;
         $this->response = parent::PROTOCOL . ' ' . $status;
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
   public function send ($body = null, ...$options): self
   {
      // ?
      if ($this->sent === true) {
         return $this;
      }
      if ($this->processed === false) {
         $this->process($body, $this->resource);
      }

      // TODO refactor.
      switch ($this->source) {
         case 'content':
            // @ Set body/content
            switch ($this->type) {
               case 'application/json':
               case 'json':
                  // TODO move to prepare or process
                  $this->Header->set('Content-Type', 'application/json');

                  if ($body && is_string($body) === true) {
                     break;
                  }

                  $flags = isset($options[0]) && is_int($options[0]) ? $options[0] : 0;
                  $encoded = json_encode($body, $flags);
                  $body = $encoded === false ? 'null' : $encoded;

                  break;
               case 'jsonp':
                  // TODO move to prepare or process
                  $this->Header->set('Content-Type', 'application/json');

                  $callbackSource = WPI->Request->queries['callback'] ?? null;
                  if (is_array($callbackSource)) {
                     $callbackSource = $callbackSource[0] ?? null;
                  }
                  $callback = is_string($callbackSource) && $callbackSource !== ''
                     ? $callbackSource
                     : 'callback';

                  $json = json_encode($body);
                  if ($json === false) {
                     $json = 'null';
                  }

                  $body = $callback . '(' . $json . ')';

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

                  $contents = $File->contents;
                  if ($contents === false) {
                     return $this;
                  }

                  $body = $contents;

                  break;

               default: // Dynamic (PHP)
                  // @ Output/Buffer start()
                  \ob_start();

                  $__data__ = [
                     'Request' => WPI->Request,
                     'Response' => WPI->Response
                  ];

                  // @ Isolate context with anonymous static function
                  (static function (string $__file__, array $__data__) {
                     \extract($__data__);
                     require $__file__;
                  })($File, $__data__);

                  $captured = \ob_get_clean(); // @ Output/Buffer clean()->get()
                  $body = $captured === false ? '' : $captured;
            }

            break;
         default:
            if ($body === null) {
               $this->sent = true;

               return $this;
            }
      }

      // @ Output
      if ($body !== null) {
         /** @var Closure(mixed):string $convert */
         $convert = static function (mixed $value): string {
            if (is_string($value)) {
               return $value;
            }

            if ($value === null || is_scalar($value)) {
               return strval($value);
            }

            if (is_object($value)) {
               if (method_exists($value, '__toString')) {
                  return (string) $value;
               }

               $encodedObject = json_encode($value);
               return $encodedObject === false ? '' : $encodedObject;
            }

            if (is_array($value)) {
               $encodedArray = json_encode($value);
               return $encodedArray === false ? '' : $encodedArray;
            }

            if (is_resource($value)) {
               return '';
            }

            return '';
         };

         $this->Body->raw = $convert($body);
      }

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
   public function upload (string|File $file, int $offset = 0, ? int $length = null, bool $close = true): self
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
            $this->__set('code', 500);
            return $this;
         }
         $File = new File($Project . Path::normalize($file));
      }

      if ($File->readable === false) {
         $this->__set('code', 403);
         return $this;
      }

      // @
      $size = $File->size;
      if (! is_int($size)) {
         $this->__set('code', 500);
         return $this;
      }

      // @ Prepare HTTP headers
      $this->Header->prepare([
         'Last-Modified' => \gmdate('D, d M Y H:i:s \G\M\T', $File->modified),
         // Cache
         'Cache-Control' => 'no-cache, must-revalidate',
         'Expires' => '0',
      ]);

      // @ Return null Response if client Purpose === prefetch
      if (WPI->Request->Header->get('Purpose') === 'prefetch') {
         $this->code(204);
         $this->Header->set('Cache-Control', 'no-store');
         $this->Header->set('Expires', '0');
         return $this;
      }

      $ranges = [];
      $parts = [];
      $Range = WPI->Request->Header->get('Range');

      if (is_string($Range) && $Range !== '') {
         // @ Parse Client range requests
         $ranges = WPI->Request->range($size, $Range);

         switch ($ranges) {
            case -2: // Malformed Range header string
               $this->end(400);
               return $this;
            case -1:
               $this->end(416, (string) $size);
               return $this;
            default:
               if (! is_array($ranges)) {
                  return $this;
               }

               /** @var mixed $type */
               $type = \array_pop($ranges);
               // @ Check Range type
               if (! is_string($type) || $type !== 'bytes') {
                  $this->end(416, (string) $size);
                  return $this;
               }

               /** @var array<int, array{start:int,end:int|null}> $ranges */
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
         $this->Header->set('Content-Length', (string) $parts[0]['length']);
      }
      // @ Set HTTP range requests Headers
      $pads = [];
      if (! empty($ranges) && ($ranges[0]['end'] !== null || $ranges[0]['start'])) {
         // @ Set Response status
         $this->code(206); // 206 Partial Content

         if ($rangesCount > 1) { // @ HTTP Multipart ranges
            $boundary = \str_pad(
               string: (string) ++WPI->Request::$multiparts,
               length: 20,
               pad_string: '0',
               pad_type: \STR_PAD_LEFT
            );

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
               $length += \strlen($prepend);
               $length += \strlen($append ?? '');

               $pads[] = [
                  'prepend' => $prepend,
                  'append' => $append
               ];
            }

            $this->Header->set('Content-Length', (string) $length);
         }
         else { // @ HTTP Single part ranges
            $start = $ranges[0]['start'];
            $end = $ranges[0]['end'];

            if ($end > $size - 1) $end += 1;

            $this->Header->set('Content-Range', "bytes {$start}-{$end}/{$size}");
         }
      }
      else {
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

   /**
    * Definitively terminates the HTTP Response.
    *
    * @param int|null $code The status code of the response.
    * @param string|null $context The context for additional information.
    *
    * @return Response Returns Response.
    */
   public function end (? int $code = null, ? string $context = null): self
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
               $this->code(416);
               // Clean prepared headers / header fields already set
               $this->Header->clean();
               $this->Body->raw = ' '; // Needs body non-empty
               break;
            default:
               $this->__set('code', $code);
         }

         // @ Contextualize
         switch ($code) {
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
