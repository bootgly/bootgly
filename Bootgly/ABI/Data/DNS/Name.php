<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\DNS;


use const PHP_INT_SIZE;
use function chr;
use function count;
use function explode;
use function implode;
use function is_int;
use function is_string;
use function ord;
use function sprintf;
use function str_contains;
use function strlen;
use function strspn;
use function substr;

use RuntimeException;


/**
 * One domain name, between its presentation form and the wire (RFC 1035
 * §§3.1, 4.1.4).
 *
 * Presentation form: labels joined by `.`, without the trailing dot; `''` is
 * the root. Inside a label, a `.`, a `\` and every byte outside `!`–`~` are
 * escaped (`\.`, `\\`, `\DDD` with three decimal digits), so the text names
 * one wire name only — a label that holds a dot never reads as two labels.
 * Case is kept as sent: compare names by their wire form (`encode()`), with
 * `strcasecmp()` (RFC 4343) or exactly when the asker randomized the case.
 *
 * `encode()` writes the flat wire form (no compression: `DNS::encode()` owns
 * the compression table of a message). `decode()` follows compression
 * pointers, only backwards (to a prior occurrence) and never into the name
 * being read, so any chain ends.
 */
final class Name
{
   /** Bytes a label holds at most (RFC 1035 §2.3.4). */
   public const int LABEL = 63;
   /** Wire bytes a name holds at most: length bytes, labels and root (RFC 1035 §2.3.4). */
   public const int LIMIT = 255;
   /** Compression pointers one name may follow before it is refused as a loop. */
   public const int JUMPS = 128;
   /** Characters of presentation text the names read with one memo (one message) add up to at most. */
   public const int BUDGET = 1048576;
   /** Label bytes shown as they are: `!`–`~` without `.` and `\`. */
   private const string PLAIN = '!"#$%&\'()*+,-/0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[]^_`abcdefghijklmnopqrstuvwxyz{|}~';
   /** Size of the message header: a pointer below it is refused. */
   private const int HEADER = 12;
   /** Last position a name can reach after a pointer: the largest target + a 255-byte name − 1. */
   private const int REACH = 0x3FFF + 254;


   /**
    * Write one name to its flat wire form.
    *
    * `''` and `'.'` are the root (`"\x00"`); one unescaped trailing dot is
    * dropped (`example.com.` writes as `example.com`). Escapes: `\DDD` is the
    * byte of that decimal value (exactly three digits, at most 255); `\X` is
    * `X` itself for any other byte.
    *
    * @param string $name Name in presentation form.
    *
    * @return string|Faults The wire bytes; `Faults::Label` for an empty
    *    inner label or one over 63 bytes, `Faults::Name` for a malformed
    *    escape or a name over 255 wire bytes.
    */
   public static function encode (string $name): string|Faults
   {
      // ? The root
      if ($name === '' || $name === '.') {
         return "\x00";
      }

      // ! Labels
      if (str_contains($name, '\\') === false) {
         // # Plain name: split on dots
         if ($name[strlen($name) - 1] === '.') {
            $name = substr($name, 0, -1);
         }
         $labels = explode('.', $name);
      }
      else {
         // # Escaped name: read it byte by byte
         $labels = self::split($name);
         if ($labels instanceof Faults) {
            return $labels;
         }
      }

      // @@ Length byte + label bytes
      $wire = '';
      foreach ($labels as $label) {
         $size = strlen($label);
         if ($size === 0 || $size > self::LABEL) {
            return Faults::Label;
         }
         $wire .= chr($size) . $label;
      }
      $wire .= "\x00";

      // ? Whole-name bound
      if (strlen($wire) > self::LIMIT) {
         return Faults::Name;
      }

      // :
      return $wire;
   }

   /**
    * Read one name from a message, following compression pointers.
    *
    * On success `$offset` moves past the name's bytes at that place — after
    * its root byte, or after its first pointer — and the name is returned in
    * presentation form. On a fault `$offset` is left as it was. When a name
    * breaks two bounds at once, which of their faults is returned is not
    * specified.
    *
    * @param string $bytes The whole message (pointers are offsets into it).
    * @param int $offset Where the name starts; moved past it on success.
    * @param array<int, int|string> $suffixes The message's memo — pass the
    *    same array, empty at first, for every name of one message and never
    *    across messages; leave it out otherwise. Every position a pointer can
    *    reach is kept once a name is read through it, so after a pointer each
    *    position is read once per message, however many pointers reach it;
    *    and the names read with it add up to `BUDGET` characters at most. Its
    *    entries are internal.
    *
    * @return string|Faults The name; `Faults::Overrun` past the end (or for
    *    a negative `$offset`), `Faults::Label` for a reserved label type,
    *    `Faults::Name` over 255 wire bytes, `Faults::Forward` for a pointer at
    *    or after itself, `Faults::Loop` for a pointer back into the name or
    *    more than 128 jumps, `Faults::Pointer` for a pointer into the header,
    *    `Faults::Size` when the memo's names would pass `BUDGET` characters.
    *
    * @throws RuntimeException On a 32-bit PHP build: the memo packs each
    *    entry into a 64-bit integer.
    */
   public static function decode (string $bytes, int &$offset, array &$suffixes = []): string|Faults
   {
      // ? The memo's entries are 64-bit integers
      if (PHP_INT_SIZE < 8) {
         throw new RuntimeException('DNS name decoding requires 64-bit PHP.');
      }

      // ? A name starts inside the message
      if ($offset < 0) {
         return Faults::Overrun;
      }

      // !
      $length = strlen($bytes);
      $position = $offset;
      // # Start of the run being read: a pointer must land before it
      $start = $offset;
      // # Offset just past the in-place bytes (set at the root or the first pointer)
      $end = -1;
      // # Wire bytes so far, without the root byte
      $wire = 0;
      $jumps = 0;
      $labels = [];
      $tail = null;
      // # Characters of the presentation form before each label
      $chars = 0;
      $cuts = [];
      // # Positions read that a pointer can reach, packed: the position, then the
      //   labels, wire bytes and jumps before it
      $read = [];
      // # Each run closed by a pointer: how many positions were read before it ends,
      //   and that pointer's target
      $closes = [];
      // # Target of the pointer that ends the last run (-1: none)
      $exit = -1;

      // @@
      while (true) {
         // ? Every byte read is inside the message
         if ($position >= $length) {
            return Faults::Overrun;
         }

         // # Past the bytes in place (they own the end offset): a position read before
         $entry = $end >= 0 ? $suffixes[$position] ?? null : null;
         if (is_int($entry)) {
            // ! id 17 bits · cut 11 · wire size 8 · jumps 8 · pointer target + 1: 15
            $through = ($entry >> 44) - 1;

            // ? The pointer that ends its run must land before the run being read
            if ($through >= $start) {
               return Faults::Loop;
            }

            $wire += (($entry >> 28) & 0xFF) - 1;
            if ($wire + 1 > self::LIMIT) {
               return Faults::Name;
            }
            $jumps += ($entry >> 36) & 0xFF;
            if ($jumps > self::JUMPS) {
               return Faults::Loop;
            }

            $text = $suffixes[-1 - ($entry & 0x1FFFF)] ?? '';
            $cut = ($entry >> 17) & 0x7FF;
            $tail = is_string($text) ? ($cut === 0 ? $text : substr($text, $cut)) : '';
            $exit = $through;
            break;
         }

         $byte = ord($bytes[$position]);

         if ($position >= self::HEADER && $position <= self::REACH) {
            $read[] = $position | (count($labels) << 16) | ($wire << 24) | ($jumps << 32);
         }

         // # Root: the name ends
         if ($byte === 0) {
            if ($end < 0) {
               $end = $position + 1;
            }
            break;
         }

         // # Compression pointer
         if ($byte >= 0xC0) {
            if ($position + 1 >= $length) {
               return Faults::Overrun;
            }

            $target = (($byte & 0x3F) << 8) | ord($bytes[$position + 1]);

            // ? Only a prior occurrence, outside the run being read, past the header
            if ($target >= $position) {
               return Faults::Forward;
            }
            if ($target >= $start) {
               return Faults::Loop;
            }
            if ($target < self::HEADER) {
               return Faults::Pointer;
            }
            if (++$jumps > self::JUMPS) {
               return Faults::Loop;
            }

            // @ The run ends at this pointer
            $closes[] = count($read) | ($target << 16);

            if ($end < 0) {
               $end = $position + 2;
            }

            $position = $target;
            $start = $target;
            continue;
         }

         // ? Label types 01 and 10 are reserved
         if ($byte >= 0x40) {
            return Faults::Label;
         }

         // # Label: length byte + bytes
         $wire += $byte + 1;
         if ($wire + 1 > self::LIMIT) {
            return Faults::Name;
         }
         if ($position + 1 + $byte > $length) {
            return Faults::Overrun;
         }

         $label = substr($bytes, $position + 1, $byte);
         $label = strspn($label, self::PLAIN) === $byte ? $label : self::escape($label);
         $labels[] = $label;
         $cuts[] = $chars;
         $chars += strlen($label) + 1;

         $position += $byte + 1;
      }

      // ! The name in presentation form
      $text = implode('.', $labels);
      if ($tail !== null && $tail !== '') {
         $text = $text === '' ? $tail : "{$text}.{$tail}";
      }

      // ? The names of one memo stay within their budget
      $spent = $suffixes[1] ?? 0;
      $spent = (is_int($spent) ? $spent : 0) + strlen($text);
      if ($spent > self::BUDGET) {
         return Faults::Size;
      }
      $suffixes[1] = $spent;

      // @ Keep every position read that a pointer can reach, with the suffix it starts
      $id = -1;
      $close = 0;
      $closed = count($closes);
      foreach ($read as $index => $packed) {
         while ($close < $closed && ($closes[$close] & 0xFFFF) <= $index) {
            $close++;
         }

         $at = $packed & 0xFFFF;
         if (isset($suffixes[$at])) {
            continue;
         }

         // # The text once per name, at a negative key; the count of texts at key 0
         if ($id < 0) {
            $id = $suffixes[0] ?? 0;
            $id = is_int($id) ? $id : 0;
            $suffixes[0] = $id + 1;
            $suffixes[-1 - $id] = $text;
         }

         $through = $close < $closed ? $closes[$close] >> 16 : $exit;
         $suffixes[$at] = $id
            | (($cuts[($packed >> 16) & 0xFF] ?? $chars) << 17)
            | (($wire - (($packed >> 24) & 0xFF) + 1) << 28)
            | (($jumps - (($packed >> 32) & 0xFF)) << 36)
            | (($through + 1) << 44);
      }

      $offset = $end;

      // :
      return $text;
   }

   // ---

   /**
    * Split an escaped presentation name into raw labels.
    *
    * @return list<string>|Faults
    */
   private static function split (string $name): array|Faults
   {
      // !
      $labels = [];
      $label = '';
      $length = strlen($name);
      $index = 0;

      // @@
      while ($index < $length) {
         $character = $name[$index];

         if ($character === '\\') {
            // ? An escape needs a byte after it
            if ($index + 1 >= $length) {
               return Faults::Name;
            }

            $next = $name[$index + 1];

            if ($next >= '0' && $next <= '9') {
               // # \DDD: exactly three digits, at most 255
               if (strspn($name, '0123456789', $index + 1, 3) !== 3) {
                  return Faults::Name;
               }
               $value = (int) substr($name, $index + 1, 3);
               if ($value > 255) {
                  return Faults::Name;
               }
               $label .= chr($value);
               $index += 4;
               continue;
            }

            // # \X: the byte itself
            $label .= $next;
            $index += 2;
            continue;
         }

         if ($character === '.') {
            // ? The trailing dot ends the name; any other dot ends a label
            $labels[] = $label;
            $label = '';
            $index++;

            if ($index === $length) {
               // :
               return $labels;
            }
            continue;
         }

         $label .= $character;
         $index++;
      }

      $labels[] = $label;

      // :
      return $labels;
   }

   /**
    * Escape one raw label for the presentation form.
    */
   private static function escape (string $label): string
   {
      // !
      $text = '';
      $length = strlen($label);

      // @@
      for ($index = 0; $index < $length; $index++) {
         $character = $label[$index];

         if (strspn($character, self::PLAIN) === 1) {
            $text .= $character;
         }
         else if ($character === '.' || $character === '\\') {
            $text .= "\\{$character}";
         }
         else {
            $text .= sprintf('\\%03d', ord($character));
         }
      }

      // :
      return $text;
   }
}
