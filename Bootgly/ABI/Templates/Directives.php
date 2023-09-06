<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Templates;


use Closure;
#use Throwable;


class Directives
{   // * Data
   protected array $directives;


   public function __construct ()
   {
      // * Data
      // ->directives
      $resource = 'directives/';
      $bootables = require($resource . '@.php');

      $files = $bootables['files'];

      foreach ($files as $file) {
         $directives = require($resource . $file . '.php');

         foreach ($directives as $directive => $Closure) {
            $this->directives[$directive] = $Closure;
         }
      }
   }
   public function __get ($name)
   {
      if ($name === 'directives') {
         return $this->directives;
      }

      return null;
   }


   public function extend (string $pattern, Closure $Callback)
   {
      $this->directives[$pattern] ??= $Callback;
   }
}
