<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Configs;


trait Set // @ Use with enums
{
   public function __call (string $name, array $arguments)
   {
      static $value;

      return match ($name) {
         'get' => $value ?? $this, // $this->value;
         'set' => $value = $this,  // $this->value = $this;
         default => $this
      };
   }
}
