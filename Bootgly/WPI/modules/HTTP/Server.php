<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\modules\HTTP;


use Bootgly\WPI;

use Bootgly\WPI\modules\HTTP;

use Bootgly\WPI\modules\HTTP\Server\Request;
use Bootgly\WPI\modules\HTTP\Server\Response;
use Bootgly\WPI\modules\HTTP\Server\Router;


class Server implements HTTP
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
