<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP\Server\Bridge;


use Bootgly\WPI\Modules\HTTP\Request\Ranging;
use Bootgly\WPI\Modules\HTTP\Server\Requestable;

use Bootgly\WPI\Nodes\HTTP\Server\Bridge as Server;
use Bootgly\WPI\Nodes\HTTP\Server\Bridge\Request\Meta;
use Bootgly\WPI\Nodes\HTTP\Server\Bridge\Request\Content;
use Bootgly\WPI\Nodes\HTTP\Server\Bridge\Request\Header;

use Bootgly\WPI\Nodes\HTTP\Server\Bridge\Request\Session;


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
 * @property object Header         
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
      $this->Meta = new Meta;
      $this->Header = new Header;
      $this->Content = new Content;

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

   // TODO implement https://www.php.net/manual/pt_BR/ref.filter.php
   public function filter (int $type, string $var_name, int $filter, array|int $options)
   {
      return filter_input($type, $var_name, $filter, $options);
   }
   public function sanitize ()
   {
      // TODO
   }
   public function validate ()
   {
      // TODO
   }
}
