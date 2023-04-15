<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Menu\Items;


use Bootgly\CLI\Terminal\components\Menu\Item;


final class Option extends Item
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
   public int $index;


   public function __construct (string $label = '')
   {
      parent::__construct();

      // * Config
      $this->aimed = [];
      $this->unaimed = [];

      $this->marked = [];
      $this->unmarked = [];

      // * Data
      $this->id = '';
      $this->label = $label;
      $this->prepend = '';
      $this->append = '';

      // * Meta
      $this->index = Options::$indexes++;
   }
}
