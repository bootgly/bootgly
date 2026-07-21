<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use const STR_PAD_BOTH;
use const STR_PAD_LEFT;
use const STR_PAD_RIGHT;
use function count;
use function explode;
use function function_exists;
use function implode;
use function intdiv;
use function max;
use function mb_strwidth;
use function preg_replace;
use function str_pad;
use function str_repeat;
use function strlen;
use function strtolower;

use Bootgly\ABI\Code\__String;
use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Code\__String\Markdown as Parser;
use Bootgly\ABI\Code\__String\Markdown\Blocks;
use Bootgly\ABI\Code\__String\Markdown\Inlines;
use Bootgly\ABI\Code\__String\Markdown\Node;
use Bootgly\ABI\Code\__String\Tokens\Highlighter;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;


/**
 * Markdown renderer — paints a markdown string as styled terminal output:
 * headings, wrapped paragraphs, emphasis, code (inline + fenced), quotes,
 * nested lists (+ tasks), tables and rules. Styling is raw SGR — untrusted
 * markdown is never routed through Template markup — and non-interactive
 * output degrades to plain structured text (set $decoration to force).
 */
class Markdown extends Component
{
   use Formattable;


   private Output $Output;

   // * Config
   /** Render width in columns — null resolves the terminal width */
   public null|int $width;
   /** SGR decoration — null follows the TTY, false forces plain, true forces styled */
   public null|bool $decoration;
   /** @var array<string,array<int,string>> SGR code lists per element key */
   public array $styles;
   /** @var array<string,callable(string $source): (null|string)> Fence highlighters by lowercase language infoword */
   public array $Highlighters;

   // * Data
   /** The markdown source */
   public string $source;

   // * Metadata
   private Parser $Parser;
   /** Undecorated rendering — resolved per render() */
   private bool $plain;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->width = null;
      $this->decoration = null;
      $this->styles = [
         'h1'        => [self::_BOLD_STYLE, self::_UNDERLINE_STYLE, self::_CYAN_BRIGHT_FOREGROUND],
         'h2'        => [self::_BOLD_STYLE, self::_CYAN_BRIGHT_FOREGROUND],
         'h3'        => [self::_BOLD_STYLE, self::_CYAN_FOREGROUND],
         'h4'        => [self::_BOLD_STYLE],
         'h5'        => [self::_BOLD_STYLE, self::_BLACK_BRIGHT_FOREGROUND],
         'h6'        => [self::_BLACK_BRIGHT_FOREGROUND],
         'bold'      => [self::_BOLD_STYLE],
         'italic'    => [self::_ITALIC_STYLE],
         'strike'    => [self::_STRIKE_STYLE],
         'code'      => [self::_YELLOW_FOREGROUND],
         'fence'     => [self::_BLACK_BRIGHT_FOREGROUND],
         'source'    => [self::_DIM_STYLE],
         'link'      => [self::_UNDERLINE_STYLE, self::_BLUE_BRIGHT_FOREGROUND],
         'url'       => [self::_DIM_STYLE],
         'image'     => [self::_MAGENTA_FOREGROUND],
         'quote'     => [self::_BLACK_BRIGHT_FOREGROUND],
         'marker'    => [self::_CYAN_FOREGROUND],
         'checked'   => [self::_GREEN_FOREGROUND],
         'unchecked' => [self::_BLACK_BRIGHT_FOREGROUND],
         'rule'      => [self::_BLACK_BRIGHT_FOREGROUND],
         'header'    => [self::_BOLD_STYLE],
         'border'    => [self::_BLACK_BRIGHT_FOREGROUND]
      ];
      $this->Highlighters = [
         'php' => static function (string $source): null|string {
            // ! Lazy engine — probed once; false marks the tokenizer absent
            static $Highlighter = null;
            $Highlighter ??= function_exists('token_get_all') === true ? new Highlighter : false;

            // ?
            if ($Highlighter === false) {
               return null;
            }

            // :
            return $Highlighter->highlight($source, gutter: false);
         }
      ];

      // * Data
      $this->source = '';

      // * Metadata
      $this->Parser = new Parser;
      $this->plain = true;
   }


   /**
    * Renders the markdown source.
    *
    * @param int $mode The render mode (WRITE_OUTPUT or RETURN_OUTPUT).
    *
    * @return null|string The rendered output when returning output.
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // ! Decoration — null follows the interactive TTY
      $this->plain = ($this->decoration ?? BOOTGLY_TTY) === false;
      // ! Width — configured, else the terminal, else a safe floor
      $width = $this->width ?? (isSet(Terminal::$width) === true ? Terminal::$width : 80);
      $width = max(20, $width);

      // @ Parse and paint
      $Blocks = $this->Parser->parse($this->source);
      $lines = $this->walk($Blocks, $width);

      $output = implode("\n", $lines) . "\n";

      // ?: Output as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $output;
      }

      // ! write, not render — markdown content must never feed Template markup
      $this->Output->write($output);

      // :
      return null;
   }

   /**
    * Walks blocks into painted lines.
    *
    * @param array<int,Node> $Nodes The blocks.
    * @param int $width The available columns.
    * @param bool $spaced Separate sibling blocks with a blank line.
    *
    * @return array<int,string>
    */
   private function walk (array $Nodes, int $width, bool $spaced = true): array
   {
      /** @var array<int,string> $lines */
      $lines = [];

      // @@ Blocks
      foreach ($Nodes as $Node) {
         // ? Sibling blocks separate with one blank line (tight lists do not)
         if ($spaced === true && $lines !== []) {
            $lines[] = '';
         }

         switch ($Node->type) {
            // # Headings — the # prefix stays visible in both modes
            case Blocks::Heading:
               $key = "h{$Node->level}";
               $codes = $this->plain === true ? [] : ($this->styles[$key] ?? []);

               $content = str_repeat('#', $Node->level) . ' '
                  . $this->style($Node->Children, $codes === [] ? [] : [$codes]);

               if ($codes !== []) {
                  $content = self::wrap(...$codes) . $content . self::_RESET_FORMAT;
               }

               // @phpstan-ignore-next-line -- wrap() resolves via __callStatic (pad precedent)
               foreach (explode("\n", (string) __String::wrap($content, $width)) as $piece) {
                  $lines[] = $piece;
               }

               break;
            // # Paragraphs — wrapped to the width
            case Blocks::Paragraph:
               $content = $this->style($Node->Children);

               // @phpstan-ignore-next-line -- wrap() resolves via __callStatic (pad precedent)
               foreach (explode("\n", (string) __String::wrap($content, $width)) as $piece) {
                  $lines[] = $piece;
               }

               break;
            // # Fenced code
            case Blocks::Fence:
               foreach ($this->code($Node) as $piece) {
                  $lines[] = $piece;
               }

               break;
            // # Blockquotes — recursive with a painted gutter
            case Blocks::Quote:
               $gutter = $this->paint('│ ', 'quote');

               foreach ($this->walk($Node->Children, max(1, $width - 2)) as $piece) {
                  $lines[] = $gutter . $piece;
               }

               break;
            // # Lists
            case Blocks::List:
               $counter = $Node->start;
               // ! Marker column — ordered lists align to the widest number
               $column = $Node->ordered === true
                  ? strlen((string) ($Node->start + count($Node->Children) - 1)) + 2
                  : 2;

               foreach ($Node->Children as $Item) {
                  // ! Marker
                  if ($Item->checked !== null) {
                     $marker = $Item->checked === true ? '[✓] ' : '[ ] ';
                     $key = $Item->checked === true ? 'checked' : 'unchecked';
                     $indent = 4;
                  }
                  else if ($Node->ordered === true) {
                     $marker = str_pad("{$counter}.", $column - 1) . ' ';
                     $key = 'marker';
                     $indent = $column;

                     $counter++;
                  }
                  else {
                     $marker = '• ';
                     $key = 'marker';
                     $indent = 2;
                  }

                  $inner = $this->walk($Item->Children, max(1, $width - $indent), spaced: false);

                  // ? Empty items still occupy a line
                  if ($inner === []) {
                     $lines[] = $this->paint($marker, $key);

                     continue;
                  }

                  foreach ($inner as $position => $piece) {
                     $lines[] = ($position === 0 ? $this->paint($marker, $key) : str_repeat(' ', $indent))
                        . $piece;
                  }
               }

               break;
            // # Tables
            case Blocks::Table:
               foreach ($this->tabulate($Node) as $piece) {
                  $lines[] = $piece;
               }

               break;
            // # Horizontal rules
            case Blocks::Rule:
               $lines[] = $this->paint(str_repeat('─', $width), 'rule');

               break;
         }
      }

      // :
      return $lines;
   }

   /**
    * Styles inline nodes into one string. $open carries the SGR code lists
    * currently open in the enclosing context — closing an inner style resets
    * and reopens them, so nesting never bleeds.
    *
    * @param array<int,Node> $Nodes The inline nodes.
    * @param array<int,array<int,string>> $open The open SGR code lists.
    *
    * @return string
    */
   private function style (array $Nodes, array $open = []): string
   {
      $styled = '';

      // @@ Inlines
      foreach ($Nodes as $Node) {
         switch ($Node->type) {
            case Inlines::Text:
               $styled .= $this->clean($Node->text);

               break;
            case Inlines::Bold:
            case Inlines::Italic:
            case Inlines::Strike:
               $key = match ($Node->type) {
                  Inlines::Bold => 'bold',
                  Inlines::Italic => 'italic',
                  default => 'strike'
               };

               // ? Plain output unwraps the emphasis
               if ($this->plain === true) {
                  $styled .= $this->style($Node->Children, $open);

                  break;
               }

               $codes = $this->styles[$key] ?? [];

               $styled .= self::wrap(...$codes)
                  . $this->style($Node->Children, [...$open, $codes])
                  . $this->restore($open);

               break;
            case Inlines::Code:
               $code = $this->clean($Node->text);

               // ? Plain output keeps the backticks for readability
               if ($this->plain === true) {
                  $styled .= "`{$code}`";

                  break;
               }

               $styled .= self::wrap(...$this->styles['code'] ?? [])
                  . $code
                  . $this->restore($open);

               break;
            case Inlines::Link:
               $URL = $this->clean((string) $Node->URL);

               if ($this->plain === true) {
                  $styled .= $this->style($Node->Children, $open) . " ({$URL})";

                  break;
               }

               $codes = $this->styles['link'] ?? [];

               $styled .= self::wrap(...$codes)
                  . $this->style($Node->Children, [...$open, $codes])
                  . $this->restore($open);
               $styled .= ' '
                  . self::wrap(...$this->styles['url'] ?? [])
                  . "({$URL})"
                  . $this->restore($open);

               break;
            case Inlines::Image:
               $alt = $this->clean($Node->text);
               $URL = $this->clean((string) $Node->URL);

               if ($this->plain === true) {
                  $styled .= "[{$alt}] ({$URL})";

                  break;
               }

               $styled .= self::wrap(...$this->styles['image'] ?? [])
                  . "[{$alt}]"
                  . $this->restore($open);
               $styled .= ' '
                  . self::wrap(...$this->styles['url'] ?? [])
                  . "({$URL})"
                  . $this->restore($open);

               break;
            case Inlines::Break:
               $styled .= "\n";

               break;
         }
      }

      // :
      return $styled;
   }

   /**
    * Paints one atomic piece with a styles key — the single decoration seam:
    * plain output returns the text unchanged.
    *
    * @param string $text The piece (no inner styles).
    * @param string $key The styles key.
    *
    * @return string
    */
   private function paint (string $text, string $key): string
   {
      // ? Plain output stays undecorated
      if ($this->plain === true) {
         return $text;
      }

      $codes = $this->styles[$key] ?? [];
      if ($codes === []) {
         return $text;
      }

      // :
      return self::wrap(...$codes) . $text . self::_RESET_FORMAT;
   }

   /**
    * Restores the enclosing styles — a reset followed by reopening every
    * still-open SGR code list.
    *
    * @param array<int,array<int,string>> $open The open SGR code lists.
    *
    * @return string
    */
   private function restore (array $open): string
   {
      $sequence = self::_RESET_FORMAT;

      foreach ($open as $codes) {
         $sequence .= self::wrap(...$codes);
      }

      // :
      return $sequence;
   }

   /**
    * Paints a fenced code block — never wrapped. `php` fences colorize via the
    * pluggable $Highlighters map (ABI Tokens\Highlighter); other languages,
    * plain output and declined highlighters fall back to verbatim dimmed lines.
    *
    * @param Node $Fence The fence node.
    *
    * @return array<int,string>
    */
   private function code (Node $Fence): array
   {
      $lines = [];
      $lines[] = $this->paint("```{$Fence->language}", 'fence');

      // ! Untrusted source — ESC/C0 stripped before any rendering path
      $source = $this->clean($Fence->text);

      // ?: Decorated output delegates to the language highlighter when one is plugged
      if ($Fence->text !== '' && $this->plain === false) {
         $highlight = $this->Highlighters[strtolower($Fence->language)] ?? null;
         $highlighted = $highlight !== null ? $highlight($source) : null;

         if ($highlighted !== null) {
            foreach (explode("\n", $highlighted) as $line) {
               $lines[] = $line;
            }

            $lines[] = $this->paint('```', 'fence');

            // :
            return $lines;
         }
      }

      // @@ Verbatim content — dimmed fallback
      foreach ($Fence->text === '' ? [] : explode("\n", $source) as $line) {
         $lines[] = $this->paint($line, 'source');
      }

      $lines[] = $this->paint('```', 'fence');

      // :
      return $lines;
   }

   /**
    * Paints a table — a light grid at natural width: per-column alignment,
    * bold header, dimmed borders.
    *
    * @param Node $Table The table node.
    *
    * @return array<int,string>
    */
   private function tabulate (Node $Table): array
   {
      // ! Styled cells with their visible widths
      /** @var array<int,array<int,array{0:string,1:int}>> $rows */
      $rows = [];
      /** @var array<int,int> $widths */
      $widths = [];

      foreach ($Table->Children as $Row) {
         $cells = [];

         foreach ($Row->Children as $index => $Cell) {
            $content = $this->style($Cell->Children);

            $visible = (string) preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $content);
            $span = mb_strwidth($visible);

            $cells[] = [$content, $span];
            $widths[$index] = max($widths[$index] ?? 0, $span);
         }

         $rows[] = $cells;
      }

      // ! Alignment per column
      /** @var array<int,int> $alignments */
      $alignments = [];
      foreach ($Table->alignments as $index => $alignment) {
         $alignments[$index] = match ($alignment) {
            'center' => STR_PAD_BOTH,
            'right' => STR_PAD_LEFT,
            default => STR_PAD_RIGHT
         };
      }

      // @@ Rows
      $lines = [];
      $bar = $this->paint('│', 'border');

      foreach ($rows as $position => $cells) {
         $pieces = [];

         foreach ($cells as $index => [$content, $span]) {
            // ? Header cells embolden
            if ($position === 0 && $this->plain === false) {
               $content = self::wrap(...$this->styles['header'] ?? [])
                  . $content
                  . self::_RESET_FORMAT;
            }

            // ! Pad by visible width
            $missing = max(0, ($widths[$index] ?? 0) - $span);
            $left = intdiv($missing, 2);

            $pieces[] = match ($alignments[$index] ?? STR_PAD_RIGHT) {
               STR_PAD_LEFT => str_repeat(' ', $missing) . $content,
               STR_PAD_BOTH => str_repeat(' ', $left) . $content . str_repeat(' ', $missing - $left),
               default => $content . str_repeat(' ', $missing)
            };
         }

         $lines[] = ' ' . implode(" {$bar} ", $pieces);

         // ? Separator under the header
         if ($position === 0) {
            $segments = [];
            foreach ($widths as $span) {
               $segments[] = str_repeat('─', $span + 2);
            }

            $lines[] = $this->paint(implode('┼', $segments), 'border');
         }
      }

      // :
      return $lines;
   }

   /**
    * Cleans untrusted text — raw control bytes (including ESC) never reach
    * the output, so source markdown cannot inject cursor/SGR sequences.
    *
    * @param string $text The raw text.
    *
    * @return string
    */
   private function clean (string $text): string
   {
      // :
      return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text);
   }
}
