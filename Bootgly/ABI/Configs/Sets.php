<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Configs;

/**
 * @method self list()
 * @method self get()
 * @method self set()
 */
trait Sets // @ Use with enums
{
   /**
    * @param string $name
    * @param array<mixed> $arguments
    *
    * @return mixed
    */
   public function __call (string $name, array $arguments): mixed
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
