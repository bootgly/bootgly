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
   Divisor,
   Divisors,
   Header,
   Headers,
   Option,
   Options,
};


#[\AllowDynamicProperties]
/**
 * @Headers $Headers
 */
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
   public Divisors $Divisors;
   public Headers $Headers;
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

      // * Data
      self::$data[0] = [];

      // * Meta
      // @ Aiming
      $this->aimed = 0;
   }

   public static function push (Divisor|Header|Option $Item)
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

      // @
      $items = '';
      // ---
      $Divisors = $this->Divisors;
      $Headers = $this->Headers;
      $Options = $this->Options;

      foreach (self::$data[Menu::$level] as $key => $Item) {
         // @ Compile
         switch ($Item->type) {
            case Divisor::class:
               // @ Compile Divisor
               $items .= $Divisors->compile($Item);

               break;
            case Header::class:
               // @ Compile Header
               $items .= $Headers->compile($Item);

               break;
            case Option::class:
               // @ Compile Option
               $items .= $Options->compile($Item);

               break;
         }

         // @ Post compile
         if ($Item->type === Header::class) {
            $items .= $Options->Orientation->get() === $Orientation::Horizontal ? '' : "\n";
         }
      }

      // @ Align items horizontally
      if ($Orientation === $Orientation::Horizontal) {
         $items = str_pad($items, $Menu->width, ' ', $Aligment->value);
      }

      $this->Menu->Output->render($items);
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
