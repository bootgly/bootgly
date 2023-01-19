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


class SAPI
{
   // * Config
   public static string $sapi = HOME_DIR . 'projects/sapi.constructor.php';
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
            opcache_invalidate(self::$sapi, true);
         }

         // @ Copy example file if loaded not exists
         if (file_exists(self::$sapi) === false) {
            $copied = copy(self::$sapi . '.example', self::$sapi);

            if ($copied === false) {
               return false;
            }
         }

         // @ Load file
         self::$Handler = require(self::$sapi);
      }

      return self::$Handler;
   }

   public static function check () : bool
   {
      static $modified = 0;

      if (file_exists(self::$sapi) === true) {
         // @ Clear file cache
         clearstatcache(false, self::$sapi);

         // @ Get last modified timestamp of file
         $lastModified = filemtime(self::$sapi);

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
