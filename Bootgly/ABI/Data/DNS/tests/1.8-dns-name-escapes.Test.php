<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\DNS\Tests;


use function assert;
use function bin2hex;
use function hex2bin;
use function is_string;
use function str_repeat;
use function str_replace;
use function strcasecmp;

use Bootgly\ABI\Data\DNS\Faults;
use Bootgly\ABI\Data\DNS\Name;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `Name` — the presentation form: escapes both ways (RFC 1035 §5.1), the
 * grammar `encode()` accepts, the trailing dot, and the 255 bound measured on
 * the wire, never on the text.
 */

return new Test(
   description: 'Name escapes label bytes one way only and reads the same escapes back',
   test: function () {
      $hex = fn (string $hex): string => (string) hex2bin(str_replace(' ', '', $hex));

      // # Decode: `.`, `\`, bytes outside `!`–`~` are escaped
      $wire = $hex('03 612e62 01 5c 02 00ff 01 20 00');
      $offset = 0;
      $text = Name::decode($wire, $offset);

      yield assert(
         assertion: $text === 'a\\.b.\\\\.\\000\\255.\\032' && $offset === 12,
         description: "label bytes `.`, `\\`, 0x00, 0xFF and space read as `{$text}`"
      );
      yield assert(
         assertion: Name::encode('a\\.b.\\\\.\\000\\255.\\032') === $wire,
         description: 'the escaped text writes back to the same wire bytes'
      );

      // # The text is injective: a label holding a dot is not two labels
      yield assert(
         assertion: Name::encode('www\\.example.com') !== Name::encode('www.example.com')
            && Name::encode('www\\.example.com') === $hex('0b 7777772e6578616d706c65 03 636f6d 00'),
         description: '`www\\.example.com` is two labels, not three'
      );

      // # Encode grammar
      $grammar = [
         'a\\.' => $hex('02 612e 00'),          // an escaped final dot stays in the label
         'a\\..' => $hex('02 612e 00'),         // ... then the trailing dot is dropped
         'a\\.\\.' => $hex('03 612e2e 00'),
         '\\065' => $hex('01 41 00'),           // \DDD
         '\\255' => $hex('01 ff 00'),
         '\\000' => $hex('01 00 00'),
         'a\\\\b' => $hex('03 615c62 00'),      // \\ is one backslash
         'a\\b' => $hex('02 6162 00'),          // \X is X
         'a.' => $hex('01 61 00'),
         'a' => $hex('01 61 00'),
         '.' => "\x00",
         '' => "\x00",
      ];
      foreach ($grammar as $name => $expected) {
         $written = Name::encode($name);

         yield assert(
            assertion: $written === $expected,
            description: "`{$name}` writes as " . bin2hex($expected)
               . ($written instanceof Faults ? " (got {$written->name})" : ' (got ' . bin2hex($written) . ')')
         );
      }

      $refused = [
         'a\\' => Faults::Name,      // dangling escape
         'a\\1' => Faults::Name,     // one digit
         'a\\12' => Faults::Name,    // two digits
         'a\\1b2' => Faults::Name,   // a non-digit inside \DDD
         '\\256' => Faults::Name,    // above 255
         '\\300' => Faults::Name,
         'a..' => Faults::Label,     // an empty label before the trailing dot
         'a..b' => Faults::Label,
         '.a' => Faults::Label,
         '..' => Faults::Label,
         'a.\\' => Faults::Name,
      ];
      foreach ($refused as $name => $Fault) {
         $written = Name::encode($name);

         yield assert(
            assertion: $written === $Fault,
            description: "`{$name}` is refused as {$Fault->name}"
         );
      }

      // # 255 is counted on the wire: escapes make the text longer, not the name
      $base = str_repeat('a', 63) . '.' . str_repeat('a', 63) . '.' . str_repeat('a', 63) . '.';
      yield assert(
         assertion: Name::encode($base . str_repeat('\\.', 61)) !== Faults::Name
            && Name::encode($base . str_repeat('\\.', 61) . '.') !== Faults::Name,
         description: 'a 255-byte wire name written with escapes (text of 314 bytes) is accepted'
      );
      yield assert(
         assertion: Name::encode($base . str_repeat('\\.', 62)) === Faults::Name
            && Name::encode($base . str_repeat('a', 62)) === Faults::Name,
         description: 'a 256-byte wire name is refused as Name, escaped or plain'
      );

      // # Canonical form: decode(encode(x)); names compare by their wire form
      $canonical = Name::encode('\\065bc.COM.');
      $offset = 0;
      yield assert(
         assertion: is_string($canonical) && Name::decode($canonical, $offset) === 'Abc.COM',
         description: 'the canonical text of `\\065bc.COM.` is `Abc.COM`'
      );
      yield assert(
         assertion: Name::encode('Example.COM.') === Name::encode('Example.COM')
            && Name::encode('Example.COM') !== Name::encode('example.com')
            && strcasecmp((string) Name::encode('Example.COM'), (string) Name::encode('example.com')) === 0,
         description: 'wire forms compare exactly (case kept) or case-insensitively (RFC 4343)'
      );
   }
);
