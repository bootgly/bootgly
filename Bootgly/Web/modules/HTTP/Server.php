<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\modules\HTTP;


use Bootgly\Web;

use Bootgly\Web\modules\HTTP;

use Bootgly\Web\modules\HTTP\Server\Request;
use Bootgly\Web\modules\HTTP\Server\Response;
use Bootgly\Web\modules\HTTP\Server\Router;


class Server implements HTTP
{
   public static Web $Web;

   // ***

   public static Request $Request;
   public static Response $Response;
   public static Router $Router;


   public function __construct (Web $Web)
   {
      self::$Web = $Web;

      // ***

      self::$Request = new Request;
      self::$Response = new Response;
      self::$Router = new Router(static::class);
   }
}
