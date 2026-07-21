<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\Input;


use const PREG_OFFSET_CAPTURE;
use function count;
use function mb_str_split;
use function mb_strlen;
use function mb_substr;
use function ord;
use function preg_match_all;
use function str_repeat;
use function strlen;
use function substr;

use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\CLI\Terminal\Input\Keystrokes;


/**
 * Line editor engine — a pure key/buffer state machine with a virtual cursor.
 * No stream I/O: consumers own the read loop, feed printable input, control with
 * Keystrokes and render the visible slice themselves.
 */
class Line
{
   use Formattable;


   /** Truncation ellipsis */
   protected const string ELLIPSIS = '…';


   // * Config
   /** Visible columns (null renders the whole value) */
   public null|int $width;
   /** Mask rendered per character (secret input) — null renders as typed */
   public null|string $mask;

   // * Data
   public string $value;

   // * Metadata
   /** Virtual cursor position, in codepoints */
   public private(set) int $cursor;


   public function __construct ()
   {
      // * Config
      $this->width = null;
      $this->mask = null;

      // * Data
      $this->value = '';

      // * Metadata
      $this->cursor = 0;
   }


   /**
    * Inserts printable input at the virtual cursor (UTF-8 aware).
    *
    * @param string $input The printable input (control bytes are ignored).
    *
    * @return self
    */
   public function feed (string $input): self
   {
      // @@ Insert complete characters only — control bytes never enter the value
      foreach (mb_str_split($input) as $character) {
         // ? Control characters (C0 + DEL) are ignored
         if (strlen($character) === 1 && (ord($character) < 32 || ord($character) === 127)) {
            continue;
         }

         $this->value = mb_substr($this->value, 0, $this->cursor)
            . $character
            . mb_substr($this->value, $this->cursor);

         $this->cursor++;
      }

      // :
      return $this;
   }

   /**
    * Handles an edit key and reports whether the editing continues.
    *
    * @param string $key The key (raw bytes — arrows arrive as escape sequences).
    *
    * @return bool `false` on ENTER (submit); `true` otherwise.
    */
   public function control (string $key): bool
   {
      switch ($key) {
         // @ Moving
         case Keystrokes::LEFT->value:
         case Keystrokes::CTRL_B->value:
            if ($this->cursor > 0) {
               $this->cursor--;
            }
            break;
         case Keystrokes::RIGHT->value:
         case Keystrokes::CTRL_F->value:
            if ($this->cursor < mb_strlen($this->value)) {
               $this->cursor++;
            }
            break;
         case Keystrokes::HOME->value:
         case Keystrokes::CTRL_A->value:
            $this->cursor = 0;
            break;
         case Keystrokes::END->value:
         case Keystrokes::CTRL_E->value:
            $this->cursor = mb_strlen($this->value);
            break;

         // @ Erasing
         case Keystrokes::BACKSPACE->value:
         case Keystrokes::CTRL_H->value:
            if ($this->cursor > 0) {
               $this->value = mb_substr($this->value, 0, $this->cursor - 1)
                  . mb_substr($this->value, $this->cursor);

               $this->cursor--;
            }
            break;
         case Keystrokes::DELETE->value:
            $this->value = mb_substr($this->value, 0, $this->cursor)
               . mb_substr($this->value, $this->cursor + 1);
            break;
         case Keystrokes::CTRL_U->value:
            // ? Kill to the start of the line
            $this->value = mb_substr($this->value, $this->cursor);
            $this->cursor = 0;
            break;
         case Keystrokes::CTRL_K->value:
            // ? Kill to the end of the line
            $this->value = mb_substr($this->value, 0, $this->cursor);
            break;
         case Keystrokes::CTRL_W->value:
         case Keystrokes::ALT_BACKSPACE->value:
            $this->chop();
            break;

         // @ Submitting
         case Keystrokes::ENTER->value:
         case "\r":
            // :
            return false;
      }

      // :
      return true;
   }

   /**
    * Renders the visible value slice with the inverse-video virtual cursor.
    * Truncated edges render a dim `…`.
    *
    * @return string
    */
   public function render (): string
   {
      // ! Displayed characters (masked input renders the mask per character)
      $value = $this->mask !== null
         ? str_repeat($this->mask, mb_strlen($this->value))
         : $this->value;

      // ! Visible window around the cursor
      $length = mb_strlen($value);
      $first = 0;

      if ($this->width !== null && $length >= $this->width) {
         // ? Keep the cursor inside the window
         if ($this->cursor > $this->width - 1) {
            $first = $this->cursor - $this->width + 1;
         }

         $value = mb_substr($value, $first, $this->width);
      }

      // ! Inverse-video cursor cell (a space when the cursor sits at the end)
      $position = $this->cursor - $first;
      $before = mb_substr($value, 0, $position);
      $current = mb_substr($value, $position, 1);
      $after = mb_substr($value, $position + 1);

      if ($current === '') {
         $current = ' ';
      }

      // ? Raw SGR — Template style markers swallow adjacent spaces (the cell is often a space)
      $cell = self::wrap(self::_INVERSE_STYLE) . $current . self::_RESET_FORMAT;
      $rendered = "{$before}{$cell}{$after}";

      // ? Truncation ellipsis
      if ($first > 0) {
         $rendered = '@#Black:' . self::ELLIPSIS . "@;{$rendered}";
      }
      if ($this->width !== null && $first + $this->width < $length) {
         $rendered .= '@#Black:' . self::ELLIPSIS . '@;';
      }

      // :
      return $rendered;
   }

   /**
    * Resets the buffer and the cursor.
    *
    * @return self
    */
   public function reset (): self
   {
      // * Data
      $this->value = '';

      // * Metadata
      $this->cursor = 0;

      // :
      return $this;
   }

   /**
    * Chops the word before the cursor (letters/numbers run; punctuation breaks words).
    */
   private function chop (): void
   {
      // ?
      if ($this->cursor === 0) {
         return;
      }

      $before = mb_substr($this->value, 0, $this->cursor);

      // ! Start of the last letters/numbers run
      $start = 0;
      if (preg_match_all('/((?:\p{L}\p{M}*|\p{N})+)/u', $before, $matches, PREG_OFFSET_CAPTURE) > 0) {
         $last = $matches[1][count($matches[1]) - 1];
         $offset = $last[1];

         // ! Byte offset → codepoint offset
         $start = mb_strlen(substr($before, 0, $offset));
      }

      $this->value = mb_substr($this->value, 0, $start) . mb_substr($this->value, $this->cursor);
      $this->cursor = $start;
   }
}
