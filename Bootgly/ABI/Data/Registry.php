<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data;


use InvalidArgumentException;


/**
 * Minimal named object registry.
 *
 * @template T of object
 */
class Registry
{
   // * Config
   public string $label;

   // * Data
   /** @var array<string,T> */
   protected array $items = [];

   // * Metadata
   // ...


   public function __construct (string $label)
   {
      // * Config
      $this->label = $label;
   }

   /**
    * Store one object by name.
    *
    * @param T $Object
    */
   protected function store (string $name, object $Object): static
   {
      $this->items[$name] = $Object;

      return $this;
   }

   /**
    * Load one object by name.
    *
    * @return T
    */
   protected function load (string $name): object
   {
      if (isset($this->items[$name]) === false) {
         throw new InvalidArgumentException("{$this->label} is not registered: {$name}.");
      }

      return $this->items[$name];
   }
}
