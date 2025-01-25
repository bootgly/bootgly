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


use function array_shift;
use function clearstatcache;
use function count;
use function filemtime;
use function file_exists;
use function function_exists;
use Closure;

use Bootgly\API\Environments;
use Bootgly\ACI\Tests\Suite;


class Server
{
   // * Config
   public static string $production;
   public static Environments $Environment = Environments::Production;

   // * Data
   public static Closure $Handler;
   // # Tests
   public static Suite $Suite;
   /**
    * Test Cases files
    * @var array<string,array<string,Closure>>
    */
   public static array $tests;

   // * Metadata
   private static string $key;
   // # Tests
   /**
    * Test Cases instances
    * @var array<string,array<string,Closure>>
    */
   public static array $Tests;


   public static function boot (
      bool $reset = false, string $base = '', null|string $key = null
   ): bool
   {
      // * Data
      $key ??= self::$key;
      self::$key = $key;

      // @
      $bootstrap = '';
      switch (self::$Environment) {
         case Environments::Production:
            $bootstrap = self::$production;
            break;
         case Environments::Test:
            if ($base === '') {
               $bootstrap = self::$production;
               break;
            }

            if (count(self::$Tests[$base]) > 0) {
               $test = array_shift(self::$Tests[$base]);
               self::$Handler = $test[$key]; // @phpstan-ignore-line
            }

            return true;
      }

      if ($reset) {
         // @ Clear Bootstrap File Cache
         if (function_exists('opcache_invalidate')) {
            \opcache_invalidate($bootstrap, true);
         }

         // @ Load Bootstrap File SAPI
         $SAPI = require $bootstrap;

         $Handler = $SAPI[$key] ?? null;
         if ($Handler !== null && $Handler instanceof Closure) {
            $Handler->bindTo(null, "static"); // @phpstan-ignore-line
            self::$Handler = $Handler;
         }
      }

      return true;
   }

   // @ Hot reload
   public static function check (): bool
   {
      static $modified = 0;

      if (file_exists(self::$production) === true) {
         // @ Clear production file cache
         clearstatcache(false, self::$production);

         // @ Get last modified timestamp of file
         $last_modified = filemtime(self::$production);

         // @ Set initial value to $modified
         if ($last_modified && $modified === 0) {
            $modified = $last_modified;
         }

         // @ Check if production is modified and reboot
         if ($last_modified && $last_modified > $modified) {
            $modified = $last_modified;
            return true;
         }
      }

      return false;
   }
}
