<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


use Bootgly\Web\HTTP;
use Bootgly\Web\HTTP\Server\Request;
use Bootgly\Web\HTTP\Server\Response;
use Bootgly\Web\HTTP\Server\Router;

use Bootgly\Web\App;
use Bootgly\Web\API;


class Web
{
   // @ Nodes
   public HTTP\Server $Server;

   public Request $Request;
   public Response $Response;
   public Router $Router;
   // @ Platforms
   public App $App;
   public API $API;


   public function __construct ()
   {
      if (@$_SERVER['REDIRECT_URL'] === NULL) {
         if (\PHP_SAPI !== 'cli') {
            echo 'Missing Rewrite!';
         }

         return;
      }

      $Server = $this->Server = new HTTP\Server($this);

      $Request = $this->Request = &$this->Server->Request;
      $Response = $this->Response = &$this->Server->Response;
      $Router = $this->Router = &$this->Server->Router;

      // @ Load Web constructor
      @include Bootgly::$Project::PROJECT_DIR . 'web.constructor.php';
   }
}
