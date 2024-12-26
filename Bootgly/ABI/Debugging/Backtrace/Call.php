<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging\Backtrace;


class Call
{
   public string $file;
   public int $line;
   public string $function;
   public ?string $class;
   public ?string $type;
   /**
    * @var array<mixed>|null
    */
   public ?array $args;


   /**
    * Create a new Call instance.
    * 
    * @param array<string,array<mixed>|int|object|string> $call The `debug_backtrace` call.
    */
   public function __construct (array $call)
   {
      /** @var string */
      $file = $call['file'];
      /** @var int */
      $line = $call['line'];
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
