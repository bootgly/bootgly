<?php

namespace Bootgly\CLI\Terminal;


use function assert;
use function fopen;
use function fwrite;
use function rewind;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Keystrokes;


return new Test(
   description: 'It should normalize extended keyboard protocol reports back to their legacy keys',
   test: function () {
      /** Reads one key out of a stream preloaded with raw bytes. */
      $listen = static function (string $bytes): string|false {
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, $bytes);
         rewind($stream);

         return (new Input($stream))->listen(); // @phpstan-ignore-line
      };

      // @ Combinations with no legacy encoding keep the canonical kitty form
      yield assert(
         assertion: $listen("\e[13;2u") === Keystrokes::SHIFT_ENTER->value
            && $listen("\e[13;5u") === Keystrokes::CTRL_ENTER->value,
         description: 'Shift+Enter and Ctrl+Enter resolve to their Keystrokes cases'
      );

      // @ The xterm modifyOtherKeys form rewrites to the same canonical value
      yield assert(
         assertion: $listen("\e[27;2;13~") === Keystrokes::SHIFT_ENTER->value,
         description: 'modifyOtherKeys `CSI 27;mod;code~` normalizes to the kitty form'
      );

      // @ Everything with a legacy encoding rewrites back to it, so enabling the
      //   protocol never changes what consumers compare against
      yield assert(
         assertion: $listen("\e[13u") === Keystrokes::ENTER->value
            && $listen("\e[27u") === Keystrokes::ESCAPE->value
            && $listen("\e[127u") === Keystrokes::BACKSPACE->value,
         description: 'Unmodified keys rewrite to their legacy bytes'
      );

      yield assert(
         assertion: $listen("\e[99;5u") === Keystrokes::CTRL_C->value
            && $listen("\e[97;5u") === Keystrokes::CTRL_A->value,
         description: 'Ctrl+letter collapses back to its C0 control byte'
      );

      yield assert(
         assertion: $listen("\e[13;3u") === Keystrokes::ALT_ENTER->value
            && $listen("\e[98;3u") === Keystrokes::ALT_B->value
            && $listen("\e[9;2u") === Keystrokes::SHIFT_TAB->value,
         description: 'Alt pairs and Shift+Tab rewrite to their legacy sequences'
      );

      // @ Legacy sequences pass through untouched
      yield assert(
         assertion: $listen("\e[A") === Keystrokes::UP->value
            && $listen("\e[5~") === Keystrokes::PAGEUP->value
            && $listen("\e[1;5A") === Keystrokes::CTRL_UP->value,
         description: 'Sequences that are not protocol reports pass through'
      );

      // @ Shift + printable reconstructs the typed character — terminals on
      //   international layouts report shifted punctuation through the protocol
      yield assert(
         assertion: $listen("\e[27;2;47~") === '/'
            && $listen("\e[47;2u") === '/'
            && $listen("\e[64;2u") === '@',
         description: 'Shifted printable reports resolve to their characters'
      );

      yield assert(
         assertion: $listen("\e[27;7;47~") === '/'
            && $listen("\e[64;7u") === '@',
         description: 'AltGr (Ctrl+Alt) printable reports resolve to their characters'
      );

      // @ Kitty keypad reports translate to their main-keyboard equivalents
      //   (VSCode's xterm.js sends KP_DIVIDE for a numpad `/`)
      yield assert(
         assertion: $listen("\e[57410u") === '/'
            && $listen("\e[57399u") === '0'
            && $listen("\e[57408u") === '9'
            && $listen("\e[57413u") === '+',
         description: 'Keypad characters resolve to their typed characters'
      );

      yield assert(
         assertion: $listen("\e[57414u") === Keystrokes::ENTER->value
            && $listen("\e[57414;2u") === Keystrokes::SHIFT_ENTER->value,
         description: 'Keypad Enter behaves as Enter — Shift included'
      );

      yield assert(
         assertion: $listen("\e[57419u") === Keystrokes::UP->value
            && $listen("\e[57426u") === Keystrokes::DELETE->value,
         description: 'Unmodified keypad navigation rewrites to its legacy sequence'
      );

      // @ Unknown reports stay verbatim rather than becoming a wrong key
      yield assert(
         assertion: $listen("\e[57441;2u") === "\e[57441;2u",
         description: 'Unmapped key codes pass through unchanged'
      );

      // @ The negotiation is opt-in — it changes how the terminal encodes keys
      //   for the whole session, and support varies
      $Input = new Input(fopen('php://memory', 'r+')); // @phpstan-ignore-line

      yield assert(
         assertion: $Input->extended === false,
         description: 'The extended keyboard protocol is not negotiated by default'
      );
   }
);
