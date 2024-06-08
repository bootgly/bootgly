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
use Bootgly\ABI\Resources;


class Directives implements Resources
{
   // * Config
   // ...

   // * Data
   protected array $directives;

   // * Metadata
   protected array $names;
   // @ Regex
   protected string $tokens;


   public function __construct ()
   {
      $resource = __DIR__ . '/Template/directives/';
      $bootstrap = require($resource . '@.php');

      $directives = $bootstrap['directives'];
      foreach ($directives as $name => $value) {
         // @ Register directive name
         if (is_string($name) === true) {
            $this->names[] = $name;
         }

         // @ Set directive value
         $directive = [];
         if (is_string($value) === true) {
            $filename = Path::normalize($value);

            $directive = require($resource . $filename . '.directive.php');
         }
         else if (is_array($value) === true) {
            $directive = $value;
         }

         foreach ($directive as $pattern => $Closure) {
            $this->directives[$pattern] = $Closure;
         }
      }

      $this->tokens = implode('|', $this->names);
   }
   public function __get ($name)
   {
      switch ($name) {
         // * Data
         case 'directives':
            return $this->directives;

         // * Metadata
         case 'names':
            return $this->names;
         // @ Regex
         case 'tokens':
            return $this->tokens;

         default:
            return null;
      }
   }

   public function extend (string $pattern, Closure $Callback, ? string $name = null)
   {
      if ($name) {
         $this->names[] = $name;
      }

      $this->directives[$pattern] ??= $Callback;
   }
}
