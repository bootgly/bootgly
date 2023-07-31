<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Menu\Items;


use Bootgly\CLI\Terminal\components\Menu\Item;


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

   // * Meta
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

      // * Meta
      $this->index = Options::$indexes++;
   }
}
