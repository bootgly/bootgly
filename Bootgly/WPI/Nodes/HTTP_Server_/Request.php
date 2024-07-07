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


use Bootgly\WPI\Modules\HTTP\Server\Requestable;
use Bootgly\WPI\Modules\HTTP\Server\Request\Ranging;
use Bootgly\WPI\Nodes\HTTP_Server_ as Server;
use Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw;
use Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw\Body;
use Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_\Request\Session;


/**
 * * Data
 * @property string $address       127.0.0.1
 * @property string $port          52252
 *
 * @property string $scheme        http, https
 *
 * ! HTTP
 * ? Header
 * @property Header $Header
 * 
 * @property array $headers
 * 
 * @property string $method        GET, POST, ...
 * @property string $URI           /test/foo?query=abc&query2=xyz
 * @property string $protocol      HTTP/1.1
 * 
 * @ Resource
 * @property string $URL           /test/foo
 * @property string $URN           foo
 * 
 * @ Query
 * @property string $query         query=abc&query2=xyz
 * @property array $queries        ['query' => 'abc', 'query2' => 'xyz']
 * 
 * @ Host
 * @property string $host          v1.docs.bootgly.com
 * @property string $domain        bootgly.com
 * @property string $subdomain     v1.docs
 * @property array $subdomains     ['docs', 'v1']
 * ? Header / Cookie
 * @property Header\Cookie $Cookie
 * @property array $cookies
 * ? Body
 * @property Body $Body
 * 
 * @property string $input
 * 
 * @property array $post
 * 
 * @property array $files
 *
 *
 * * Metadata
 * @property string $raw
 * 
 * @property string $on            2020-03-10 (Y-m-d)
 * @property string $at            17:16:18 (H:i:s)
 * @property int $time             1586496524
 *
 * @property bool $secure          true
 * 
 * @ HTTP Basic Authentication
 * @property string $username      bootgly
 * @property string $password      example123
 * 
 * @ HTTP Content Negotiation
 * @property array $types
 * @property string $type          text/html
 * @property array $languages
 * @property string $language      en-US
 * @property array $charsets
 * @property string $charset       UTF-8
 * @property array $encodings
 * @property string $econding      gzip
 * 
 * @ HTTP Caching Specification
 * @property bool $fresh           true
 * @property bool $stale           false
 */

#[\AllowDynamicProperties]
class Request
{
   use Requestable;
   use Ranging;


   public Raw $Raw;
   public Body $Body;
   public Header $Header;

   // * Config
   private string $base;

   // * Data
   // ...

   // * Metadata
   private string $Server;
   public readonly string $on;
   public readonly string $at;
   public readonly int $time;
   // ...

   public Session $Session;


   public function __construct ()
   {
      $this->Raw = new Raw;
      $this->Body = &$this->Raw->Body;
      $this->Header = &$this->Raw->Header;

      // * Config
      $this->base = '';
      // TODO pre-defined filters
      // $this->Filter->sanitize(...) | $this->Filter->validate(...)

      // * Data
      // ...

      // * Metadata
      $this->Server = Server::class;
      $this->on = date("Y-m-d");
      $this->at = date("H:i:s");
      $this->time = $_SERVER['REQUEST_TIME'];


      $this->Session = new Session;
   }
}
