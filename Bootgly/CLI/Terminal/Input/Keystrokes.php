<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\Input;


use function chr;
use function preg_match;
use function str_ends_with;


enum Keystrokes : string
{
   case BACKSPACE = "\177";
   case ESCAPE    = "\e";
   case ENTER     = "\n";
   case TAB       = "\t";
   case SPACE     = " ";

   case UP        = "\e[A";
   case DOWN      = "\e[B";
   case RIGHT     = "\e[C";
   case LEFT      = "\e[D";

   case HOME      = "\e[H";
   case INSERT    = "\e[2~";
   case DELETE    = "\e[3~";
   case END       = "\e[F";
   case PAGEUP    = "\e[5~";
   case PAGEDOWN  = "\e[6~";

   case F1  = "\eOP";
   case F2  = "\eOQ";
   case F3  = "\eOR";
   case F4  = "\eOS";
   case F5  = "\e[15~";
   case F6  = "\e[17~";
   case F7  = "\e[18~";
   case F8  = "\e[19~";
   case F9  = "\e[20~";
   case F10 = "\e[21~";
   case F11 = "\e[23~";
   case F12 = "\e[24~";

   // @ Combined keys
   // CTRL + [key]
   case CTRL_A = "\x01";
   case CTRL_B = "\x02";
   case CTRL_C = "\x03";
   case CTRL_D = "\x04";
   case CTRL_E = "\x05";
   case CTRL_F = "\x06";
   case CTRL_G = "\x07";
   case CTRL_H = "\x08"; // \b
   #case CTRL_I = "\x09"; // (duplicated with TAB)
   #case CTRL_J = "\x0A"; // (duplicated with ENTER)
   case CTRL_K = "\x0B";
   case CTRL_L = "\x0C"; // \f
   case CTRL_M = "\x0D"; // \r (CR — Enter on raw terminals without icrnl)
   case CTRL_N = "\x0E";
   case CTRL_O = "\x0F";
   case CTRL_P = "\x10";
   case CTRL_Q = "\x11";
   case CTRL_R = "\x12";
   case CTRL_S = "\x13";
   case CTRL_T = "\x14";
   case CTRL_U = "\x15";
   case CTRL_V = "\x16";
   case CTRL_W = "\x17";
   case CTRL_X = "\x18";
   case CTRL_Y = "\x19";
   case CTRL_Z = "\x1A";

   case CTRL_UP    = "\e[1;5A";
   case CTRL_DOWN  = "\e[1;5B";
   case CTRL_RIGHT = "\e[1;5C";
   case CTRL_LEFT  = "\e[1;5D";

   case CTRL_BACKSLASH     = "\x1C"; // Ctrl + \
   #case CTRL_LEFT_BRACKET = "\x1B"; // Ctrl + [ (duplicated with ESCAPE)
   case CTRL_RIGHT_BRACKET = "\x1D"; // Ctrl + ]
   case CTRL_UNDERSCORE    = "\x1F"; // Ctrl + _
   case CTRL_AT            = "\x00"; // Ctrl + @
   case CTRL_CIRCUMFLEX    = "\x1E"; // Ctrl + ^

   // SHIFT + [key]
   case SHIFT_TAB = "\e[Z";

   case SHIFT_UP    = "\e[1;2A";
   case SHIFT_DOWN  = "\e[1;2B";
   case SHIFT_RIGHT = "\e[1;2C";
   case SHIFT_LEFT  = "\e[1;2D";

   // ALT + [key]
   case ALT_UP    = "\e[1;3A";
   case ALT_DOWN  = "\e[1;3B";
   case ALT_RIGHT = "\e[1;3C";
   case ALT_LEFT  = "\e[1;3D";

   case ALT_INSERT    = "\e[2;3~"; // Alt + Insert
   case ALT_DELETE    = "\e[3;3~"; // Alt + Delete
   case ALT_HOME      = "\e[1;3H"; // Alt + Home
   case ALT_END       = "\e[1;3F"; // Alt + End
   case ALT_PAGEUP    = "\e[5;3~"; // Alt + Page Up
   case ALT_PAGEDOWN  = "\e[6;3~"; // Alt + Page Down

   case ALT_ENTER     = "\e\r";    // Alt + Enter
   case ALT_BACKSPACE = "\e\x7F";  // Alt + Backspace
   case ALT_B         = "\eb";     // Alt + B (word backward)
   case ALT_F         = "\ef";     // Alt + F (word forward)

   // SHIFT/CTRL + Enter — no legacy encoding exists (a plain terminal sends CR
   // for all three), so these only arrive under the extended keyboard protocol.
   // The kitty `CSI code ; modifiers u` form is the canonical value: normalize()
   // rewrites the xterm modifyOtherKeys form into it.
   case SHIFT_ENTER = "\e[13;2u";
   case CTRL_ENTER  = "\e[13;5u";


   /**
    * Normalizes an extended keyboard protocol sequence into the canonical key.
    * Combinations that already have a legacy encoding rewrite to it — so
    * enabling the protocol never changes what consumers compare against — and
    * the ones that do not keep the kitty `CSI u` form. Anything else passes
    * through untouched.
    *
    * Both forms are accepted: kitty `CSI code ; modifiers u` and xterm
    * modifyOtherKeys `CSI 27 ; modifiers ; code ~`. Modifiers are the usual
    * 1-based bitmask (1 none, +1 shift, +2 alt, +4 ctrl).
    *
    * Only ASCII key codes reconstruct a legacy byte: with the disambiguate flag
    * a terminal reports text keys in their legacy UTF-8 encoding anyway.
    *
    * @param string $sequence The assembled key sequence.
    *
    * @return string
    */
   public static function normalize (string $sequence): string
   {
      // ? Only the extended protocols end this way (cheap hot-path guard)
      if (str_ends_with($sequence, 'u') === false && str_ends_with($sequence, '~') === false) {
         // :
         return $sequence;
      }

      // ! Parse both forms into (code, modifiers)
      if (preg_match('/^\e\[(\d+)(?:;(\d+))?u$/', $sequence, $matches) === 1) {
         $code = (int) $matches[1];
         $modifiers = (int) ($matches[2] ?? 1);
      }
      else if (preg_match('/^\e\[27;(\d+);(\d+)~$/', $sequence, $matches) === 1) {
         $modifiers = (int) $matches[1];
         $code = (int) $matches[2];
      }
      else {
         // :
         return $sequence;
      }

      // ! Kitty functional keys — the keypad translates to its main-keyboard
      //   equivalent (a numpad `/` arrives as KP_DIVIDE 57410 once the protocol
      //   is on) and then flows through the regular reconstruction below
      $code = match ($code) {
         57399, 57400, 57401, 57402, 57403,
         57404, 57405, 57406, 57407, 57408 => $code - 57351, // KP_0..KP_9 → '0'..'9'
         57409 => 46,  // KP_DECIMAL   → '.'
         57410 => 47,  // KP_DIVIDE    → '/'
         57411 => 42,  // KP_MULTIPLY  → '*'
         57412 => 45,  // KP_SUBTRACT  → '-'
         57413 => 43,  // KP_ADD       → '+'
         57414 => 13,  // KP_ENTER     → Enter
         57415 => 61,  // KP_EQUAL     → '='
         57416 => 44,  // KP_SEPARATOR → ','
         default => $code
      };

      // ? Unmodified keypad navigation rewrites to its legacy sequence
      if ($code >= 57417 && $code <= 57426 && $modifiers <= 1) {
         // :
         return match ($code) {
            57417 => self::LEFT->value,
            57418 => self::RIGHT->value,
            57419 => self::UP->value,
            57420 => self::DOWN->value,
            57421 => self::PAGEUP->value,
            57422 => self::PAGEDOWN->value,
            57423 => self::HOME->value,
            57424 => self::END->value,
            57425 => self::INSERT->value,
            57426 => self::DELETE->value
         };
      }

      // ? Non-ASCII keys keep their legacy encoding — nothing to reconstruct
      if ($code > 127) {
         // :
         return $sequence;
      }

      // ! Modifier bitmask (0 = unmodified)
      $held = $modifiers > 0 ? $modifiers - 1 : 0;

      // ---

      // ? Unmodified — the key is its own legacy byte
      if ($held === 0) {
         // :
         return $code === 13 ? self::ENTER->value : chr($code);
      }

      // ? Ctrl + letter collapses to its C0 control byte (Ctrl+A = \x01)
      if ($held === 4 && $code >= 97 && $code <= 122) {
         // :
         return chr($code - 96);
      }

      // ? Alt + key is the ESC-prefixed legacy pair
      if ($held === 2 && $code !== 13) {
         // :
         return "\e" . chr($code);
      }

      // :
      return match (true) {
         $held === 1 && $code === 9  => self::SHIFT_TAB->value,
         $held === 1 && $code === 13 => self::SHIFT_ENTER->value,
         $held === 2 && $code === 13 => self::ALT_ENTER->value,
         $held === 4 && $code === 13 => self::CTRL_ENTER->value,
         // ? Shift (or AltGr = Ctrl+Alt) + printable — the code is the resolved
         //   grapheme (international layouts reach punctuation through these
         //   modifiers; dropping the sequence would swallow the typed character)
         ($held === 1 || $held === 6) && $code >= 32 && $code <= 126 => chr($code),
         default => $sequence
      };
   }
}
