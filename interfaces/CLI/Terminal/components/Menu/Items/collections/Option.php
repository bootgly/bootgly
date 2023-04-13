<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Menu\Items\collections;


final class Option
{
   // * Config
   public array $aimed;
   public array $unaimed;

   public array $marked;
   public array $unmarked;

   // * Data
   public ? string $id;
   public string $label;
   public string $prepend;
   public string $append;

   // * Meta
   public readonly string $type;
   public int $index;


   public function __construct ()
   {
      // * Config
      $this->aimed = [];
      $this->unaimed = [];

      $this->marked = [];
      $this->unmarked = [];

      // * Data
      // ...

      // * Meta
      $this->type = static::class;
   }
}
