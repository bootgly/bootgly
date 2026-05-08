<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Coverage\Drivers;


use function extension_loaded;
use function function_exists;
use LogicException;

use Bootgly\ACI\Tests\Coverage\Driver;


/**
 * ext-pcov backend.
 */
final class PCOV extends Driver
{
   /**
    * ext-pcov function used to start collection.
    */
   private const string FN_START = 'pcov\\start';
   /**
    * ext-pcov function used to stop collection.
    */
   private const string FN_STOP = 'pcov\\stop';
   /**
    * ext-pcov function used to collect hits.
    */
   private const string FN_COLLECT = 'pcov\\collect';


   /**
    * Ensure ext-pcov is available before the backend is used.
    */
   public function __construct ()
   {
      if (! extension_loaded('pcov')) {
         throw new LogicException('Pcov coverage requires ext-pcov');
      }
   }

   /**
    * Start ext-pcov collection.
    */
   protected function begin (): void
   {
      $start = self::resolve(self::FN_START);
      $start();
   }

   /**
    * Stop ext-pcov collection.
    */
   protected function end (): void
   {
      $stop = self::resolve(self::FN_STOP);
      $stop();
   }

   /**
    * Collect and normalize ext-pcov line states.
    *
    * @return array<string,array<int,int>>
    */
   public function collect (): array
   {
      $collect = self::resolve(self::FN_COLLECT);
      /** @var array<string, array<int, int>> $raw */
      $raw = $collect();
      $out = [];
      foreach ($raw as $file => $lines) {
         $bucket = [];
         foreach ($lines as $line => $state) {
            $bucket[$line] = $state > 0 ? 1 : 0;
         }
         $out[$file] = $bucket;
      }
      return $out;
   }

   /**
    * @return callable-string
    */
   private static function resolve (string $function): string
   {
      if (! function_exists($function)) {
         throw new LogicException("Pcov coverage requires {$function}().");
      }

      return $function;
   }
}
