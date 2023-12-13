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


class Server
{
   // * Config
   public static string $production;
   public static Environments $Environment = Environments::Production;

   // * Data
   private static string $key;
   public static \Closure $Handler;
   public static array $tests;

   // * Metadata
   public static array $Tests;


   public static function boot (
      bool $reset = false, string $base = '', ? string $key = null
   )
   {
      // * Data
      $key ??= self::$key;
      self::$key = $key;

      // @
      switch (self::$Environment) {
         case Environments::Production:
            $bootstrap = self::$production;
            break;
         case Environments::Test:
            if ($base === '') {
               $bootstrap = self::$production;
               break;
            }

            if (\count(self::$Tests[$base]) > 0) {
               $test = \array_shift(self::$Tests[$base]);
               self::$Handler = $test[$key];
            }

            return true;
      }

      if ($reset) {
         // @ Clear Bootstrap File Cache
         if (\function_exists('opcache_invalidate')) {
            \opcache_invalidate($bootstrap, true);
         }

         // @ Load Bootstrap File SAPI
         $SAPI = require $bootstrap;

         self::$Handler = $SAPI[$key];
      }

      return true;
   }

   // @ Hot reload
   public static function check () : bool
   {
      static $modified = 0;

      if (\file_exists(self::$production) === true) {
         // @ Clear production cache
         \clearstatcache(false, self::$production);

         // @ Get last modified timestamp of production
         $lastModified = \filemtime(self::$production);

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
