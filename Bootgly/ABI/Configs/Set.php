<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Configs;


trait Set // @ Use with enums
{
   public function __call (string $name, array $arguments)
   {
      static $value;

      return match ($name) {
         'get' => $value ?? $this, // $this->value;
         'set' => $value = $this,  // $this->value = $this;
         default => $this
      };
   }
}

/* Example:
// * Config
namespace Bootgly\CLI\Terminal\Output\Text;


enum Colors : int
{
   use \Bootgly\ABI\Configs\Set;


   case Default = 1;
   case Bright = 2;
}

// ...


$Output = CLI::$Terminal->Output;

// @ Set
$Output->Text->Colors::Bright->set(); // @ Set bright color

// @ Get
// $Output->Text->Colors->value;
$Output->Text->Colors->get(); // @ Get configured color
*/
