<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Templates\Template;


use Bootgly\ABI\Data\__String\Escapeable;
use Bootgly\ABI\Data\__String\Escapeable\cursor;
use Bootgly\ABI\Data\__String\Escapeable\text;
use Bootgly\ABI\Data\__String\Escapeable\viewport;
use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Resources;


class Escaped implements Resources
{
   use Escapeable;
   use cursor\Positionable;
   use cursor\Shapeable;
   use cursor\Visualizable;
   use text\Formattable;
   use text\Modifiable;
   use viewport\Scrollable;

   // * Config
   // ...

   // * Data
   protected static array $directives = [];

   // * Meta
   protected static array $names = [];


   public static function boot ()
   {
      if ( ! empty(self::$directives) ) {
         return;
      }

      $resource = __DIR__ . '/Escaped/directives/';
      $bootstrap = require($resource . '@.php');

      $directives = $bootstrap['directives'];
      foreach ($directives as $name => $value) {
         // @ Register directive name
         if (is_string($name) === true) {
            self::$names[] = $name;
         }

         // @ Set directive value
         if (is_string($value) === true) {
            $filename = Path::normalize($value);

            $directive = require($resource . $filename . '.directive.php');
         } else if (is_array($value) === true) {
            $directive = $value;
         }

         foreach ($directive as $pattern => $Closure) {
            self::$directives[$pattern] = $Closure;
         }
      }
   }

   public static function render (string $message) : string
   {
      $message = preg_replace_callback_array(self::$directives, $message);

      return $message;
   }
}

Escaped::boot();
