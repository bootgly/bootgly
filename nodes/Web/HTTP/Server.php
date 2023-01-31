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

use Bootgly\Web\TCP\Server\Packages;

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

   public static function decode (Packages $Package, string &$buffer)
   {
      if ($buffer === '') {
         return false;
      }

      static $input = []; // @ Instance local cache

      // @ Check local cache and return
      if ( isSet($input[$buffer]) ) {
         #$this->cached = true;
         return $input[$buffer];
      }

      // @ Instance callbacks
      $Request = Server::$Request;

      // @ Handle Package cache
      if ($Package->changed) {
         $Request->reset();
      }

      // ! Request
      // @ Input HTTP Request
      $length = $Request->input($Package, $buffer); // @ Return Request Content length

      // @ Write to local cache
      if ( ! isSet($buffer[512]) ) {
         $input[$buffer] = $length;

         if (count($input) > 512) {
            unSet($input[key($input)]);
         }
      }

      return $length;
   }
   public static function encode (Packages $Package, &$length)
   {
      // @ Instance callbacks
      $Request = Server::$Request;
      $Response = Server::$Response;
      $Router = Server::$Router;

      // @ Handle Package cache
      if ($Package->changed) {
         $Response->reset();
      } else if ($Response->Content->raw) {
         // TODO check if Response raw is static or dynamic
         return $Response->raw;
      }

      // ! Response
      // @ Try to Invoke SAPI Closure
      try {
         (SAPI::$Handler)($Request, $Response, $Router);
      } catch (\Throwable) {
         $Response->Meta->status = 500; // @ Set 500 HTTP Server Error Response

         if ($Response->Content->raw === '') {
            $Response->Content->raw = ' ';
         }
      }

      // @ Output/Stream HTTP Response
      return $Response->output($Package, $length); // @ Return Response raw
   }
}
