<?php
namespace Bootgly;


trait Sets // @ Use with enums
{
   public function __call (string $name, array $arguments)
   {
      static $values = [];

      return match ($name) {
         'get' => $values[$this->name] ?? null,
         'set' => $values[$this->name] = $arguments[0],
         default => $this
      };
   }
}
