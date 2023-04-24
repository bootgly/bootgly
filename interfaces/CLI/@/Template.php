<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


use Bootgly\__String\Escapeable\ {
   Escapeable
};
use Bootgly\__String\Escapeable\cursor;
use Bootgly\__String\Escapeable\text;
use Bootgly\__String\Escapeable\viewport;


class Template
{
   use Escapeable;
   use cursor\Positionable;
   use cursor\Shapeable;
   use cursor\Visualizable;
   use text\Formattable;
   use text\Modifiable;
   use viewport\Scrollable;


   public static array $tokens = [];


   public static function boot ()
   {
      $resource = 'Template/tokens/';
      $tokens = require $resource . '@.php';

      $files = $tokens['files'];
      foreach ($files as $file) {
         $Token = require $resource . $file . '.php';

         foreach ($Token as $token => $Closure) {
            self::$tokens[$token] = $Closure;
         }
      }
   }

   public static function render (string $message) : string
   {
      #$line = "\033[1A\n\033[K";

      $message = preg_replace_callback_array(self::$tokens, $message);

      return $message;
   }
}

Template::boot();
