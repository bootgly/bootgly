<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP\Server\CLI;


use Bootgly\WPI\Interfaces\TCP\Server\Packages;

use Bootgly\WPI\Modules\HTTP\Request\Ranging;
use Bootgly\WPI\Modules\HTTP\Server\Requestable;

use Bootgly\WPI\Nodes\HTTP\Server\CLI as Server;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Decoders\Decoder_Waiting;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request\Meta;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request\Content;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request\Header;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request\Downloader;


/**
 * * Data
 * @property string $address       127.0.0.1
 * @property string $port          52252
 *
 * @property string $scheme        http, https
 *
 * ! HTTP
 * @property string $raw
 * ? Meta
 * @property string $method        GET, POST, ...
 * @property string $protocol      HTTP/1.1
 * @ Resource
 * @property string $URI          /test/foo?query=abc&query2=xyz
 * @property string $URL          /test/foo
 * @property string $URN          foo
 * @ Query
 * @property object $Query
 * @property string $query         query=abc&query2=xyz
 * @property array $queries        ['query' => 'abc', 'query2' => 'xyz']
 * ? Header
 * @property Header $Header         
 * @ Host
 * @property string $host          v1.docs.bootgly.com
 * @property string $domain        bootgly.com
 * @property string $subdomain     v1.docs
 * @property array $subdomains     ['docs', 'v1']
 * @ Authorization (Basic)
 * @property string $username      boot
 * @property string $password      gly
 * @ Accept-Language
 * @property string $language      pt-BR
 * ? Header / Cookie
 * @property object $Cookie
 * @property array $cookies
 * ? Content
 * @property object Content
 * 
 * @property string $input
 * @property array $inputs
 * 
 * @property array $post
 * 
 * @property array $files
 *
 *
 * * Meta
 * @property string $on            2020-03-10 (Y-m-d)
 * @property string $at            17:16:18 (H:i:s)
 * @property int $time             1586496524
 *
 * @property bool $secure          true
 * 
 * @property bool $fresh           true
 * @property bool $stale           false
 */

#[\AllowDynamicProperties]
class Request
{
   use Ranging;
   use Requestable;


   public Meta $Meta;
   public Header $Header;
   public Content $Content;

   // * Config
   private string $base;

   // * Data
   private $_SERVER;

   // * Meta
   private string $Server;
   public readonly string $on;
   public readonly string $at;
   public readonly int $time;
   // ...

   private Downloader $Downloader;


   public function __construct ()
   {
      $this->Meta = new Meta;
      $this->Header = new Header;
      $this->Content = new Content;

      // * Config
      $this->base = '';
      // TODO pre-defined filters
      // $this->Filter->sanitize(...) | $this->Filter->validate(...)

      // * Data
      // ... dynamically
      $_POST = [];
      #$_FILES = []; // Reseted on __destruct only
      $_SERVER = [];

      // * Meta
      $this->Server = Server::class;
      $this->on = \date("Y-m-d");
      $this->at = \date("H:i:s");
      $this->time = \time();


      $this->Downloader = new Downloader($this);
   }

   public function __clone ()
   {
      $this->_SERVER = $_SERVER;
   }

   public function boot (Packages $Package, string &$buffer, int $size) : int // @ return Request length
   {
      // @ Check Request raw separator
      $separatorPosition = \strpos($buffer, "\r\n\r\n");
      if ($separatorPosition === false) { // @ Check if the Request raw has a separator
         // @ Check Request raw length
         if ($size >= 16384) { // Package size
            $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
         }

         return 0;
      }

      $length = $separatorPosition + 4; // @ Boot Request length

      // ? Request Meta
      // @ Boot Request Meta raw
      // Sample: GET /path HTTP/1.1
      $metaRaw = \strstr($buffer, "\r\n", true);
      #$metaRaw = strtok($buffer, "\r\n");

      @[$method, $URI, $protocol] = \explode(' ', $metaRaw, 3);

      // @ Check Request Meta
      if (! $method || ! $URI || ! $protocol) {
         $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
         return 0;
      }
      // method
      switch ($method) {
         case 'GET':
         case 'HEAD':
         case 'POST':
         case 'PUT':
         case 'PATCH':
         case 'DELETE':
         case 'OPTIONS':
            break;
         default:
            $Package->reject("HTTP/1.1 405 Method Not Allowed\r\n\r\n");
            return 0;
      }
      // URI
      // protocol

      // @ Prepare Request Meta length
      $metaLength = \strlen($metaRaw);

      // ? Request Header
      // @ Boot Request Header raw
      $headerRaw = \substr($buffer, $metaLength + 2, $separatorPosition - $metaLength);

      // @ Prepare Request Header length
      $headerLength = \strlen($headerRaw);

      // ? Request Content
      // @ Prepare Request Content length if possible
      if ( $_ = \strpos($headerRaw, "\r\nContent-Length: ") ) {
         $contentLength = (int) \substr($headerRaw, $_ + 18, 10);
      } else if (\preg_match("/\r\ncontent-length: ?(\d+)/i", $headerRaw, $match) === 1) {
         $contentLength = $match[1];
      } else if (\stripos($headerRaw, "\r\nTransfer-Encoding:") !== false) {
         $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
         return 0;
      }

      // @ Set Request Content raw / length if possible
      if ( isSet($contentLength) ) {
         $length += $contentLength; // @ Add Request Content length

         if ($length > 10485760) { // @ 10 megabytes
            $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
            return 0;
         }

         if ($contentLength > 0) {
            // @ Check if HTTP content is not empty
            if (\strlen($buffer) >= $separatorPosition + 4) {
               $this->Content->raw = \substr($buffer, $separatorPosition + 4, $contentLength);
               $this->Content->downloaded = \strlen($this->Content->raw);
            }

            if ($contentLength > $this->Content->downloaded) {
               $this->Content->waiting = true;
               Server::$Decoder = new Decoder_Waiting;
            }
         }

         $this->Content->length = $contentLength;
      }

      // @ Set Request
      // ? Request
      // address
      $_SERVER['REMOTE_ADDR'] = $Package->Connection->ip;
      // port
      $_SERVER['REMOTE_PORT'] = $Package->Connection->port;
      // scheme
      $_SERVER['HTTPS'] = $Package->Connection->encrypted;
      // ? Request Meta
      // raw
      $this->Meta->raw = $metaRaw;

      // method
      $_SERVER['REQUEST_METHOD'] = $method;
      // URI
      $_SERVER['REQUEST_URI'] = $URI;
      // protocol
      $_SERVER['SERVER_PROTOCOL'] = $protocol;

      // length
      $this->Meta->length = $metaLength;
      // ? Request Header
      // raw
      $this->Header->set($headerRaw);

      // host
      #$_SERVER['HTTP_HOST'] = $this->Header->get('HOST');

      // length
      $this->Header->length = $headerLength;
      // ? Request Content
      $this->Content->position = $separatorPosition + 4;

      // @ return Request length
      return $length;
   }
   public function reboot ()
   {
      if ( isSet($this->_SERVER) ) {
         $_SERVER = $this->_SERVER;
      }
   }

   public function download (? string $key = null) : array|null
   {
      // ?
      $boundary = $this->Content->parse(
         content: 'Form-data',
         type: $this->Header->get('Content-Type')
      );

      if ($boundary) {
         $this->Downloader->downloading($boundary);
      }

      // :
      if ($key === null) {
         return $_FILES;
      }
      if ( isSet($_FILES[$key]) ) {
         return $_FILES[$key];
      }
      return null;
   }
   public function receive (? string $key = null) : array|null
   {
      if ( empty($this->post) ) {
         $parsed = $this->Content->parse(
            content: 'raw',
            type: $this->Header->get('Content-Type')
         );

         if ($parsed) {
            $this->Downloader->downloading($parsed);
         }
      }

      if ($key === null) {
         return $this->post;
      }

      if ( isSet($this->post[$key]) ) {
         return $this->post[$key];
      }

      return null;
   }

   // > Middlewares
   // TODO implement https://www.php.net/manual/pt_BR/ref.filter.php
   public function filter (int $type, string $var_name, int $filter, array|int $options)
   {
      return \filter_input($type, $var_name, $filter, $options);
   }
   public function sanitize ()
   {
      // TODO
   }
   public function validate ()
   {
      // TODO
   }

   public function __destruct ()
   {
      // @ Delete files downloaded by server in temp folder
      if (empty($_FILES) === false) {
         // @ Clear cache
         \clearstatcache();

         // @ Delete temp files
         \array_walk_recursive($_FILES, function ($value, $key) {
            if ($key === 'tmp_name' && \is_file($value) === true) {
               \unlink($value);
            }
         });

         // @ Reset $_FILES
         $_FILES = [];
      }
   }
}
