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

use Bootgly\Web\protocols\HTTP;

use Bootgly\Web\HTTP\Server\Request;
use Bootgly\Web\HTTP\Server\Response;
use Bootgly\Web\HTTP\Server\Router;


class Server extends TCP\Server implements HTTP
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

   public static function decode (Packages $Package, string &$buffer, int $length)
   {
      static $inputs = []; // @ Instance local cache

      // @ Check local cache and return
      if ( $length <= 512 && isSet($inputs[$buffer]) ) {
         #Server::$Request = $inputs[$buffer];
         return $length;
      }

      // @ Instance callbacks
      $Request = Server::$Request;

      // ! Request
      // @ Check if Request Content is waiting data
      if ($Request->Content->waiting) {
         // @ Finish filling the Request Content raw with TCP read buffer
         $Content = &$Request->Content;

         $Content->raw .= $buffer;
         $Content->downloaded += $length;

         if ($Content->length > $Content->downloaded) {
            return 0;
         }

         $Content->waiting = false;

         return $Content->length;
      }

      // @ Handle Package cache
      if ($Package->changed) {
         // @ Reset Globals
         $_POST = [];
         $_FILES = [];
         // @ Instance new Request
         $Request = Server::$Request = new Request;
         // Reset Request
         // $Request->reset();
      }

      // @ Write to local cache
      if ($length <= 512) {
         #$inputs[$buffer] = clone $Request;
         $inputs[$buffer] = $length;

         if (count($inputs) > 1) { // @ Cache only the last Request?
            unSet($inputs[key($inputs)]);
         }
      }

      // ! Request
      // @ Boot HTTP Request
      return $Request->boot($Package, $buffer, $length); // @ Return Request Content length
   }
   public static function encode (Packages $Package, &$length)
   {
      // @ Instance callbacks
      $Request = Server::$Request;
      $Response = Server::$Response;

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
         (SAPI::$Handler)($Request, $Response, Server::$Router);
      } catch (\Throwable) {
         $Response->Meta->status = 500; // @ Set 500 HTTP Server Error Response

         if ($Response->Content->raw === '') {
            $Response->Content->raw = ' ';
         }
      }
      // @ Check if Request Content is waiting data
      if ($Request->Content->waiting) {
         return '';
      }
      // @ Output/Stream HTTP Response
      return $Response->output($Package, $length); // @ Return Response raw
   }
}
