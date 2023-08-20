<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Menu;


use AllowDynamicProperties;
use Bootgly\CLI\Terminal\components\Menu\Menu;
use Bootgly\CLI\Terminal\components\Menu\Items\Options;


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
   public static array $data;

   // * Meta
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

      // * Meta
      // @ Aiming
      $this->aimed = 0;
   }

   // @ Extending
   // TODO rename args to (Extension ...$Extensions)
   public function extend (Items ...$Extensions)
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
   public static function push (Item ...$Items)
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
enum Orientation
{
   use \Bootgly\ABI\Configs\Set;


   case Vertical;
   case Horizontal;
}
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
enum Margin
{
   use \Bootgly\ABI\Configs\Sets;


   case All;
   case Top;
   case Right;
   case Bottom;
   case Left;
}
