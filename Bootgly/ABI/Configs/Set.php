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

/**
 * @method self get()
 * @method self set()
 */
trait Set
{
   /**
    * @param string $name
    * @param array<mixed> $arguments
    * 
    * @return self
    */
   public function __call (string $name, array $arguments): self
   {
      static $value;

      return match ($name) {
         'get' => $value ?? $this, // $this->value;
         'set' => $value = $this,  // $this->value = $this;
         default => $this
      };
   }
}
