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
use function count;
use function hex2bin;
use function is_string;
use function sprintf;
use function str_replace;
use function strlen;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\Data\DNS\Message;
use Bootgly\ABI\Data\DNS\Message\Question;
use Bootgly\ABI\Data\DNS\Message\Record;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `DNS::encode()` / `DNS::decode()` — one answer per record type, both ways:
 * the bytes are written from the RFC layouts (RFC 1035 §3.3, RFC 3596, RFC
 * 2782, RFC 8659, RFC 3597), the expected fields by hand, and both directions
 * are compared exactly (`===`).
 */

return new Test(
   description: 'DNS reads and writes every record type exactly',
   test: function () {
      $hex = fn (string $hex): string => (string) hex2bin(str_replace(' ', '', $hex));
      // # An answer to `a.example` with one record (owner compressed to C00C), TTL 3600
      $answer = function (string $type, string $rdata, string $class = '0001') use ($hex): string {
         $RDATA = $hex($rdata);

         return $hex(
            "0001 8180 0001 0001 0000 0000 01 61 07 6578616d706c65 00 {$type} 0001"
            . " c00c {$type} {$class} 00000e10 " . sprintf('%04x', strlen($RDATA))
         ) . $RDATA;
      };

      $cases = [
         'A' => [DNS::TYPE_A, '0001', '5db8d822', '93.184.216.34', 1],
         'AAAA' => [DNS::TYPE_AAAA, '001c', '20010db8000000000000000000000001', '2001:db8::1', 1],
         'CNAME (compressed into the question)' => [DNS::TYPE_CNAME, '0005', '01 62 c00e', 'b.example', 1],
         'NS' => [DNS::TYPE_NS, '0002', '02 6e73 c00c', 'ns.a.example', 1],
         'PTR' => [DNS::TYPE_PTR, '000c', '04 686f7374 c00e', 'host.example', 1],
         'MX' => [
            DNS::TYPE_MX, '000f', '000a 04 6d61696c c00c', ['preference' => 10, 'exchange' => 'mail.a.example'], 1
         ],
         'null MX (RFC 7505)' => [DNS::TYPE_MX, '000f', '0000 00', ['preference' => 0, 'exchange' => ''], 1],
         'TXT (two strings, one empty)' => [
            DNS::TYPE_TXT, '0010', '0b 763d73706631202d616c6c 00', ['v=spf1 -all', ''], 1
         ],
         'TXT with empty RDATA' => [DNS::TYPE_TXT, '0010', '', [], 1],
         'SRV (target never compressed)' => [
            DNS::TYPE_SRV, '0021', '000a 003c 13c4 03 736970 01 61 07 6578616d706c65 00',
            ['priority' => 10, 'weight' => 60, 'port' => 5060, 'target' => 'sip.a.example'], 1
         ],
         'CAA' => [
            DNS::TYPE_CAA, '0101', '80 05 6973737565 6c657473656e63727970742e6f7267',
            ['flags' => 128, 'tag' => 'issue', 'value' => 'letsencrypt.org'], 1
         ],
         'SOA' => [
            DNS::TYPE_SOA, '0006',
            '03 6e7331 c00c 0a 686f73746d6173746572 c00c 78c40659 00001c20 00000e10 00127500 0000012c',
            [
               'mname' => 'ns1.a.example', 'rname' => 'hostmaster.a.example', 'serial' => 2026112601,
               'refresh' => 7200, 'retry' => 3600, 'expire' => 1209600, 'minimum' => 300
            ], 1
         ],
         'unknown type 65280 (raw, RFC 3597)' => [0xFF00, 'ff00', '010203', "\x01\x02\x03", 1],
         'A of class CH (raw outside IN)' => [DNS::TYPE_A, '0001', '05 6162636465', "\x05abcde", 3],
         'A of class ANY without RDATA (RFC 2136)' => [DNS::TYPE_A, '0001', '', '', 255],
      ];

      foreach ($cases as $label => [$type, $code, $rdata, $data, $class]) {
         $bytes = $answer($code, $rdata, sprintf('%04x', $class));

         // @ Decode: every field, exactly
         $Message = DNS::decode($bytes);
         $Record = $Message instanceof Message ? ($Message->Answers[0] ?? null) : null;

         yield assert(
            assertion: $Message instanceof Message && $Record instanceof Record
               && count($Message->Answers) === 1 && $Message->Authorities === [] && $Message->Additionals === []
               && $Record->name === 'a.example' && $Record->type === $type && $Record->class === $class
               && $Record->TTL === 3600 && $Record->data === $data,
            description: "{$label}: decodes to its fields"
         );

         // @ Encode: the same bytes
         $encoded = DNS::encode(new Message(
            id: 1,
            response: true,
            recursion: true,
            recursive: true,
            Questions: [new Question('a.example', $type)],
            Answers: [new Record('a.example', $type, 3600, $data, $class)]
         ));

         yield assert(
            assertion: $encoded === $bytes,
            description: "{$label}: encodes to its bytes (" . bin2hex((string) $encoded) . ')'
         );
      }

      // # A TTL with its top bit set reads as 0 (RFC 2181 §8)
      $high = $hex(
         '0001 8180 0000 0001 0000 0000 01 61 00 0001 0001 80000000 0004 7f000001'
      );
      $Message = DNS::decode($high);
      yield assert(
         assertion: $Message instanceof Message && $Message->Answers[0]->TTL === 0,
         description: 'a TTL of 2^31 decodes as 0'
      );

      // # Records land in their sections, in order; the OPT record is `size`
      $Full = new Message(
         id: 7,
         response: true,
         size: 1232,
         Questions: [new Question('a.example', DNS::TYPE_A)],
         Answers: [
            new Record('a.example', DNS::TYPE_CNAME, 60, 'b.example'),
            new Record('b.example', DNS::TYPE_A, 60, '192.0.2.1')
         ],
         Authorities: [
            new Record('example', DNS::TYPE_NS, 60, 'ns.example')
         ],
         Additionals: [
            new Record('ns.example', DNS::TYPE_AAAA, 60, '2001:db8::53')
         ]
      );
      $bytes = DNS::encode($Full);
      $Decoded = is_string($bytes) ? DNS::decode($bytes) : null;

      yield assert(
         assertion: $Decoded instanceof Message
            && count($Decoded->Answers) === 2 && count($Decoded->Authorities) === 1
            && count($Decoded->Additionals) === 1 && $Decoded->size === 1232
            && $Decoded->Answers[0]->data === 'b.example' && $Decoded->Answers[1]->data === '192.0.2.1'
            && $Decoded->Answers[1]->name === 'b.example'
            && $Decoded->Authorities[0]->data === 'ns.example'
            && $Decoded->Additionals[0]->data === '2001:db8::53'
            && DNS::encode($Decoded) === $bytes,
         description: 'answer, authority and additional records keep their section and order'
      );
   }
);
