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
use function chr;
use function hex2bin;
use function pack;
use function str_repeat;
use function str_replace;
use function strlen;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\Data\DNS\Faults;
use Bootgly\ABI\Data\DNS\Message;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `DNS::decode()` — hostile and malformed messages, one per refusal trigger,
 * each refused by its own `Faults` case (`===`), with the accepted message on
 * the other side of every bound.
 */

return new Test(
   description: 'DNS refuses each malformed message by name and accepts each bound exactly',
   test: function () {
      $hex = fn (string $hex): string => (string) hex2bin(str_replace(' ', '', $hex));
      // # Header: id 1, flags, QD, AN, NS, AR
      $header = fn (int $flags, int $QD, int $AN = 0, int $NS = 0, int $AR = 0): string
         => pack('nnnnnn', 1, $flags, $QD, $AN, $NS, $AR);
      // # A record owned by the root: type, class, TTL 0, RDATA
      $record = fn (string $type, string $RDATA, string $class = '0001'): string
         => $hex("00 {$type} {$class} 00000000") . pack('n', strlen($RDATA)) . $RDATA;
      $empty = $hex('00 ff00 0001 00000000 0000');   // 11 bytes: the smallest record
      $root = $hex('00 0001 0001');                  // 5 bytes: the smallest question

      $refused = [
         // # Short
         'an 11-byte message' => [$hex('0001 0100 0001 0000 0000 00'), Faults::Short],

         // # Count
         'two questions in a standard query (RFC 9619)' => [$header(0x0100, 2) . $root . $root, Faults::Count],
         'QD over the bytes left (3 × 5 > 12)' => [$header(0x1000, 3) . $root . $root . "\x00\x00", Faults::Count],
         'AN over the bytes left (2 × 11 > 21)' => [$header(0, 0, 2) . $empty . str_repeat("\x00", 10), Faults::Count],
         'NS over the bytes left (2 × 11 > 21)' => [$header(0, 0, 0, 2) . $empty . str_repeat("\x00", 10), Faults::Count],
         'AR over the bytes left (2 × 11 > 21)' => [$header(0, 0, 0, 0, 2) . $empty . str_repeat("\x00", 10), Faults::Count],
         'AN 65535 in a 12-byte message' => [$header(0, 0, 0xFFFF), Faults::Count],

         // # Overrun
         'a label longer than the bytes left' => [$header(0, 1) . $hex('09 6162636465666768'), Faults::Overrun],
         'a question without its type and class' => [$header(0, 1) . $hex('01 61 00 0001'), Faults::Overrun],
         'a record without its fixed part' => [$header(0, 0, 1) . $hex('01 61 00 ff00 0001 0000 0000'), Faults::Overrun],
         'RDATA longer than the bytes left' => [$header(0, 0, 1) . $hex('00 ff00 0001 00000000 0005 0102'), Faults::Overrun],
         'a pointer cut after its first byte' => [$header(0, 0, 1) . $hex('09 616263646566676869 c0'), Faults::Overrun],

         // # Label
         'label type 01 (extended, RFC 6891 §5)' => [$header(0, 1) . $hex('41 61 00 0001 0001'), Faults::Label],
         'label type 10 (reserved)' => [$header(0, 1) . $hex('81 61 00 0001 0001'), Faults::Label],

         // # Loop, Forward, Pointer
         'a pointer back to the start of its own name' => [$header(0, 1) . $hex('01 61 c00c 0001 0001'), Faults::Loop],
         'a pointer back into its own two labels' => [$header(0, 1) . $hex('01 61 01 62 c00e 0001 0001'), Faults::Loop],
         'a pointer to itself' => [$header(0, 1) . $hex('c00c 0001 0001'), Faults::Forward],
         'a pointer past itself' => [$header(0, 1, 1) . $root . $hex('c0ff ff00 0001 00000000 0000'), Faults::Forward],
         'a pointer into the header (11)' => [$header(0, 1) . $hex('c00b 0001 0001'), Faults::Pointer],

         // # Data
         'A with 5 bytes' => [$header(0, 0, 1) . $record('0001', "\x7F\x00\x00\x01\x00"), Faults::Data],
         'AAAA with 15 bytes' => [$header(0, 0, 1) . $record('001c', str_repeat("\x00", 15)), Faults::Data],
         'a TXT string past the RDATA' => [$header(0, 0, 1) . $record('0010', "\x05ab"), Faults::Data],
         'a TXT string past its RDATA, into the next record' => [
            $header(0, 0, 2) . $record('0010', "\x05ab") . $empty, Faults::Data
         ],
         'a CAA tag of length 0' => [$header(0, 0, 1) . $record('0101', "\x00\x00"), Faults::Data],
         'a CAA tag past the RDATA' => [$header(0, 0, 1) . $record('0101', "\x00\x05ab"), Faults::Data],
         'a CAA tag with a space (letters and digits only)' => [
            $header(0, 0, 1) . $record('0101', "\x00\x03a bx"), Faults::Data
         ],
         'A of class IN without RDATA' => [$header(0, 0, 1) . $record('0001', ''), Faults::Data],
         'a CAA tag of 16 bytes' => [$header(0, 0, 1) . $record('0101', "\x00\x10" . str_repeat('a', 16)), Faults::Data],
         'SOA with 19 bytes of fields' => [$header(0, 0, 1) . $record('0006', "\x00\x00" . str_repeat("\x00", 19)), Faults::Data],
         'SOA with 21 bytes of fields' => [$header(0, 0, 1) . $record('0006', "\x00\x00" . str_repeat("\x00", 21)), Faults::Data],
         'CNAME with a byte after its name' => [$header(0, 0, 1) . $record('0005', "\x00\x00"), Faults::Data],
         'MX with 2 bytes' => [$header(0, 0, 1) . $record('000f', "\x00\x0a"), Faults::Data],
         'SRV with 6 bytes' => [$header(0, 0, 1) . $record('0021', str_repeat("\x00", 6)), Faults::Data],
         'an MX exchange running past its RDATA' => [
            $header(0, 0, 2) . $record('000f', "\x00\x0a\x01a") . $empty, Faults::Data
         ],

         // # EDNS
         'an OPT record in the answer section' => [$header(0, 0, 1) . $record('0029', ''), Faults::EDNS],
         'two OPT records' => [$header(0, 0, 0, 0, 2) . $record('0029', '') . $record('0029', ''), Faults::EDNS],
         'an OPT record not owned by the root' => [
            $header(0, 0, 0, 0, 1) . $hex('01 61 00 0029 0200 00000000 0000'), Faults::EDNS
         ],

         // # Trailing
         'a byte after the last section' => [$header(0, 0, 1) . $empty . "\x00", Faults::Trailing],
         'a byte after an empty message' => [$header(0, 0) . "\x00", Faults::Trailing],
      ];

      foreach ($refused as $label => [$bytes, $Fault]) {
         $Result = DNS::decode($bytes);

         yield assert(
            assertion: $Result === $Fault,
            description: "{$label}: refused as {$Fault->name}"
               . ($Result instanceof Faults ? " (got {$Result->name})" : ' (got a Message)')
         );
      }

      // # The accepted side of each bound
      $accepted = [
         'two questions in a non-QUERY opcode' => $header(0x1000, 2) . $root . $root,
         'QD exactly filling the bytes (3 × 5)' => $header(0x1000, 3) . $root . $root . $root,
         'AN exactly filling the bytes (2 × 11)' => $header(0, 0, 2) . $empty . $empty,
         'NS exactly filling the bytes (2 × 11)' => $header(0, 0, 0, 2) . $empty . $empty,
         'AR exactly filling the bytes (2 × 11)' => $header(0, 0, 0, 0, 2) . $empty . $empty,
         'a pointer to offset 12' => $header(0, 1, 1) . $hex('01 61 00 0001 0001 c00c ff00 0001 00000000 0000'),
         'one OPT record owned by the root' => $header(0, 0, 0, 0, 1) . $record('0029', ''),
      ];

      foreach ($accepted as $label => $bytes) {
         $Result = DNS::decode($bytes);

         yield assert(
            assertion: $Result instanceof Message,
            description: "{$label}: accepted" . ($Result instanceof Faults ? " (got {$Result->name})" : '')
         );
      }

      // # RDATA by class: SRV typed for IN only; no RDATA for NONE and ANY (RFC 2136)
      $SRV = DNS::decode($header(0, 0, 1) . $record('0021', str_repeat("\x00", 6), '0003'));
      yield assert(
         assertion: $SRV instanceof Message && $SRV->Answers[0]->data === str_repeat("\x00", 6),
         description: 'SRV of class CH keeps its raw RDATA (typed only for class IN)'
      );
      foreach (['00fe' => 'NONE', '00ff' => 'ANY'] as $class => $name) {
         $Delete = DNS::decode($header(0, 0, 1) . $record('000f', '', $class));
         yield assert(
            assertion: $Delete instanceof Message && $Delete->Answers[0]->data === '',
            description: "MX of class {$name} without RDATA reads as '' (RFC 2136)"
         );
      }

      // # 255 wire bytes across a pointer: accepted; 256: refused
      $long = str_repeat(chr(63) . str_repeat('a', 63), 3) . "\x00";      // 193 bytes at offset 12
      $question = $header(0, 1, 1) . $long . $hex('0001 0001');
      $owner = fn (int $size): string => chr($size) . str_repeat('b', $size) . $hex('c00c');
      $fixed = $hex('ff00 0001 00000000 0000');

      $Fits = DNS::decode($question . $owner(61) . $fixed);
      yield assert(
         assertion: $Fits instanceof Message && strlen($Fits->Answers[0]->name) === 61 + 1 + 63 * 3 + 2,
         description: 'a name of 255 wire bytes built across a pointer is accepted'
      );
      yield assert(
         assertion: DNS::decode($question . $owner(62) . $fixed) === Faults::Name,
         description: 'a name of 256 wire bytes built across a pointer is refused as Name'
      );

      // # 255 wire bytes in place (no pointer): accepted; 256: refused
      $flat = fn (int $size): string => $header(0, 1)
         . str_repeat(chr(63) . str_repeat('a', 63), 3) . chr($size) . str_repeat('b', $size) . "\x00"
         . $hex('0001 0001');

      $Flat = DNS::decode($flat(61));
      yield assert(
         assertion: $Flat instanceof Message && strlen($Flat->Questions[0]->name) === 63 * 3 + 61 + 3,
         description: 'a name of 255 wire bytes in place is accepted'
      );
      yield assert(
         assertion: DNS::decode($flat(62)) === Faults::Name,
         description: 'a name of 256 wire bytes in place is refused as Name'
      );

      // # Jumps: 128 pointers are followed, 129 are a loop
      $chain = function (int $pointers) use ($header, $hex): string {
         // ! RDATA at offset 12 + 11 = 23: a root byte, then pointers each to the one before
         $RDATA = "\x00";
         $previous = 23;
         for ($index = 1; $index < $pointers; $index++) {
            $RDATA .= pack('n', 0xC000 | $previous);
            $previous = 23 + 1 + 2 * ($index - 1);
         }
         $owner = pack('n', 0xC000 | $previous);

         return $header(0, 0, 2)
            . $hex('00 ff00 0001 00000000') . pack('n', strlen($RDATA)) . $RDATA
            . $owner . $hex('ff00 0001 00000000 0000');
      };

      $Followed = DNS::decode($chain(128));
      yield assert(
         assertion: $Followed instanceof Message && $Followed->Answers[1]->name === '',
         description: 'a name of 128 chained pointers is followed to the root'
      );
      yield assert(
         assertion: DNS::decode($chain(129)) === Faults::Loop,
         description: 'a name of 129 chained pointers is refused as Loop'
      );
   }
);
