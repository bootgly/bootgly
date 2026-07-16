<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Atoms;


use const BOOTGLY_TTY;
use function str_ends_with;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


class Dumper extends Component
{
   private Output $Output;

   // * Config
   /** SGR decoration — null follows the TTY, false forces plain, true forces styled */
   public null|bool $decoration;
   /** Max nesting level — deeper containers render as `…` */
   public int $depth;
   /** Max string chars — longer strings truncate with a `(+N)` note */
   public int $strings;
   /** Max entries per container — extra entries collapse into `… +N more` */
   public int $items;
   /** Named dump theme — resolved from Vars\Dumper::$Themes */
   public string $theme;

   // * Data
   /** The value to dump */
   public mixed $value;

   // * Metadata
   /** @var array<string,Vars\Dumper> Lazy ABI engines by theme name */
   private array $Engines;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->decoration = null;
      $this->depth = 8;
      $this->strings = 150;
      $this->items = 100;
      $this->theme = 'bootgly';

      // * Data
      $this->value = null;

      // * Metadata
      $this->Engines = [];
   }

   /**
    * Dump the value as colorized, structured output.
    *
    * @param int $mode `WRITE_OUTPUT` writes to the Output; `RETURN_OUTPUT` returns the string.
    *
    * @return null|string
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // !
      $plain = ($this->decoration ?? BOOTGLY_TTY) === false;

      // ! Engine — lazy per theme
      $key = $plain === true ? 'plain' : $this->theme;
      $Engine = $this->Engines[$key] ??= new Vars\Dumper($key);
      // caps passthrough
      $Engine->depth = $this->depth;
      $Engine->strings = $this->strings;
      $Engine->items = $this->items;

      // @
      $output = $Engine->dump($this->value);

      if (str_ends_with($output, "\n") === false) {
         $output .= "\n";
      }

      // ?: Return
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $output;
      }

      // @ Write
      $this->Output->write($output);

      // :
      return null;
   }
}
