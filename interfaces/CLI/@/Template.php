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


use Bootgly\CLI\Escaping;
use Bootgly\CLI\Escaping\cursor;
use Bootgly\CLI\Escaping\text;
use Bootgly\CLI\Escaping\viewport;
use Bootgly\Debugger;


class Template
{
   use Escaping;

   use cursor\Positioning;
   use cursor\Shaping;
   use cursor\Visualizing;

   use text\Formatting;
   use text\Modifying;

   use viewport\Positioning;


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
