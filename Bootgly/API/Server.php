<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API;


use Closure;


class Server
{
   // * Config
   // @ Environments
   public static string $production;
   public const MODE_PRODUCTION = 1; // TODO rename to WORK?
   public const MODE_TEST = 2;
   public static int $mode = self::MODE_PRODUCTION;

   // * Data
   public static array $tests;

   // * Meta
   public static Closure $Handler;
   public static array $Tests;


   public static function boot ($reset = false, string $base = '')
   {
      switch (self::$mode) {
         case self::MODE_PRODUCTION:
            $file = self::$production;
            break;
         case self::MODE_TEST:
            if ($base === '') {
               $file = self::$production;
               break;
            }

            if (count(self::$Tests[$base]) > 0) {
               $test = array_shift(self::$Tests[$base]);
               // TODO pass name by arg
               self::$Handler = $test['response'];
            }

            return true;
      }

      if ($reset) {
         // @ Clear cache
         if ( function_exists('opcache_invalidate') ) {
            opcache_invalidate($file, true);
         }

         // @ Copy example production if loaded not exists
         if (file_exists($file) === false) {
            $copied = copy($file . '.example', $file);

            var_dump($file . '.example', $file);
            if ($copied === false) {
               return false;
            }
         }

         // @ Load production
         self::$Handler = require $file;
      }

      return self::$Handler;
   }

   public static function check () : bool
   {
      static $modified = 0;

      if (file_exists(self::$production) === true) {
         // @ Clear production cache
         clearstatcache(false, self::$production);

         // @ Get last modified timestamp of production
         $lastModified = filemtime(self::$production);

         // @ Set initial value to $modified
         if ($modified === 0) {
            $modified = $lastModified;
         }

         // @ Check if production is modified and reboot
         if ($lastModified > $modified) {
            $modified = $lastModified;
            return true;
         }
      }

      return false;
   }
}
