<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server\Request\Raw;


abstract class Header
{
   // * Config
   // ...

   // * Data
   /** @var array<string|array<string>> */
   protected array $fields;

   // * Metadata
   protected bool $built;


   /**
    * Get a field from the Request Header
    *
    * @param string $name 
    *
    * @return string|array<string>
    */
   public function get (string $name): string|array
   {
      if ($this->built === false) {
         $this->build();
      }

      return ($this->fields[$name] ?? $this->fields[\strtolower($name)] ?? '');
   }

   /**
    * Append a field to the Request Header
    *
    * @param string $name Field name
    * @param string $value Field value
    *
    * @return bool 
    */
   public function append (string $name, string $value): bool
   {
      if ( isSet($this->fields[$name]) ) {
         return false;
      }

      $this->fields[$name] = $value;

      return true;
   }

   /**
    * Build fields from the Request Header
    *
    * @return bool 
    */
   abstract public function build (): bool;
}
