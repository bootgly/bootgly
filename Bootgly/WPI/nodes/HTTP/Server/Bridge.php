<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\nodes\HTTP\Server;


use Bootgly\WPI;

use Bootgly\WPI\modules\HTTP;
use Bootgly\WPI\modules\HTTP\Server;

use Bootgly\WPI\nodes\HTTP\Server\Bridge\Request;
use Bootgly\WPI\nodes\HTTP\Server\Bridge\Response;
use Bootgly\WPI\modules\HTTP\Server\Router;


class Bridge implements HTTP, Server
{
   public static WPI $WPI;

   // ***

   public static Request $Request;
   public static Response $Response;
   public static Router $Router;


   public function __construct (WPI $WPI)
   {
      self::$WPI = $WPI;

      // ***

      self::$Request = new Request;
      self::$Response = new Response;
      self::$Router = new Router(static::class);
   }
}
