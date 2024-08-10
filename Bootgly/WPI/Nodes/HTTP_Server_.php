<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes;


use const Bootgly\WPI;
use Bootgly\WPI\Modules\HTTP;
use Bootgly\WPI\Modules\HTTP\Server;
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP_Server_\Request;
use Bootgly\WPI\Nodes\HTTP_Server_\Response;


class HTTP_Server_ implements HTTP, Server
{
   // ***

   public static Request $Request;
   public static Response $Response;
   public static Router $Router;


   public function __construct ()
   {
      // ***

      self::$Request = new Request;
      self::$Response = new Response;
      self::$Router = new Router(static::class);

      $WPI = WPI;
      // # HTTP
      $WPI->Server = $this;
      $WPI->Request = self::$Request;
      $WPI->Response = self::$Response;
      $WPI->Router = self::$Router;
   }
}
