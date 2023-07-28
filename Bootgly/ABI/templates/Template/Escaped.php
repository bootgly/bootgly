<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\templates\Template;


// -abstract
use Bootgly\ABI\__String\Escapeable;
use Bootgly\ABI\__String\Escapeable\cursor;
use Bootgly\ABI\__String\Escapeable\text;
use Bootgly\ABI\__String\Escapeable\viewport;


class Escaped
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
      $resource = '/Escaped/tokens/';
      $tokens = require (__DIR__ . $resource . '@.php');

      $files = $tokens['files'];
      foreach ($files as $file) { // TODO add filter
         $Token = require (__DIR__ . $resource . $file . '.php');

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

Escaped::boot();
