<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


abstract class Command
{
   // * Config
   // Signature
   public string $name;
   public array $arguments = [];
   // Display
   public string $description;
   public bool $separate;
   public int $group;
   // Runtime
   public ? object $context;

   // * Data
   protected ? \Closure $Command = null;

   // * Metadata
   // ...


   public function __construct
   (
      // * Config
      ? string $name = null,
      ? string $description = null,
      ? array $arguments = null,

      ? object $context = null,

      // * Data
      ? \Closure $Command = null,
   )
   {
      // * Config
      $this->name = $name ?? $this->name;
      $this->description = $description ?? $this->description;
      $this->arguments = $arguments ?? $this->arguments;

      $this->context = $context;
      // * Data
      $this->Command = $Command;

      // @
      if ($context !== null) {
         $this($context);
      }

      if ($Command !== null) {
         $this->Command = $Command->bindTo($this, $this);
      }
   }
   public function __invoke (? object $context = null)
   {
      if ($context !== null) {
         $Closure = function (\Closure $Callback) use ($context) {
            $Callback = $Callback->bindTo($context, $context);
            $Callback();
         };

         $this->context = $Closure;
      }
   }
   abstract public function run (array $arguments = [], array $options = []) : bool;
}
