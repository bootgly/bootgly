<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Chart;


/**
 * Graph symbol sets — each character encodes a (previous level, current level) pair,
 * levels 0-4, as a flat 5×5 map indexed `previous * 5 + current`.
 * Braille packs 2 values per cell (2 dot sub-columns × 4 dots); Block uses quadrants;
 * TTY degrades to shade characters for limited terminals.
 */
enum Symbols
{
   case Braille;
   case Block;
   case TTY;


   /** Level ramp for one-line sparklines (8 levels) */
   public const array RAMP = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
   /** Meter cell */
   public const string METER = '■';

   /**
    * Braille cells derive from the Unicode braille pattern (U+2800 + dot bitmask):
    * left sub-column fills dots 7,3,2,1 bottom-up (0x00,0x40,0x44,0x46,0x47) and
    * right sub-column fills dots 8,6,5,4 (0x00,0x80,0xA0,0xB0,0xB8).
    */
   private const array BRAILLE_UP = [
      ' ', '⢀', '⢠', '⢰', '⢸',
      '⡀', '⣀', '⣠', '⣰', '⣸',
      '⡄', '⣄', '⣤', '⣴', '⣼',
      '⡆', '⣆', '⣦', '⣶', '⣾',
      '⡇', '⣇', '⣧', '⣷', '⣿'
   ];
   /** Braille cells filling top-down (inverted graphs) — dots 1,2,3,7 / 4,5,6,8 mirrored */
   private const array BRAILLE_DOWN = [
      ' ', '⠈', '⠘', '⠸', '⢸',
      '⠁', '⠉', '⠙', '⠹', '⢹',
      '⠃', '⠋', '⠛', '⠻', '⢻',
      '⠇', '⠏', '⠟', '⠿', '⢿',
      '⡇', '⡏', '⡟', '⡿', '⣿'
   ];
   /**
    * Quadrant cells — per half: level 0 = empty, 1-2 = bottom quadrant, 3-4 = full half.
    */
   private const array BLOCK_UP = [
      ' ', '▗', '▗', '▐', '▐',
      '▖', '▄', '▄', '▟', '▟',
      '▖', '▄', '▄', '▟', '▟',
      '▌', '▙', '▙', '█', '█',
      '▌', '▙', '▙', '█', '█'
   ];
   /** Quadrant cells filling top-down (inverted graphs) */
   private const array BLOCK_DOWN = [
      ' ', '▝', '▝', '▐', '▐',
      '▘', '▀', '▀', '▜', '▜',
      '▘', '▀', '▀', '▜', '▜',
      '▌', '▛', '▛', '█', '█',
      '▌', '▛', '▛', '█', '█'
   ];
   /**
    * Shade cells — bucketed by the rounded mean of both levels:
    * 0 = space, 1 = light, 2 = medium, 3-4 = full.
    */
   private const array TTY_UP = [
      ' ', '░', '░', '▒', '▒',
      '░', '░', '▒', '▒', '█',
      '░', '▒', '▒', '█', '█',
      '▒', '▒', '█', '█', '█',
      '▒', '█', '█', '█', '█'
   ];
   /** Shade cells are direction-agnostic — the down map mirrors the up map */
   private const array TTY_DOWN = self::TTY_UP;


   /**
    * Maps the symbol set to its flat 5×5 character table.
    *
    * @param bool $inverted `true` fills top-down (down-graphs).
    *
    * @return array<int,string> 25 characters indexed `previous * 5 + current`.
    */
   public function map (bool $inverted = false): array
   {
      // :
      return match ($this) {
         self::Braille => $inverted ? self::BRAILLE_DOWN : self::BRAILLE_UP,
         self::Block => $inverted ? self::BLOCK_DOWN : self::BLOCK_UP,
         self::TTY => $inverted ? self::TTY_DOWN : self::TTY_UP
      };
   }
}
