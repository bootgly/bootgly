<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Menu\Items;


use Bootgly\CLI\UI\Components\Menu\Item;


final class Option extends Item
{
   // * Config
   // @ Aiming
   // Aim
   /** @var array<int> */
   public array $aimed;
   /** @var array<int> */
   public array $unaimed;
   // Marker
   /** @var array<int> */
   public array $marked;
   /** @var array<int> */
   public array $unmarked;
   // @ Selecting
   /** Display-only option: always marked, never aimed nor selectable */
   public bool $locked;

   // * Data
   public string $label;
   public string $prepend;
   public string $append;

   // * Metadata
   public int $index;


   /**
    * 
    * @param array<int> $aimed 
    * @param array<int> $unaimed 
    * @param array<int> $marked 
    * @param array<int> $unmarked 
    * @param string $label 
    * @param string $prepend 
    * @param string $append 
    * @return void 
    */
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
      // @ Selecting
      $this->locked = false;

      // * Data
      $this->label = $label;
      $this->prepend = $prepend;
      $this->append = $append;

      // * Metadata
      $this->index = Options::$indexes++;
   }
}
