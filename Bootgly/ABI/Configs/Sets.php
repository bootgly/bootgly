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


trait Sets // @ Use with enums
{
   public function __call (string $name, array $arguments)
   {
      static $values = [];

      return match ($name) {
         'list'  => $values,
         'get'   => $values[$this->name] ?? null,
         'set'   => $values[$this->name] = $arguments[0],
         default => $this
      };
   }
}
