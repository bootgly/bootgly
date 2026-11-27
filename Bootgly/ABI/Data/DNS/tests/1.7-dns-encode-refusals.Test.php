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
use function is_string;
use function str_repeat;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\Data\DNS\Faults;
use Bootgly\ABI\Data\DNS\Message;
use Bootgly\ABI\Data\DNS\Message\Question;
use Bootgly\ABI\Data\DNS\Message\Record;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `DNS::encode()` — every value it cannot write, refused by name instead of
 * written wrapped (`pack()` would turn -1 into 65535 silently), with the
 * accepted value on the other side of each range.
 */

return new Test(
   description: 'DNS refuses to encode out-of-range fields, misshaped RDATA and oversized messages',
   test: function () {
      $answer = fn (Record $Record): Message => new Message(id: 1, response: true, Answers: [$Record]);
      $A = fn (mixed $data, int $TTL = 60, int $class = 1): Record => new Record('a', DNS::TYPE_A, $TTL, $data, $class);

      $refused = [
         // # Field: header
         'id -1' => [new Message(id: -1), Faults::Field],
         'id 65536' => [new Message(id: 0x10000), Faults::Field],
         'opcode 16' => [new Message(opcode: 16), Faults::Field],
         'opcode -1' => [new Message(opcode: -1), Faults::Field],
         'rcode 16 without EDNS' => [new Message(rcode: 16), Faults::Field],
         'rcode 4096 with EDNS' => [new Message(rcode: 4096, size: 1232), Faults::Field],
         'rcode -1' => [new Message(rcode: -1), Faults::Field],
         'size 511' => [new Message(size: 511), Faults::Field],
         'size 65536' => [new Message(size: 65536), Faults::Field],
         'two questions in a standard query (RFC 9619)' => [
            new Message(Questions: [new Question('a', 1), new Question('b', 1)]), Faults::Field
         ],
         // # Field: question and record
         'question type -1' => [new Message(Questions: [new Question('a', -1)]), Faults::Field],
         'question type 65536' => [new Message(Questions: [new Question('a', 0x10000)]), Faults::Field],
         'question class 65536' => [new Message(Questions: [new Question('a', 1, 0x10000)]), Faults::Field],
         'record type 65536' => [$answer(new Record('a', 0x10000, 0, '')), Faults::Field],
         'record class -1' => [$answer(new Record('a', 0xFF00, 0, '', -1)), Faults::Field],
         'TTL -1' => [$answer($A('192.0.2.1', -1)), Faults::Field],
         'TTL 2^31' => [$answer($A('192.0.2.1', 0x80000000)), Faults::Field],

         // # Data
         'A not an address' => [$answer($A('not-an-address')), Faults::Data],
         'A given an IPv6 address' => [$answer($A('::1')), Faults::Data],
         'A given an array' => [$answer($A(['192.0.2.1'])), Faults::Data],
         'AAAA given an IPv4 address' => [$answer(new Record('a', DNS::TYPE_AAAA, 0, '192.0.2.1')), Faults::Data],
         'CNAME given an array' => [$answer(new Record('a', DNS::TYPE_CNAME, 0, ['b'])), Faults::Data],
         'TXT string of 256 bytes' => [$answer(new Record('a', DNS::TYPE_TXT, 0, [str_repeat('x', 256)])), Faults::Data],
         'TXT given a string' => [$answer(new Record('a', DNS::TYPE_TXT, 0, 'text')), Faults::Data],
         'TXT given a map' => [$answer(new Record('a', DNS::TYPE_TXT, 0, ['k' => 'v'])), Faults::Data],
         'MX without exchange' => [$answer(new Record('a', DNS::TYPE_MX, 0, ['preference' => 1])), Faults::Data],
         'MX preference 65536' => [
            $answer(new Record('a', DNS::TYPE_MX, 0, ['preference' => 0x10000, 'exchange' => 'b'])), Faults::Data
         ],
         'MX preference as a string' => [
            $answer(new Record('a', DNS::TYPE_MX, 0, ['preference' => '1', 'exchange' => 'b'])), Faults::Data
         ],
         'SRV port -1' => [
            $answer(new Record('a', DNS::TYPE_SRV, 0, ['priority' => 0, 'weight' => 0, 'port' => -1, 'target' => 'b'])),
            Faults::Data
         ],
         'CAA empty tag' => [
            $answer(new Record('a', DNS::TYPE_CAA, 0, ['flags' => 0, 'tag' => '', 'value' => 'x'])), Faults::Data
         ],
         'CAA tag with a dash' => [
            $answer(new Record('a', DNS::TYPE_CAA, 0, ['flags' => 0, 'tag' => 'is-sue', 'value' => 'x'])), Faults::Data
         ],
         'CAA tag of 16 bytes' => [
            $answer(new Record('a', DNS::TYPE_CAA, 0, ['flags' => 0, 'tag' => str_repeat('a', 16), 'value' => 'x'])),
            Faults::Data
         ],
         'CAA flags 256' => [
            $answer(new Record('a', DNS::TYPE_CAA, 0, ['flags' => 256, 'tag' => 'issue', 'value' => 'x'])), Faults::Data
         ],
         'SOA serial 2^32' => [
            $answer(new Record('a', DNS::TYPE_SOA, 0, [
               'mname' => 'b', 'rname' => 'c', 'serial' => 0x100000000, 'refresh' => 0, 'retry' => 0,
               'expire' => 0, 'minimum' => 0
            ])),
            Faults::Data
         ],
         'raw type given an array' => [$answer(new Record('a', 0xFF00, 0, ['x'])), Faults::Data],

         // # EDNS
         'an OPT record in the additional list' => [
            new Message(Additionals: [new Record('', DNS::TYPE_OPT, 0, '', 1232)]), Faults::EDNS
         ],

         // # Names
         'an empty inner label' => [new Message(Questions: [new Question('a..b', 1)]), Faults::Label],
         'a label of 64 bytes' => [new Message(Questions: [new Question(str_repeat('a', 64), 1)]), Faults::Label],
         'a malformed escape' => [new Message(Questions: [new Question('a\\', 1)]), Faults::Name],

         // # Size
         'RDATA of 65536 bytes' => [$answer(new Record('a', 0xFF00, 0, str_repeat("\x00", 0x10000))), Faults::Size],
         'a message over 65535 bytes' => [
            new Message(Answers: [
               new Record('a', 0xFF00, 0, str_repeat("\x00", 40000)),
               new Record('a', 0xFF00, 0, str_repeat("\x00", 40000))
            ]),
            Faults::Size
         ],
      ];

      foreach ($refused as $label => [$Message, $Fault]) {
         $Result = DNS::encode($Message);

         yield assert(
            assertion: $Result === $Fault,
            description: "{$label}: refused as {$Fault->name}"
               . ($Result instanceof Faults ? " (got {$Result->name})" : ' (got bytes)')
         );
      }

      // # The accepted side of each range
      $accepted = [
         'id 0 and 65535' => [new Message(id: 0), new Message(id: 0xFFFF)],
         'opcode 15' => [new Message(opcode: 15)],
         'two questions in a non-QUERY opcode' => [
            new Message(opcode: 1, Questions: [new Question('a', 1), new Question('b', 1)])
         ],
         'records of class NONE and ANY without RDATA (RFC 2136)' => [
            new Message(opcode: 5, Answers: [
               new Record('a', DNS::TYPE_MX, 0, '', DNS::CLASS_NONE),
               new Record('a', DNS::TYPE_MX, 0, '', DNS::CLASS_ANY)
            ])
         ],
         'rcode 15 without EDNS, 4095 with' => [new Message(rcode: 15), new Message(rcode: 4095, size: 512)],
         'size 512 and 65535' => [new Message(size: 512), new Message(size: 65535)],
         'type and class 0 and 65535' => [
            new Message(Questions: [new Question('a', 0, 0)]),
            new Message(Questions: [new Question('a', 0xFFFF, 0xFFFF)])
         ],
         'TTL 0 and 2^31 - 1' => [$answer($A('192.0.2.1', 0)), $answer($A('192.0.2.1', 0x7FFFFFFF))],
         'TXT string of 255 bytes' => [$answer(new Record('a', DNS::TYPE_TXT, 0, [str_repeat('x', 255)]))],
         'CAA tag of 15 letters and digits' => [
            $answer(new Record('a', DNS::TYPE_CAA, 0, ['flags' => 255, 'tag' => 'abcdefghij12345', 'value' => '']))
         ],
         'SOA fields at 2^32 - 1' => [
            $answer(new Record('a', DNS::TYPE_SOA, 0, [
               'mname' => '', 'rname' => '', 'serial' => 0xFFFFFFFF, 'refresh' => 0xFFFFFFFF,
               'retry' => 0xFFFFFFFF, 'expire' => 0xFFFFFFFF, 'minimum' => 0xFFFFFFFF
            ]))
         ],
         'a message of exactly 65535 bytes' => [$answer(new Record('a', 0xFF00, 0, str_repeat("\x00", 0xFFFF - 12 - 13)))],
      ];

      foreach ($accepted as $label => $Messages) {
         $written = true;
         foreach ($Messages as $Message) {
            $bytes = DNS::encode($Message);
            $written = $written && is_string($bytes) && DNS::decode($bytes) instanceof Message;
         }

         yield assert(
            assertion: $written,
            description: "{$label}: written and read back"
         );
      }
   }
);
