<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components;


use const PHP_EOL;
use const STR_PAD_RIGHT;
use function mb_strlen;
use function mb_strwidth;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function rewind;
use function str_ends_with;
use function str_pad;
use function stream_get_contents;

use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Alert\Type;


class Alert extends Component
{
   // ! ANSI escape sequence matcher (escape-aware measuring)
   private const string ANSI = '/\e\[[0-9;]*m/';

   private Output $Output;

   // * Config
   public Type $Type;
   public Style $Style;
   public int $width;
   /** Render a blank line above the alert (disable to glue consecutive alerts) */
   public bool $spaced;

   // * Data
   public string $message;

   // * Metadata
   // ...


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->Type = Type::Default;
      $this->Style = Style::Default;
      $this->width = 80;
      $this->spaced = true;

      // * Data
      $this->message = '';

      // * Metadata
      // ...
   }


   /**
    * Visible columns of a template message — markup and escapes occupy none,
    * wide glyphs count their real width.
    */
   private function measure (string $message): int
   {
      return mb_strwidth((string) preg_replace(self::ANSI, '', TemplateEscaped::render($message)));
   }

   /**
    * Crop a template message to a visible width, cutting only outside a markup
    * token so no `@#color:` fragment ever reaches the terminal as text.
    */
   private function crop (string $message, int $columns): string
   {
      // ? Fits as it is
      if ($this->measure($message) <= $columns) {
         return $message;
      }

      // @@ Widest prefix that fits beside the ellipsis, never inside a token
      for ($length = mb_strlen($message) - 1; $length > 0; $length--) {
         $prefix = mb_substr($message, 0, $length);

         if (str_ends_with($prefix, '@') || preg_match('/@#[^:]*$/', $prefix) === 1) {
            continue;
         }
         if ($this->measure($prefix) <= $columns - 1) {
            // :
            return "{$prefix}…";
         }
      }

      // :
      return '…';
   }

   public function render (int $mode = self::WRITE_OUTPUT): mixed
   {
      // * Config
      $type = $this->Type->get();
      $style = $this->Style->get();
      // * Data
      $message = $this->message;

      // ? Crop to the terminal — a wrapped alert breaks the block repaints and
      //   the row accounting of any region hosting it
      if (isSet(Terminal::$width) === true) {
         $badge = match ($type) {
            Type::Success => 10,
            Type::Attention => 12,
            Type::Failure => 7,
            default => 8
         };

         $columns = (int) Terminal::$width - $badge - 2;

         // ! Measured on what the terminal will SHOW: the message is template
         //   markup, and `@#cyan:`/`@;` occupy no columns once rendered
         if ($columns > 1) {
            $message = $this->crop($message, $columns);
         }
      }

      // @
      if ($mode === self::RETURN_OUTPUT) {
         $Output = new Output('php://memory');
      }
      $Output ??= $this->Output;
      $Text = $Output->Text;
      // ---
      if ($this->spaced === true) {
         $Output->write(PHP_EOL);
      }
      $Text->stylize('bold');

      switch ($style) {
         case Style::Fullcolor:
            // @ Colorize
            match ($type) {
               Type::Success => $Text->colorize('white', 'green'),
               Type::Attention => $Text->colorize(16, 11), // black (cube 16: bold cannot brighten it) on bright yellow
               Type::Failure => $Text->colorize('white', 'red'),
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
               Type::Success => $Text->colorize('white', 'green'),
               Type::Attention => $Text->colorize(16, 11), // black (cube 16: bold cannot brighten it) on bright yellow
               Type::Failure => $Text->colorize('white', 'red'),
               default => $Text->colorize('white', 'blue')
            };

            // @ Write alert type
            match ($type) {
               Type::Success => $Output->write(' SUCCESS '),
               Type::Attention => $Output->write(' ATTENTION '),
               Type::Failure => $Output->write(' FAIL '),
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

      return null;
   }
}


// * Configs
/**
 * @method self get()
 * @method self set()
 */
enum Style
{
   use \Bootgly\ABI\Configs\Set;


   case Default;
   case Fullcolor;
}
