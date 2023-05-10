<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP;


use Bootgly\Web;

use Bootgly\Web\protocols\HTTP;

use Bootgly\Web\HTTP\Server\Request;
use Bootgly\Web\HTTP\Server\Response;
use Bootgly\Web\HTTP\Server\Router;


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
      self::$Router = new Router;
   }
}
