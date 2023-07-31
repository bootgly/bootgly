<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Alert;


use Bootgly\CLI\Terminal\Output;


class Alert
{
   private Output $Output;

   // * Config
   public Type $Type;
   public int $width;


   public function __construct (Output &$Output)
   {
      $this->Output = $Output;

      // * Config
      $this->Type = Type::DEFAULT;
      $this->width = 80;
   }


   public function emit (string $message)
   {
      // * Config
      $type = $this->Type->get();

      // @
      // @ Prepare
      $this->Output->write(PHP_EOL);
      $this->Output->Text->stylize('bold');

      // @ Colorize
      match ($type) {
         Type::SUCCESS => $this->Output->Text->colorize('white', 'green'),
         Type::ATTENTION => $this->Output->Text->colorize(0, 'yellow'),
         Type::FAILURE => $this->Output->Text->colorize('white', 'red'),
         default => $this->Output->Text->colorize(0, 7)
      };

      // @ Padding
      $padding = str_pad('', $this->width, ' ', STR_PAD_RIGHT);
      $message = str_pad($message, $this->width, ' ', STR_PAD_RIGHT);

      // @ Output
      $this->Output->render(<<<OUTPUT
       $padding
       $message
       $padding
       @;\n
      OUTPUT);

      // @ Reset style and color
      $this->Output->Text->stylize();
      $this->Output->Text->colorize();
   }
}


// * Configs
enum Type : int
{
   use \Bootgly\ABI\Set;


   case DEFAULT   = 0;
   case SUCCESS   = 1;
   case ATTENTION = 2;
   case FAILURE   = 4;
}
