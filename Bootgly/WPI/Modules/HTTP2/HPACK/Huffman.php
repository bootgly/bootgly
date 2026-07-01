<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP2\HPACK;


use function chr;
use function ord;
use function strlen;


/**
 * HPACK Huffman decoder (RFC 7541 §5.2 + Appendix B).
 *
 * `CODES`/`LENGTHS` are transcribed from RFC 7541 Appendix B (symbols 0..255
 * plus EOS at index 256). Decoding walks a nibble-indexed state table built
 * once per process from those constants: 2 lookups per input byte, no
 * per-bit branching. Encoding is intentionally absent — Bootgly emits
 * HPACK string literals in raw form (context-free, cacheable blocks).
 */
final class Huffman
{
   // @ Huffman code values (RFC 7541 Appendix B), indexed by symbol.
   public const array CODES = [
      0x1ff8, 0x7fffd8, 0xfffffe2, 0xfffffe3, 0xfffffe4, 0xfffffe5, 0xfffffe6, 0xfffffe7, 0xfffffe8, 0xffffea, 0x3ffffffc, 0xfffffe9,
      0xfffffea, 0x3ffffffd, 0xfffffeb, 0xfffffec, 0xfffffed, 0xfffffee, 0xfffffef, 0xffffff0, 0xffffff1, 0xffffff2, 0x3ffffffe, 0xffffff3,
      0xffffff4, 0xffffff5, 0xffffff6, 0xffffff7, 0xffffff8, 0xffffff9, 0xffffffa, 0xffffffb, 0x14, 0x3f8, 0x3f9, 0xffa,
      0x1ff9, 0x15, 0xf8, 0x7fa, 0x3fa, 0x3fb, 0xf9, 0x7fb, 0xfa, 0x16, 0x17, 0x18,
      0x0, 0x1, 0x2, 0x19, 0x1a, 0x1b, 0x1c, 0x1d, 0x1e, 0x1f, 0x5c, 0xfb,
      0x7ffc, 0x20, 0xffb, 0x3fc, 0x1ffa, 0x21, 0x5d, 0x5e, 0x5f, 0x60, 0x61, 0x62,
      0x63, 0x64, 0x65, 0x66, 0x67, 0x68, 0x69, 0x6a, 0x6b, 0x6c, 0x6d, 0x6e,
      0x6f, 0x70, 0x71, 0x72, 0xfc, 0x73, 0xfd, 0x1ffb, 0x7fff0, 0x1ffc, 0x3ffc, 0x22,
      0x7ffd, 0x3, 0x23, 0x4, 0x24, 0x5, 0x25, 0x26, 0x27, 0x6, 0x74, 0x75,
      0x28, 0x29, 0x2a, 0x7, 0x2b, 0x76, 0x2c, 0x8, 0x9, 0x2d, 0x77, 0x78,
      0x79, 0x7a, 0x7b, 0x7ffe, 0x7fc, 0x3ffd, 0x1ffd, 0xffffffc, 0xfffe6, 0x3fffd2, 0xfffe7, 0xfffe8,
      0x3fffd3, 0x3fffd4, 0x3fffd5, 0x7fffd9, 0x3fffd6, 0x7fffda, 0x7fffdb, 0x7fffdc, 0x7fffdd, 0x7fffde, 0xffffeb, 0x7fffdf,
      0xffffec, 0xffffed, 0x3fffd7, 0x7fffe0, 0xffffee, 0x7fffe1, 0x7fffe2, 0x7fffe3, 0x7fffe4, 0x1fffdc, 0x3fffd8, 0x7fffe5,
      0x3fffd9, 0x7fffe6, 0x7fffe7, 0xffffef, 0x3fffda, 0x1fffdd, 0xfffe9, 0x3fffdb, 0x3fffdc, 0x7fffe8, 0x7fffe9, 0x1fffde,
      0x7fffea, 0x3fffdd, 0x3fffde, 0xfffff0, 0x1fffdf, 0x3fffdf, 0x7fffeb, 0x7fffec, 0x1fffe0, 0x1fffe1, 0x3fffe0, 0x1fffe2,
      0x7fffed, 0x3fffe1, 0x7fffee, 0x7fffef, 0xfffea, 0x3fffe2, 0x3fffe3, 0x3fffe4, 0x7ffff0, 0x3fffe5, 0x3fffe6, 0x7ffff1,
      0x3ffffe0, 0x3ffffe1, 0xfffeb, 0x7fff1, 0x3fffe7, 0x7ffff2, 0x3fffe8, 0x1ffffec, 0x3ffffe2, 0x3ffffe3, 0x3ffffe4, 0x7ffffde,
      0x7ffffdf, 0x3ffffe5, 0xfffff1, 0x1ffffed, 0x7fff2, 0x1fffe3, 0x3ffffe6, 0x7ffffe0, 0x7ffffe1, 0x3ffffe7, 0x7ffffe2, 0xfffff2,
      0x1fffe4, 0x1fffe5, 0x3ffffe8, 0x3ffffe9, 0xffffffd, 0x7ffffe3, 0x7ffffe4, 0x7ffffe5, 0xfffec, 0xfffff3, 0xfffed, 0x1fffe6,
      0x3fffe9, 0x1fffe7, 0x1fffe8, 0x7ffff3, 0x3fffea, 0x3fffeb, 0x1ffffee, 0x1ffffef, 0xfffff4, 0xfffff5, 0x3ffffea, 0x7ffff4,
      0x3ffffeb, 0x7ffffe6, 0x3ffffec, 0x3ffffed, 0x7ffffe7, 0x7ffffe8, 0x7ffffe9, 0x7ffffea, 0x7ffffeb, 0xffffffe, 0x7ffffec, 0x7ffffed,
      0x7ffffee, 0x7ffffef, 0x7fffff0, 0x3ffffee, 0x3fffffff
   ];
   // @ Huffman code bit lengths (RFC 7541 Appendix B), indexed by symbol.
   public const array LENGTHS = [
      13, 23, 28, 28, 28, 28, 28, 28, 28, 24, 30, 28,
      28, 30, 28, 28, 28, 28, 28, 28, 28, 28, 30, 28,
      28, 28, 28, 28, 28, 28, 28, 28, 6, 10, 10, 12,
      13, 6, 8, 11, 10, 10, 8, 11, 8, 6, 6, 6,
      5, 5, 5, 6, 6, 6, 6, 6, 6, 6, 7, 8,
      15, 6, 12, 10, 13, 6, 7, 7, 7, 7, 7, 7,
      7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7,
      7, 7, 7, 7, 8, 7, 8, 13, 19, 13, 14, 6,
      15, 5, 6, 5, 6, 5, 6, 6, 6, 5, 7, 7,
      6, 6, 6, 5, 6, 7, 6, 5, 5, 6, 7, 7,
      7, 7, 7, 15, 11, 14, 13, 28, 20, 22, 20, 20,
      22, 22, 22, 23, 22, 23, 23, 23, 23, 23, 24, 23,
      24, 24, 22, 23, 24, 23, 23, 23, 23, 21, 22, 23,
      22, 23, 23, 24, 22, 21, 20, 22, 22, 23, 23, 21,
      23, 22, 22, 24, 21, 22, 23, 23, 21, 21, 22, 21,
      23, 22, 23, 23, 20, 22, 22, 22, 23, 22, 22, 23,
      26, 26, 20, 19, 22, 23, 22, 25, 26, 26, 26, 27,
      27, 26, 24, 25, 19, 21, 26, 27, 27, 26, 27, 24,
      21, 21, 26, 26, 28, 27, 27, 27, 20, 24, 20, 21,
      22, 21, 21, 23, 22, 22, 25, 25, 24, 24, 26, 23,
      26, 27, 26, 26, 27, 27, 27, 27, 27, 28, 27, 27,
      27, 27, 27, 26, 30
   ];

   // * Metadata
   // # Nibble state machine (built once per process from CODES/LENGTHS)
   /** @var array<int, int> next state per (state * 16 + nibble); -1 = invalid */
   private static array $next = [];
   /** @var array<int, string> symbols emitted per (state * 16 + nibble) */
   private static array $emit = [];
   /** @var array<int, bool> valid end-of-input states (all-ones path, depth < 8) */
   private static array $accept = [];


   /**
    * Decode a Huffman-coded HPACK string literal.
    *
    * @return null|string `null` on invalid coding (EOS in data, bad padding).
    */
   public static function decode (string $input): null|string
   {
      // ?!
      if (self::$next === []) {
         self::build();
      }

      // !
      $next = self::$next;
      $emit = self::$emit;
      $state = 0;
      $output = '';

      // @@ Two nibble transitions per input byte
      $size = strlen($input);
      for ($offset = 0; $offset < $size; $offset++) {
         $byte = ord($input[$offset]);

         $key = ($state << 4) | ($byte >> 4);
         $state = $next[$key];
         if ($state < 0) {
            return null;
         }
         if ($emit[$key] !== '') {
            $output .= $emit[$key];
         }

         $key = ($state << 4) | ($byte & 0xf);
         $state = $next[$key];
         if ($state < 0) {
            return null;
         }
         if ($emit[$key] !== '') {
            $output .= $emit[$key];
         }
      }

      // ? Padding must be a strict EOS prefix (all ones) shorter than 8 bits
      if (self::$accept[$state] === false) {
         return null;
      }

      // :
      return $output;
   }

   /**
    * Build the nibble-indexed decode table from `CODES`/`LENGTHS`.
    *
    * States are the internal nodes of the canonical Huffman trie; each
    * transition consumes 4 bits, emitting decoded symbols whenever a leaf
    * is crossed. Reaching the EOS leaf poisons the transition (-1).
    */
   private static function build (): void
   {
      // ! Trie: parallel child arrays; 0 is the root, -1 is "unset".
      $zero = [-1];
      $one = [-1];
      $symbol = [-1];
      $nodes = 1;

      // @@ Insert the 257 codes (MSB first)
      foreach (self::CODES as $sym => $code) {
         $length = self::LENGTHS[$sym];
         $node = 0;

         for ($bit = $length - 1; $bit >= 0; $bit--) {
            $side = ($code >> $bit) & 1;

            if ($bit === 0) {
               // @ Leaf
               if ($side === 0) {
                  $zero[$node] = -($sym + 2);
               }
               else {
                  $one[$node] = -($sym + 2);
               }
               break;
            }

            // @ Descend / create internal node (leaves encoded as -(sym+2))
            $child = ($side === 0) ? $zero[$node] : $one[$node];
            if ($child === -1) {
               $child = $nodes++;
               $zero[$child] = -1;
               $one[$child] = -1;
               if ($side === 0) {
                  $zero[$node] = $child;
               }
               else {
                  $one[$node] = $child;
               }
            }
            $node = $child;
         }
      }

      // ! Accepting states: nodes on the all-ones path from the root, depth < 8
      $accept = [];
      for ($node = 0; $node < $nodes; $node++) {
         $accept[$node] = false;
      }
      $accept[0] = true;
      $node = 0;
      for ($depth = 1; $depth < 8; $depth++) {
         $node = $one[$node];
         if ($node < 0) {
            break;
         }
         $accept[$node] = true;
      }

      // @@ Flatten: for each (state, nibble) walk 4 bits through the trie
      $next = [];
      $emit = [];
      for ($state = 0; $state < $nodes; $state++) {
         for ($nibble = 0; $nibble < 16; $nibble++) {
            $node = $state;
            $output = '';
            $failed = false;

            for ($bit = 3; $bit >= 0; $bit--) {
               $side = ($nibble >> $bit) & 1;
               $node = ($side === 0) ? $zero[$node] : $one[$node];

               if ($node <= -2) {
                  // @ Leaf crossed: emit symbol, restart at the root
                  $sym = -$node - 2;
                  if ($sym === 256) {
                     // ? EOS inside data is a decoding error
                     $failed = true;
                     break;
                  }
                  $output .= chr($sym);
                  $node = 0;
               }
            }

            $key = ($state << 4) | $nibble;
            $next[$key] = $failed ? -1 : $node;
            $emit[$key] = $output;
         }
      }

      self::$next = $next;
      self::$emit = $emit;
      self::$accept = $accept;
   }
}
