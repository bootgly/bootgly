<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Menu\Items;


use Bootgly\CLI\UI\Menu\Item;


final class Option extends Item
{
   // * Config
   // @ Aiming
   // Aim
   public array $aimed;
   public array $unaimed;
   // Marker
   public array $marked;
   public array $unmarked;

   // * Data
   public string $label;
   public string $prepend;
   public string $append;

   // * Metadata
   public int $index;


   public function __construct (
      array $aimed = [],
      array $unaimed = [],
      array $marked = [],
      array $unmarked = [],
      string $label = '',
      string $prepend = '',
      string $append = '',
   )
   {
      parent::__construct();

      // * Config
      // @ Aiming
      // Aim
      $this->aimed = $aimed;
      $this->unaimed = $unaimed;
      // Marker
      $this->marked = $marked;
      $this->unmarked = $unmarked;

      // * Data
      $this->label = $label;
      $this->prepend = $prepend;
      $this->append = $append;

      // * Metadata
      $this->index = Options::$indexes++;
   }
}
