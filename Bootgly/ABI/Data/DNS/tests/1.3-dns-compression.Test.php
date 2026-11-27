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
use function file_get_contents;
use function hex2bin;
use function is_string;
use function str_repeat;
use function str_replace;
use function strlen;
use function substr;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\Data\DNS\Faults;
use Bootgly\ABI\Data\DNS\Message;
use Bootgly\ABI\Data\DNS\Message\Question;
use Bootgly\ABI\Data\DNS\Message\Record;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `DNS::encode()` — name compression (RFC 1035 §4.1.4): which names are
 * compressed, at which exact offsets, case kept as written, and the 14-bit
 * reach of a pointer.
 */

return new Test(
   description: 'DNS compresses names at their exact offsets, case-sensitively, within 0x3FFF',
   test: function () {
      $hex = fn (string $hex): string => (string) hex2bin(str_replace(' ', '', $hex));

      // # Case is kept: a suffix is shared only when its bytes are equal
      $Mixed = new Message(
         id: 1,
         response: true,
         Questions: [new Question('Example.COM', DNS::TYPE_A)],
         Answers: [
            new Record('example.com', DNS::TYPE_A, 60, '192.0.2.1'),
            new Record('www.example.com', DNS::TYPE_A, 60, '192.0.2.2'),
            new Record('x.COM', DNS::TYPE_A, 60, '192.0.2.3')
         ]
      );
      $expected = $hex(
         '0001 8000 0001 0003 0000 0000'
         . ' 07 4578616d706c65 03 434f4d 00 0001 0001'                       // question at 12, `COM` at 20
         . ' 07 6578616d706c65 03 636f6d 00 0001 0001 0000003c 0004 c0000201' // owner at 29: other case, in full
         . ' 03 777777 c01d 0001 0001 0000003c 0004 c0000202'                 // `www` + pointer to 29
         . ' 01 78 c014 0001 0001 0000003c 0004 c0000203'                     // `x` + pointer to `COM` at 20
      );
      $encoded = DNS::encode($Mixed);

      yield assert(
         assertion: $encoded === $expected,
         description: 'names differing only in case are written apart; equal suffixes point to their first offset: '
            . bin2hex((string) $encoded)
      );

      // # A name inside CNAME RDATA is a compression target for later owners
      $Chain = new Message(
         id: 2,
         response: true,
         Questions: [new Question('a.example', DNS::TYPE_A)],
         Answers: [
            new Record('a.example', DNS::TYPE_CNAME, 60, 'b.example'),
            new Record('b.example', DNS::TYPE_A, 60, '192.0.2.9')
         ]
      );
      $expected = $hex(
         '0002 8000 0001 0002 0000 0000'
         . ' 01 61 07 6578616d706c65 00 0001 0001'      // question at 12, `example` at 14
         . ' c00c 0005 0001 0000003c 0004 01 62 c00e'   // CNAME RDATA at 39: `b` + pointer to 14
         . ' c027 0001 0001 0000003c 0004 c0000209'     // owner points into the RDATA (39)
      );

      yield assert(
         assertion: DNS::encode($Chain) === $expected,
         description: 'an owner name points into an earlier CNAME RDATA'
      );

      // # An SRV target is never compressed, even when it repeats the question
      $SRV = new Message(
         id: 3,
         response: true,
         Questions: [new Question('a.example', DNS::TYPE_SRV)],
         Answers: [
            new Record('a.example', DNS::TYPE_SRV, 60, ['priority' => 1, 'weight' => 2, 'port' => 3, 'target' => 'a.example'])
         ]
      );
      $expected = $hex(
         '0003 8000 0001 0001 0000 0000'
         . ' 01 61 07 6578616d706c65 00 0021 0001'
         . ' c00c 0021 0001 0000003c 0011 0001 0002 0003 01 61 07 6578616d706c65 00'
      );

      yield assert(
         assertion: DNS::encode($SRV) === $expected,
         description: 'the SRV target is written in full (RFC 2782)'
      );

      // # The 14-bit reach: a name starting at 0x3FFF is a target, one at 0x4000 is not
      $reach = function (int $pad): string|Faults {
         return DNS::encode(new Message(
            id: 4,
            response: true,
            Questions: [new Question('x', DNS::TYPE_A)],         // 12..18
            Answers: [
               new Record('x', 0xFF00, 0, str_repeat("\x00", $pad)), // owner c00c at 19, RDATA up to 31 + pad
               new Record('y.z', 0xFF00, 0, ''),                    // owner at 31 + pad
               new Record('y.z', 0xFF00, 0, ''),
               new Record('z', 0xFF00, 0, '')
            ]
         ));
      };

      $near = (string) $reach(16352);
      yield assert(
         assertion: substr($near, 16383, 5) === "\x01y\x01z\x00"
            && substr($near, 16383 + 15, 2) === "\xFF\xFF"
            && substr($near, 16383 + 15 + 12, 3) === "\x01z\x00",
         description: 'a name at offset 0x3FFF is pointed to (0xFFFF); its suffix at 0x4001 is not'
      );

      $Near = DNS::decode($near);
      yield assert(
         assertion: $Near instanceof Message && $Near->Answers[1]->name === 'y.z'
            && $Near->Answers[2]->name === 'y.z' && $Near->Answers[3]->name === 'z',
         description: 'the 0xFFFF pointer decodes back'
      );

      $far = (string) $reach(16353);
      yield assert(
         assertion: substr($far, 16384, 5) === "\x01y\x01z\x00"
            && substr($far, 16384 + 15, 5) === "\x01y\x01z\x00"
            && strlen($far) === 16384 + 15 + 15 + 13,
         description: 'a name at offset 0x4000 is out of reach: the next one is written in full'
      );

      // # SOA: the rname is registered where it is written, after the mname
      $Zone = new Message(
         id: 1,
         response: true,
         Authorities: [
            new Record('example.com', DNS::TYPE_SOA, 60, [
               'mname' => 'ns.example.com', 'rname' => 'hostmaster.zone.test', 'serial' => 1,
               'refresh' => 2, 'retry' => 3, 'expire' => 4, 'minimum' => 5
            ])
         ],
         Additionals: [new Record('zone.test', DNS::TYPE_A, 60, '192.0.2.9')]
      );
      $zone = DNS::encode($Zone);
      $Read = is_string($zone) ? DNS::decode($zone) : $zone;

      yield assert(
         // owner at 12 (13 bytes), RDATA at 35: mname `ns` + c00c (5 bytes), rname at 40 — `zone` at 51
         assertion: is_string($zone) && substr($zone, 82, 2) === "\xC0\x33"
            && $Read instanceof Message
            && $Read->Authorities[0]->data === $Zone->Authorities[0]->data
            && $Read->Additionals[0]->name === 'zone.test',
         description: 'a later owner points into the SOA rname at its own offset (0x33), and reads back'
      );

      // # Compression is what real servers produce: a captured answer re-encodes byte for byte
      $captured = $hex(str_replace("\n", '', (string) file_get_contents(__DIR__ . '/fixtures/upstream-mx.answer.txt')));
      $Captured = DNS::decode($captured);

      yield assert(
         assertion: $Captured instanceof Message && DNS::encode($Captured) === $captured,
         description: 'a captured MX answer (5 exchanges sharing suffixes) re-encodes to the same bytes'
      );
   }
);
