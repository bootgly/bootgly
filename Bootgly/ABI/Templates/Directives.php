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

use Bootgly\ABI\Data\__String\Path;


class Directives
{   // * Data
   protected array $directives;


   public function __construct ()
   {
      // * Data
      // ->directives
      $resource = 'directives/';
      $bootables = require($resource . '@.php');

      $directives = $bootables['directives'];

      foreach ($directives as $path) {
         $filename = Path::normalize($path);

         $directive = require($resource . $filename . '.php');

         foreach ($directive as $pattern => $Closure) {
            $this->directives[$pattern] = $Closure;
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
