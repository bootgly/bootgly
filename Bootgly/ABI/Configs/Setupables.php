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
 * @method mixed get()
 * @method self set(mixed ...$arguments)
 */
trait Setupables // @ Use with enums
{
   /**
    * @param string $name
    * @param array<mixed> $arguments
    * 
    * @return object
    */
   public function __call (string $name, array $arguments): ?object
   {
      static $values = [];
      /** @var ?object $class */
      static $class = null;

      switch ($name) {
         case 'instance':
            $class = $arguments[0];
            break;

         case 'set':
            if ($class !== null) {
               $values[$this->name] = $arguments[0];
               return $class;
            }
            break;
         case 'get':
            return $values[$this->name] ?? null;

         default:
            return $this;
      }

      return $this;
   }
}
