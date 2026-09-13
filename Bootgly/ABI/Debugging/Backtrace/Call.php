<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging\Backtrace;


class Call
{
   public null|string $file;
   public null|int $line;
   public string $function;
   public null|string $class;
   public null|string $type;
   /**
    * @var array<mixed>|null
    */
   public null|array $args;


   /**
    * Create a new Call instance.
    * 
    * @param array<string,null|string|int|array<mixed>|object> $call The `debug_backtrace` call.
    */
   public function __construct (array $call)
   {
      /** @var string */
      $file = $call['file'] ?? null;
      /** @var int */
      $line = $call['line'] ?? null;
      /** @var string */
      $function = $call['function'];
      /** @var string|null */
      $class = $call['class'] ?? null;
      /** @var string|null */
      $type = $call['type'] ?? null;
      /** @var array<mixed>|null */
      $args = $call['args'] ?? null;

      $this->file = $file;
      $this->line = $line;
      $this->function = $function;
      $this->class = $class;
      $this->type = $type;
      $this->args = $args;
   }
}
