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
use Bootgly\ABI\Data\__String\Escapeable\Cursor\Positionable;
use Bootgly\ABI\Data\__String\Escapeable\Cursor\Shapeable;
use Bootgly\ABI\Data\__String\Escapeable\Cursor\Visualizable;
use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Data\__String\Escapeable\Text\Modifiable;
use Bootgly\ABI\Data\__String\Escapeable\Viewport\Scrollable;
use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Resources;


class Escaped implements Resources
{
   use Escapeable;
   use Positionable;
   use Shapeable;
   use Visualizable;
   use Formattable;
   use Modifiable;
   use Scrollable;

   // * Config
   // ...

   // * Data
   protected static array $directives = [];

   // * Metadata
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
