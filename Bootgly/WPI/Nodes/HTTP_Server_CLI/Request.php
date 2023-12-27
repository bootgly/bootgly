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


use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Modules\HTTP\Server\Requestable;
use Bootgly\WPI\Modules\HTTP\Server\Request\Ranging;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Waiting;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Meta;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Content;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Header;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Downloader;


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
   protected array $_SERVER;

   // * Metadata
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

      // * Metadata
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

   public function input (Packages $Package, string &$buffer, int $size) : int // @ return Request length
   {
      // @ Check Request raw separator
      $separator_position = \strpos($buffer, "\r\n\r\n");
      // @ Check if the Request raw has a separator
      if ($separator_position === false) {
         // @ Check Request raw length
         if ($size >= 16384) { // Package size
            $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
         }

         return 0;
      }

      // @ Init Request length
      $length = $separator_position + 4;

      // ? Request Meta (first line of HTTP Header)
      // @ Get Request Meta raw
      // Sample: GET /path HTTP/1.1
      $meta_raw = \strstr($buffer, "\r\n", true);

      @[$method, $URI, $protocol] = \explode(' ', $meta_raw, 3);

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

      // @ Set Request Meta length
      $meta_length = \strlen($meta_raw);

      // ? Request Header
      // @ Get Request Header raw
      $header_raw = \substr($buffer, $meta_length + 2, $separator_position - $meta_length);

      // @ Prepare Request Header length
      $header_length = \strlen($header_raw);

      // ? Request Content
      // @ Set Request Content length if possible
      if ( $_ = \strpos($header_raw, "\r\nContent-Length: ") ) {
         $content_length = (int) \substr($header_raw, $_ + 18, 10);
      }
      else if (\preg_match("/\r\ncontent-length: ?(\d+)/i", $header_raw, $match) === 1) {
         $content_length = $match[1];
      }
      else if (\stripos($header_raw, "\r\nTransfer-Encoding:") !== false) {
         $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
         return 0;
      }

      // @ Set Request Content raw if possible
      if ( isSet($content_length) ) {
         $length += $content_length; // @ Add Request Content length

         if ($length > 10485760) { // @ 10 megabytes
            $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
            return 0;
         }

         if ($content_length > 0) {
            // @ Check if HTTP content is not empty
            if ($size >= $separator_position + 4) {
               $this->Content->raw = \substr($buffer, $separator_position + 4, $content_length);
               $this->Content->downloaded = \strlen($this->Content->raw);
            }

            if ($content_length > $this->Content->downloaded) {
               $this->Content->waiting = true;
               Server::$Decoder = new Decoder_Waiting;
            }
         }

         $this->Content->length = $content_length;
      }

      // @ Set Request
      // ! Request
      // address
      $_SERVER['REMOTE_ADDR'] = $Package->Connection->ip;
      // port
      $_SERVER['REMOTE_PORT'] = $Package->Connection->port;
      // scheme
      $_SERVER['HTTPS'] = $Package->Connection->encrypted;

      // ! Request Meta
      // raw
      $this->Meta->raw = $meta_raw;
      // method
      $_SERVER['REQUEST_METHOD'] = $method;
      // URI
      $_SERVER['REQUEST_URI'] = $URI;
      // protocol
      $_SERVER['SERVER_PROTOCOL'] = $protocol;
      // length
      $this->Meta->length = $meta_length;

      // ! Request Header
      // raw
      $this->Header->set(raw: $header_raw);
      // host
      #$_SERVER['HTTP_HOST'] = $this->Header->get('HOST');
      // length
      $this->Header->length = $header_length;

      // ! Request Content
      $this->Content->position = $separator_position + 4;

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
