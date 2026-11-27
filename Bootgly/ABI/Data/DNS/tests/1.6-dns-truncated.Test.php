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
use function count;
use function file_get_contents;
use function hex2bin;
use function pack;
use function str_replace;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\Data\DNS\Faults;
use Bootgly\ABI\Data\DNS\Message;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `DNS::decode()` — a truncated answer (TC): flagged and read for its
 * questions only, never half-parsed (RFC 2181 §9) — whatever follows the
 * questions, records, garbage or impossible counts, is not looked at.
 */

return new Test(
   description: 'DNS flags a truncated answer and reads only its questions',
   test: function () {
      $hex = fn (string $hex): string => (string) hex2bin(str_replace(' ', '', $hex));
      $question = $hex('01 61 00 0001 0001');

      // # A captured TC answer that carries 6 records anyway
      $captured = $hex(str_replace("\n", '', (string) file_get_contents(__DIR__ . '/fixtures/stub-tc.answer.txt')));
      $Captured = DNS::decode($captured);

      yield assert(
         assertion: $Captured instanceof Message && $Captured->truncated
            && count($Captured->Questions) === 1 && $Captured->Questions[0]->name === 'google.com'
            && $Captured->Answers === [] && $Captured->Authorities === [] && $Captured->Additionals === [],
         description: 'the records of a TC answer are not read'
      );

      // # Garbage after the question
      $Garbage = DNS::decode(pack('nnnnnn', 1, 0x8300, 1, 1, 0, 0) . $question . "\xC0\xFF\x41\x00garbage");
      yield assert(
         assertion: $Garbage instanceof Message && $Garbage->truncated && count($Garbage->Questions) === 1,
         description: 'garbage after the question of a TC answer is not refused (not Trailing, not Overrun)'
      );

      // # Counts the message cannot hold
      $Counts = DNS::decode(pack('nnnnnn', 1, 0x8300, 1, 0xFFFF, 0xFFFF, 0xFFFF) . $question);
      yield assert(
         assertion: $Counts instanceof Message && $Counts->truncated && count($Counts->Questions) === 1,
         description: 'impossible record counts in a TC answer are not refused (not Count)'
      );

      // # A TC header whose question is cut: flagged, no question
      $Cut = DNS::decode(pack('nnnnnn', 7, 0x8300, 1, 0, 0, 0));
      yield assert(
         assertion: $Cut instanceof Message && $Cut->truncated && $Cut->id === 7 && $Cut->Questions === [],
         description: 'a TC answer with an unreadable question is still a truncated Message (retry over TCP)'
      );

      $Looping = DNS::decode(pack('nnnnnn', 7, 0x8300, 1, 0, 0, 0) . $hex('01 61 c00c 0001 0001'));
      yield assert(
         assertion: $Looping instanceof Message && $Looping->truncated && $Looping->Questions === [],
         description: 'a TC answer with a looping question name keeps no question'
      );

      // # Without TC the same bytes are refused
      yield assert(
         assertion: DNS::decode(pack('nnnnnn', 1, 0x8100, 1, 1, 0, 0) . $question . "\xC0\xFF\x41\x00garbage")
            instanceof Faults,
         description: 'the same bytes without TC are refused'
      );

      // # TC never reads the OPT record either: no size, the header's rcode bits only
      $OPT = DNS::decode(
         pack('nnnnnn', 1, 0x8200, 1, 0, 0, 1) . $question . $hex('00 0029 04d0 01000000 0000')
      );
      yield assert(
         assertion: $OPT instanceof Message && $OPT->truncated && $OPT->size === null && $OPT->rcode === 0,
         description: 'a TC answer with an OPT record (BADVERS) reads size null and rcode from the header only'
      );

      // # A question that cannot be read empties the list: never a partial question section
      $Partial = DNS::decode(pack('nnnnnn', 1, 0x9200, 2, 0, 0, 0) . $question . "\x09ab");
      yield assert(
         assertion: $Partial instanceof Message && $Partial->truncated && $Partial->Questions === [],
         description: 'a TC answer (opcode 2) whose second question cannot be read keeps no question'
      );

      // # TC never reads the records, even well-formed ones
      $Records = DNS::decode(
         pack('nnnnnn', 1, 0x8200, 1, 1, 0, 0) . $question . $hex('c00c 0001 0001 00000000 0004 7f000001')
      );
      yield assert(
         assertion: $Records instanceof Message && $Records->Answers === [],
         description: 'a TC answer with a complete record keeps no record'
      );
   }
);
