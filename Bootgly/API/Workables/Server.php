<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Workables;


use function array_shift;
use function clearstatcache;
use function count;
use function filemtime;
use function file_exists;
use function function_exists;
use Closure;

use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environments;
use Bootgly\API\Workables\Server\Handling;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\API\Workables\Server\Middlewares;


class Server
{
   // * Config
   public static string $production = '';
   public static Environments $Environment = Environments::Production;

   // * Data
   public static Closure $Handler;
   public static Middlewares $Middlewares;
   // # Tests
   public static Suite $Suite;
   /**
    * Test Cases files
    * @var array<string,array<int|string,string>>
    */
   public static array $tests;

   // * Metadata
   private static string $key;
   // # Tests
   /**
    * Test Cases instances
    * @var array<string,array<int|string,Specification|Closure|null>>
    */
   public static array $Tests;


   public static function boot (
      bool $reset = false, string $base = '', null|string $key = null
   ): bool
   {
      // !
      if (isSet(self::$Middlewares) === false) {
         self::$Middlewares = new Middlewares;
      }

      // * Data
      $key ??= self::$key;
      self::$key = $key;

      // @
      $bootstrap = '';
      switch (self::$Environment) {
         case Environments::Production:
            // ? No production file when handler set via handle()
            if (self::$production === '') {
               return true;
            }

            $bootstrap = self::$production;
            break;
         case Environments::Test:
            if ($base === '') {
               $bootstrap = self::$production;
               break;
            }

            if (count(self::$Tests[$base]) > 0) {
               $test = array_shift(self::$Tests[$base]);

               if ($test instanceof Handling) {
                  self::$Handler = $test->response;

                  // @ Configure test middlewares
                  self::$Middlewares = new Middlewares;
                  if ($test->middlewares !== []) {
                     self::$Middlewares->pipe(...$test->middlewares);
                  }
               }
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

         // @ Load global middlewares from SAPI
         /** @var array<Middleware> $middlewares */
         $middlewares = $SAPI['on.Middlewares'] ?? [];
         self::$Middlewares = new Middlewares;
         if ($middlewares !== []) {
            self::$Middlewares->pipe(...$middlewares);
         }
      }

      return true;
   }

   // @ Hot reload
   public static function check (): bool
   {
      static $modified = 0;

      // ? No production file to watch
      if (self::$production === '') {
         return false;
      }

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