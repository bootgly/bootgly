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

   // * Meta
   public readonly array $versions;

   public Request $Request;
   public Response $Response;
   public Router $Router;


   public function __construct (Web $Web)
   {
      $this->Web = $Web; // TODO make static ?

      // * Meta
      $this->versions = [ // @ HTTP 1.1
         'min' => '1.1',
         'max' => '1.1' // TODO HTTP 2
      ];

      parent::__construct();

      // TODO make static ?
      $this->Request = new Request($this);
      $this->Response = new Response($Web, $this);
      $this->Router = new Router($Web, $this);

      // @ Init Data callback
      if ($this->status === self::STATUS_BOOTING) {
         $this->Request->Meta;
         $this->Request->Header;
         $this->Request->Content;

         $this->Connections->Packages->callbacks = [
            &$this->Request,
            &$this->Response,
            &$this->Router
         ];
      }
   }
}
