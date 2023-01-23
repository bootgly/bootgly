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
   public static function encode ($Package)
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

         // @ Reset Buffer I/O
         #$Package::$input = '';
         #$Package::$output = '';
      } else {
         return $Response->raw;
      }

      // ! Response
      // @ Output HTTP Response
      return $Response->output($Request, $Response, $Router); // @ Return Response raw
   }
}
