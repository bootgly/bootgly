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


use Closure;
use Bootgly\{
   SAPI\Environment
};
use Bootgly\Web\HTTP\Server;


class SAPI
{
   // * Config
   public static string $file = HOME_DIR . 'projects/sapi.constructor.php';
   // * Data
   // * Meta
   public static Closure $Handler;

   public object $Environment;


   public function __construct ()
   {
      // TODO
      #$this->Environment = new Environment($this);

      #$Environment  = &$this->Environment;
   }

   public static function boot ($reset = false)
   {
      if ($reset) {
         // @ Invalidate opcache
         if ( function_exists('opcache_invalidate') ) {
            opcache_invalidate(self::$file, true);
         }

         // @ Copy example file if loaded not exists
         if (file_exists(self::$file) === false) {
            $copied = copy(self::$file . '.example', self::$file);

            if ($copied === false) {
               return false;
            }
         }

         if ( isSet(Server::$Response) ) {
            // @ Clear Response cache
            Server::$Response->reset();
         }

         // @ Load file
         self::$Handler = require(self::$file);
      }

      return self::$Handler;
   }

   public static function check () : bool
   {
      static $modified = 0;

      if (file_exists(self::$file) === true) {
         // @ Clear file cache
         clearstatcache(false, self::$file);

         // @ Get last modified timestamp of file
         $lastModified = filemtime(self::$file);

         // @ Set initial value to $modified
         if ($modified === 0) {
            $modified = $lastModified;
         }

         // @ Check if file is modified and reboot
         if ($lastModified > $modified) {
            $modified = $lastModified;
            return true;
         }
      }

      return false;
   }
}
