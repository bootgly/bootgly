<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\Output;


use function preg_replace_callback;
use function str_ends_with;

use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\CLI\Terminal\Output;


/**
 * A nested output region — every row a component writes is shifted right and
 * carries a painted left gutter (e.g. the Wizard's `│  ` guide). The byte
 * stream is translated on the fly: line breaks re-enter after the gutter,
 * column-absolute moves shift by the region offset, previous-line moves land
 * on the region column and line erases repaint the gutter — so components
 * using the block-repaint idiom render inside the region untouched, never
 * aware they are embedded.
 */
class Region extends Output
{
   // * Config
   /** The painted left gutter injected at every region row */
   public private(set) string $gutter;
   /** Visible gutter width — the region column offset */
   public private(set) int $offset;


   /**
    * @param resource $stream The host output stream (shared with the outer Output).
    * @param string $gutter The painted left gutter (SGR allowed).
    * @param int $offset The visible gutter width, in columns.
    */
   public function __construct ($stream, string $gutter, int $offset)
   {
      parent::__construct($stream);

      // * Config
      $this->gutter = $gutter;
      $this->offset = $offset;
   }


   public function write (string $data, int $times = 1): self
   {
      parent::write($this->nest($data), $times);

      return $this;
   }

   public function render (string $data): self
   {
      parent::write($this->nest(TemplateEscaped::render($data)));

      return $this;
   }

   public function escape (string $data): self
   {
      parent::write($this->nest(self::_START_ESCAPE . $data));

      return $this;
   }

   /**
    * Translates a component byte stream into the region:
    * line breaks re-enter after the gutter, `CSI n F` (previous line) lands on
    * the region column, `CSI n G` (column absolute) shifts by the offset and
    * `CSI 2 K` (line erase) repaints the gutter.
    *
    * @param string $bytes The raw component output.
    *
    * @return string
    */
   private function nest (string $bytes): string
   {
      $column = $this->offset + 1;

      // :
      return (string) preg_replace_callback(
         '/\r\n|\n|\r|\e\[(\d*)F|\e\[(\d*)G|\e\[2K/',
         function (array $match) use ($column): string {
            $sequence = $match[0];

            // ? Line breaks re-enter the region after the gutter
            if ($sequence === "\n" || $sequence === "\r\n" || $sequence === "\r") {
               return "{$sequence}{$this->gutter}";
            }
            // ? Line erase repaints the gutter, resting on the region column
            if ($sequence === "\e[2K") {
               return "\e[2K\e[1G{$this->gutter}";
            }
            // ? Previous-line moves land on the region column
            if (($match[1] ?? '') !== '' || str_ends_with($sequence, 'F')) {
               $rows = (int) (($match[1] ?? '') !== '' ? $match[1] : 1);

               if (str_ends_with($sequence, 'F') === true) {
                  return "\e[{$rows}A\e[{$column}G";
               }
            }
            // ? Column-absolute moves shift by the region offset
            $target = (int) (($match[2] ?? '') !== '' ? $match[2] : 1) + $this->offset;

            // :
            return "\e[{$target}G";
         },
         $bytes
      );
   }
}
