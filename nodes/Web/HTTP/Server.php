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

use Bootgly\SAPI;
use Bootgly\Web;
use Bootgly\Web\TCP;

use Bootgly\Web\HTTP\Server\Request;
use Bootgly\Web\HTTP\Server\Response;
use Bootgly\Web\HTTP\Server\Router;


class Server extends TCP\Server
{
   public static Web $Web;

   // * Meta
   public readonly array $versions;

   public static Request $Request;
   public static Response $Response;
   public static Router $Router;


   public function __construct (Web $Web)
   {
      self::$Web = $Web;

      // * Meta
      $this->versions = [ // @ HTTP 1.1
         'min' => '1.1',
         'max' => '1.1' // TODO HTTP 2
      ];

      parent::__construct();

      self::$Request = new Request;
      self::$Response = new Response;
      self::$Router = new Router;

      // @ Init Data callback
      if ($this->status === self::STATUS_BOOTING) {
         self::$Request->Meta;
         self::$Request->Header;
         self::$Request->Content;

         // TODO initial Response Data API
         #self::$Response->Meta;
         #self::$Response->Header;
         #self::$Response->Content;
      }
   }

   public static function decode ($Package)
   {
      if ($Package::$input === '') {
         return false;
      }

      // @ Instance callbacks
      $Request = Server::$Request;

      // ! Request
      // @ Input HTTP Request
      return $Request->input($Package); // @ Return Request Content length
   }
   public static function encode ($Package, &$length)
   {
      // @ Instance callbacks
      $Request = Server::$Request;
      $Response = Server::$Response;
      $Router = Server::$Router;

      // @ Reset cache if necessary otherwise return cached
      if ($Package->changed) {
         // @ Reset HTTP Request/Response Data
         $Request->reset();
         $Response->reset();
      } else {
         // TODO check if Response raw is static or dynamic
         return $Response->raw;
      }

      // ! Response
      // @ Invoke SAPI Closure
      try {
         (SAPI::$Handler)($Request, $Response, $Router);
      } catch (\Throwable) {
         $Response->Meta->status = 500; // @ Set 500 HTTP Server Error Response
      }

      // @ Output/Stream HTTP Response
      return $Response->output($length); // @ Return Response raw
   }
}
