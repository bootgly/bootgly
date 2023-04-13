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


use AllowDynamicProperties;
use Bootgly\CLI\Terminal\components\Menu\ {
   Menu
};
use Bootgly\CLI\Terminal\components\Menu\Items\collections\ {
   Option,
   Separator,
};


#[\AllowDynamicProperties]
class Items
{
   protected Menu $Menu;

   // * Config
   // @ Selecting
   /**
    * Items are selectable?
    */
   public bool $selectable;
    /**
     * Items are deselectable?
     */
   public bool $deselectable;
   // @ Displaying
   /**
    * Items Orientation is Vertical or Horizontal?
    */
   public Orientation $Orientation;
   /**
    * Items Aligment is Left, Center or Right?
    */
   public Aligment $Aligment;

   // * Data
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

      // * Data
      self::$data[0] = [];

      // * Meta
      // @ Aiming
      $this->aimed = 0;
   }

   public static function push (Option|Separator $Item)
   {
      self::$data[Menu::$level][] = $Item;
   }

   public function render ()
   {
      $Menu = $this->Menu;

      // ! Items
      // TODO replace with Item if set
      // * Config
      // @ Displaying
      $Orientation = $this->Orientation->get();
      $Aligment = $this->Aligment->get();

      $items = '';
      foreach (self::$data[Menu::$level] as $key => $Item) {
         $items .= match ($Item->type) {
            Option::class    => $this->Options->compile($Item),
            Separator::class => $this->Separators->compile($Item)
         };
      }

      // Align items horizontally
      if ($Orientation === $Orientation::Horizontal) {
         $items = str_pad($items, $Menu->width, ' ', $Aligment->value);
      }

      $this->Menu->Output->render($items);
   }

   public function __destruct ()
   {
      // * Data
      self::$data[0] = [];
   }
}


// * Configs
// @ Displaying
enum Orientation
{
   use \Bootgly\Set;


   case Vertical;
   case Horizontal;
}
enum Aligment : int
{
   use \Bootgly\Set;


   case Left = 1;
   case Center = 2;
   case Right = 0;
}
