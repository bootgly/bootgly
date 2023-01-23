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

   public static Request $Request;
   public static Response $Response;
   public static Router $Router;


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
      self::$Request = new Request;
      self::$Response = new Response;
      self::$Router = new Router;

      // @ Init Data callback
      if ($this->status === self::STATUS_BOOTING) {
         self::$Request->Meta;
         self::$Request->Header;
         self::$Request->Content;
      }
   }

   public static function decode ($Socket, $Package)
   {
      if ($Package::$input === '') {
         return false;
      }

      // @ Instance callbacks
      $Request = Server::$Request;

      // ! Request
      // @ Input HTTP Request
      if ($Request->input($Package::$input) === 0) {
         $Package->write($Socket, false);

         $Package->Connections->close($Socket);
      }

      #$this->log(self::$output . PHP_EOL . PHP_EOL . PHP_EOL);
   }
   public static function encode ($Socket, $Package)
   {
      // @ Instance callbacks
      $Request = Server::$Request;
      $Response = Server::$Response;
      $Router = Server::$Router;

      // @ Reset cache
      if ($Package->changed) {
         // @ Delete event from loop
         #Server::$Event->del($Socket, Server::$Event::EVENT_WRITE);

         // @ Reset HTTP Request/Response Data
         $Request->reset();
         $Response->reset();

         // @ Reset Buffer I/O
         #$Package::$input = '';
         #$Package::$output = '';
      }

      return $Response->output($Request, $Response, $Router);
   }
}
