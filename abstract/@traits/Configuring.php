<?php
namespace Bootgly;


trait Configuring
{
   public function __call (string $name, array $arguments)
   {
      static $value;

      return match ($name) {
         'get' => $value ?? $this,
         'set' => $value = $this,
         default => $this
      };
   }
}
