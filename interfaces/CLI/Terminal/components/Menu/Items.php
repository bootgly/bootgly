<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Menu;


use AllowDynamicProperties;
use Bootgly\CLI\Terminal\components\Menu\ {
   Menu
};
use Bootgly\CLI\Terminal\components\Menu\Items\ {
   Option,
   Options,
};
use Bootgly\CLI\Terminal\components\Menu\Items\extensions\ {
   Divisors\Divisor,
   Headers\Header,
};


#[AllowDynamicProperties]
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

   public static function push (Item $Item)
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
      $rendered = '';
      // ---
      $Divisors = $this->Divisors ?? null;
      $Headers = $this->Headers ?? null;
      $Options = $this->Options;

      foreach (self::$data[Menu::$level] as $key => $Item) {
         $compiled = '';

         // @ Compile
         switch ($Item->type) {
            case Divisor::class:
               // @ Compile Divisor
               $compiled = $Divisors->compile($Item);

               break;
            case Header::class:
               // @ Compile Header
               $compiled = $Headers->compile($Item);

               break;
            case Option::class:
               // @ Compile Option
               $compiled = $Options->compile($Item);

               break;
         }

         // @ Post compile Item
         if ($Item->type === Header::class) {
            $compiled .= $Options->Orientation->get() === $Orientation::Horizontal ? ' ' : "\n";
         }

         $rendered .= $compiled;
      }

      // @ Post compile Items
      // @ Align items horizontally
      if ($Orientation === $Orientation::Horizontal) {
         $rendered = str_pad($rendered, $Menu->width, ' ', $Aligment->value);
         $rendered .= "\n";
      }

      $this->Menu->Output->render($rendered);
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
