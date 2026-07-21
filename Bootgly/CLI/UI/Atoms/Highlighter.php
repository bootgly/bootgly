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
use function function_exists;
use function str_ends_with;

use Bootgly\ABI\Code\__String\Tokens;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


/**
 * PHP syntax highlighter — paints a PHP source (or tagless snippet) as colored
 * terminal output via the framework's native tokenizer (ABI Tokens\Highlighter):
 * optional line-number gutter, marked line with an excerpt window, and plain
 * (escape-free) degradation on non-interactive output (set $decoration to force).
 */
class Highlighter extends Component
{
   private Output $Output;

   // * Config
   /** SGR decoration — null follows the TTY, false forces plain, true forces styled */
   public null|bool $decoration;
   /** Render the gutter (line numbers, divider, line marker) */
   public bool $gutter;
   /** Line to mark — windows the output around it */
   public null|int $mark;
   /** Window lines before the marked line */
   public int $before;
   /** Window lines after the marked line */
   public int $after;
   /** Named highlight theme — resolved from Tokens\Highlighter::$Themes */
   public string $theme;

   // * Data
   /** The PHP source — sources without an open tag are colorized as pure PHP */
   public string $source;

   // * Metadata
   /** @var array<string,false|Tokens\Highlighter> Lazy ABI engines by theme name — false marks the tokenizer absent */
   private array $Engines;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->decoration = null;
      $this->gutter = true;
      $this->mark = null;
      $this->before = 4;
      $this->after = 4;
      $this->theme = 'bootgly';

      // * Data
      $this->source = '';

      // * Metadata
      $this->Engines = [];
   }


   /**
    * Renders the highlighted source.
    *
    * @param int $mode The render mode (WRITE_OUTPUT or RETURN_OUTPUT).
    *
    * @return null|string The rendered output when returning output.
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // ! Decoration — null follows the interactive TTY
      $plain = ($this->decoration ?? BOOTGLY_TTY) === false;

      // ! Engine — lazy per theme; probed once
      $key = $plain === true ? 'plain' : $this->theme;
      $Engine = $this->Engines[$key] ??= function_exists('token_get_all') === true
         ? new Tokens\Highlighter($key)
         : false;

      // ?: Verbatim degrade without the native tokenizer
      $output = $Engine === false
         ? $this->source
         : $Engine->highlight(
              $this->source,
              marked_line: $this->mark,
              lines_before: $this->before,
              lines_after: $this->after,
              gutter: $this->gutter
           );

      // ? Guttered output already ends with an EOL; bare lines do not
      if (str_ends_with($output, "\n") === false) {
         $output .= "\n";
      }

      // ?: Output as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $output;
      }

      // ! write, not render — sources must never feed Template markup
      $this->Output->write($output);

      // :
      return null;
   }
}
