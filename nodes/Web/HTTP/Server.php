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
use Bootgly\Web\TCP;

use Bootgly\Web\HTTP\Server\Request;
use Bootgly\Web\HTTP\Server\Response;
use Bootgly\Web\HTTP\Server\Router;


class Server extends TCP\Server
{
   public Web $Web;

   // ***

   public Request $Request;
   public Response $Response;
   public Router $Router;


   public function __construct (Web $Web)
   {
      $this->Web = $Web;

      // ***

      parent::__construct();

      $this->Request = new Request($this);
      $this->Response = new Response($Web, $this);
      $this->Router = new Router($Web, $this);

      if (self::$status === self::STATUS_BOOTING) {
         $this->Connections->Data->callbacks = [
            &$this->Request,
            &$this->Response,
            &$this->Router
         ];
      }
   }
}
