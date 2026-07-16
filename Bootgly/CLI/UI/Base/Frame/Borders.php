<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Base\Frame;


/**
 * Frame border glyph sets — each set maps the box positions to its drawing
 * characters. `None` removes the border, so the frame interior spans the full
 * rectangle.
 */
enum Borders
{
   case Sharp;
   case Round;
   case Double;
   case Heavy;
   case None;


   private const array SHARP = [
      'top'          => '─',
      'top-left'     => '┌',
      'top-right'    => '┐',

      'mid'          => '─',
      'left'         => '│',
      'right'        => '│',

      'bottom'       => '─',
      'bottom-left'  => '└',
      'bottom-right' => '┘',
   ];
   private const array ROUND = [
      'top'          => '─',
      'top-left'     => '╭',
      'top-right'    => '╮',

      'mid'          => '─',
      'left'         => '│',
      'right'        => '│',

      'bottom'       => '─',
      'bottom-left'  => '╰',
      'bottom-right' => '╯',
   ];
   private const array DOUBLE = [
      'top'          => '═',
      'top-left'     => '╔',
      'top-right'    => '╗',

      'mid'          => '═',
      'left'         => '║',
      'right'        => '║',

      'bottom'       => '═',
      'bottom-left'  => '╚',
      'bottom-right' => '╝',
   ];
   private const array HEAVY = [
      'top'          => '━',
      'top-left'     => '┏',
      'top-right'    => '┓',

      'mid'          => '━',
      'left'         => '┃',
      'right'        => '┃',

      'bottom'       => '━',
      'bottom-left'  => '┗',
      'bottom-right' => '┛',
   ];


   /**
    * Maps the border set to its position ⇒ glyph table.
    *
    * @return array<string,string> The glyphs by position — empty for `None`.
    */
   public function map (): array
   {
      // :
      return match ($this) {
         self::Sharp => self::SHARP,
         self::Round => self::ROUND,
         self::Double => self::DOUBLE,
         self::Heavy => self::HEAVY,
         self::None => []
      };
   }
}
