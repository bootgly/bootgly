<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Menu;


use AllowDynamicProperties;

use Bootgly\CLI\UI\Components\Menu;
use Bootgly\CLI\UI\Components\Menu\Items\Option;
use Bootgly\CLI\UI\Components\Menu\Items\Options;


#[AllowDynamicProperties]
class Items
{
   protected Menu $Menu;

   // * Config
   // @ Selecting
   public bool $selectable;
   public bool $deselectable;
   // @ Displaying
   public Orientation $Orientation;
   public Aligment $Aligment;
   // @ Boxing
   public Margin $Margin;


   // * Data
   public Options $Options;
   /** @var array<int,array<Item|Option>> */
   public static array $data;

   // * Metadata
   // @ Aiming
   protected int $aimed;


   public function __construct (Menu &$Menu)
   {
      $this->Menu = $Menu;

      // * Config
      // @ Displaying
      $this->Orientation = Orientation::Vertical->set();
      $this->Aligment = Aligment::Left->set();
      // @ Boxing
      $this->Margin = Margin::All;

      // * Data
      self::$data[0] = [];

      // * Metadata
      // @ Aiming
      $this->aimed = 0;
   }

   // @ Extending
   // TODO rename args to (Extension ...$Extensions)
   public function extend (Items ...$Extensions): void
   {
      foreach ($Extensions as $Extension) {
         $extension = basename(
            str_replace(
               '\\', '/', get_class($Extension)
            )
         );
   
         $this->$extension = $Extension;
      }
   }

   // @ Setting
   public static function push (Item ...$Items): void
   {
      foreach ($Items as $Item) {
         self::$data[Menu::$level][] = $Item;
      }
   }

   public function __destruct ()
   {
      // * Data
      self::$data = [];
      self::$data[0] = [];
   }
}


// * Configs
// @ Displaying
/**
 * @method self get()
 * @method self set()
 */
enum Orientation
{
   use \Bootgly\ABI\Configs\Set;


   case Vertical;
   case Horizontal;
}
/**
 * @method self get()
 * @method self set()
 */
enum Aligment : int
{
   use \Bootgly\ABI\Configs\Set;


   case Left = 1;
   case Center = 2;
   case Right = 0;
}
// @ Styling
// TODO Color = Foreground, Background
// @ Boxing
/*
enum Border
{}
enum Padding
{}
*/
/**
 * @method self list()
 * @method self get()
 * @method self set()
 */
enum Margin
{
   use \Bootgly\ABI\Configs\Sets;


   case All;
   case Top;
   case Right;
   case Bottom;
   case Left;
}
