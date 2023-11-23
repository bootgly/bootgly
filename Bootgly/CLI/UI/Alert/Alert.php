<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Alert;


use Bootgly\API\Component;

use Bootgly\CLI\Terminal\Output;


class Alert extends Component
{
   private Output $Output;

   // * Config
   public Type $Type;
   public Style $Style;
   public int $width;

   // * Data
   public string $message;

   // * Meta
   // ...


   public function __construct (Output &$Output)
   {
      $this->Output = $Output;

      // * Config
      $this->Type = Type::DEFAULT;
      $this->Style = Style::DEFAULT;
      $this->width = 80;

      // * Data
      $this->message = '';

      // * Meta
      // ...
   }


   public function render (int $mode = self::WRITE_OUTPUT)
   {
      // * Config
      $type = $this->Type->get();
      $style = $this->Style->get();
      // * Data
      $message = $this->message;

      // @
      if ($mode === self::RETURN_OUTPUT) {
         $Output = new Output('php://memory');
      }
      $Output ??= $this->Output;
      $Text = $Output->Text;
      // ---
      $Output->write(PHP_EOL);
      $Text->stylize('bold');

      switch ($style) {
         case STYLE::FULLCOLOR:
            // @ Colorize
            match ($type) {
               Type::SUCCESS => $Text->colorize('white', 'green'),
               Type::ATTENTION => $Text->colorize(0, 'yellow'),
               Type::FAILURE => $Text->colorize('white', 'red'),
               default => $Text->colorize(0, 7)
            };

            // @ Padding
            $padding = str_pad('', $this->width, ' ', STR_PAD_RIGHT);
            $message = str_pad($message, $this->width, ' ', STR_PAD_RIGHT);

            // @ Output
            $Output->render(<<<OUTPUT
             $padding
             $message
             $padding
            @;\n
            OUTPUT);

            // @ Reset style and color
            $Text->stylize();
            $Text->colorize();
            break;
         default:
            // @ Colorize alert type
            match ($type) {
               Type::SUCCESS => $Text->colorize('white', 'green'),
               Type::ATTENTION => $Text->colorize(0, 'yellow'),
               Type::FAILURE => $Text->colorize('white', 'red'),
               default => $Text->colorize('white', 'blue')
            };

            // @ Write alert type
            match ($type) {
               Type::SUCCESS => $Output->write(' SUCCESS '),
               Type::ATTENTION => $Output->write(' ATTENTION '),
               Type::FAILURE => $Output->write(' FAIL '),
               default => $Output->write(' ALERT ')
            };

            // @ Reset color
            $Text->colorize();

            // @ Write message
            $Output->render(<<<OUTPUT
             $message
            @;\n
            OUTPUT);

            // @ Reset style
            $Text->stylize();
      }

      if ($mode === self::RETURN_OUTPUT) {
         rewind($Output->stream);
         $output = stream_get_contents($Output->stream);
         return $output;
      }
   }
}


// * Configs
enum Type
{
   use \Bootgly\ABI\Configs\Set;


   case DEFAULT;
   case SUCCESS;
   case ATTENTION;
   case FAILURE;
}

enum Style
{
   use \Bootgly\ABI\Configs\Set;

   case DEFAULT;
   case FULLCOLOR;
}
