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


class Directives // TODO use Resources interface
{
   // * Config
   // ...
   // * Data
   protected array $directives;
   // * Meta
   // ...


   public function __construct ()
   {
      // * Data
      // ->directives
      $resource = __DIR__ . '/directives/';
      $bootables = require($resource . '@.php');

      $directives = $bootables['directives'];

      foreach ($directives as $value) {
         if (is_string($value) === true) {
            $filename = Path::normalize($value);

            $directive = require($resource . $filename . '.php');
         } else if (is_array($value) === true) {
            $directive = $value;
         }

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
