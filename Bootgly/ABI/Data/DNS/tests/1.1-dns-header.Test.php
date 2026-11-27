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
use function sprintf;
use function str_replace;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\Data\DNS\Message;
use Bootgly\ABI\Data\DNS\Message\Question;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `DNS::encode()` / `DNS::decode()` — the header: id, every flag bit, opcode,
 * rcode (with the EDNS extended bits), the counts and the EDNS(0) OPT record.
 * Vectors written from the RFC 1035 §4.1.1 / RFC 6891 §6.1 layout, never from
 * the codec.
 */

return new Test(
   description: 'DNS writes and reads the header, its flags and EDNS(0) exactly',
   test: function () {
      $hex = fn (string $hex): string => (string) hex2bin(str_replace(' ', '', $hex));

      // # A recursive AAAA query with EDNS(0) 1232
      $query = $hex(
         '1234 0100 0001 0000 0000 0001'      // id, RD, QD 1, AR 1 (the OPT)
         . ' 07 6578616d706c65 03 636f6d 00'   // example.com
         . ' 001c 0001'                        // AAAA IN
         . ' 00 0029 04d0 00000000 0000'       // OPT: root, size 1232, TTL 0, no option
      );
      $Query = new Message(
         id: 0x1234,
         recursion: true,
         size: 1232,
         Questions: [new Question('example.com', DNS::TYPE_AAAA)]
      );
      $encoded = DNS::encode($Query);

      yield assert(
         assertion: $encoded === $query,
         description: 'a query encodes to its exact bytes: ' . bin2hex((string) $encoded)
      );

      $Decoded = DNS::decode($query);
      yield assert(
         assertion: $Decoded instanceof Message
            && $Decoded->id === 0x1234 && $Decoded->response === false && $Decoded->opcode === 0
            && $Decoded->authoritative === false && $Decoded->truncated === false
            && $Decoded->recursion === true && $Decoded->recursive === false
            && $Decoded->authentic === false && $Decoded->unchecked === false
            && $Decoded->rcode === 0 && $Decoded->size === 1232
            && count($Decoded->Questions) === 1
            && $Decoded->Questions[0]->name === 'example.com'
            && $Decoded->Questions[0]->type === 28 && $Decoded->Questions[0]->class === 1
            && $Decoded->Answers === [] && $Decoded->Authorities === [] && $Decoded->Additionals === [],
         description: 'the query decodes field by field; the OPT record is `size`, not an additional'
      );

      // # Every flag bit, a non-zero opcode and rcode, no EDNS
      $flags = $hex('abcd 97b5 0000 0000 0000 0000');
      $Flags = new Message(
         id: 0xABCD,
         response: true,
         opcode: 2,
         authoritative: true,
         truncated: true,
         recursion: true,
         recursive: true,
         authentic: true,
         unchecked: true,
         rcode: 5
      );

      yield assert(
         assertion: DNS::encode($Flags) === $flags,
         description: 'QR, opcode 2, AA, TC, RD, RA, AD, CD and rcode 5 pack to 0x97b5'
      );

      $Decoded = DNS::decode($flags);
      yield assert(
         assertion: $Decoded instanceof Message
            && $Decoded->id === 0xABCD && $Decoded->response && $Decoded->opcode === 2
            && $Decoded->authoritative && $Decoded->truncated && $Decoded->recursion
            && $Decoded->recursive && $Decoded->authentic && $Decoded->unchecked
            && $Decoded->rcode === 5 && $Decoded->size === null,
         description: 'every flag reads back; no OPT record means `size === null`'
      );

      // # Each flag bit on its own
      $bits = [
         'response' => 0x8000, 'authoritative' => 0x0400, 'truncated' => 0x0200, 'recursion' => 0x0100,
         'recursive' => 0x0080, 'authentic' => 0x0020, 'unchecked' => 0x0010
      ];
      $single = true;
      foreach ($bits as $flag => $bit) {
         $bytes = DNS::encode(new Message(...[$flag => true]));
         $single = $single && $bytes === $hex('0000' . sprintf('%04x', $bit) . '0000 0000 0000 0000');
      }
      yield assert(
         assertion: $single,
         description: 'each flag owns exactly its RFC bit'
      );

      // # The Z bit (0x0040) is ignored on decode and never written
      $Z = DNS::decode($hex('0001 0040 0000 0000 0000 0000'));
      yield assert(
         assertion: $Z instanceof Message && DNS::encode($Z) === $hex('0001 0000 0000 0000 0000 0000'),
         description: 'the reserved Z bit is dropped'
      );

      // # Extended rcode: BADVERS (16) = OPT TTL top byte 1, header rcode 0
      $badvers = $hex('beef 8000 0000 0000 0000 0001 00 0029 1000 01000000 0000');
      $Badvers = new Message(id: 0xBEEF, response: true, rcode: DNS::RCODE_BADVERS, size: 4096);

      yield assert(
         assertion: DNS::encode($Badvers) === $badvers,
         description: 'an extended rcode is written in the OPT TTL top byte'
      );

      $Decoded = DNS::decode($badvers);
      yield assert(
         assertion: $Decoded instanceof Message && $Decoded->rcode === 16 && $Decoded->size === 4096,
         description: 'the extended rcode reads back from the raw OPT TTL'
      );

      // # Extended rcode with its top bit set (private use 3841) survives the TTL rule
      $high = $hex('0001 8001 0000 0000 0000 0001 00 0029 0200 f0000000 0000');
      $Decoded = DNS::decode($high);
      yield assert(
         assertion: $Decoded instanceof Message && $Decoded->rcode === (0xF0 << 4 | 1) && $Decoded->size === 512,
         description: 'the extended rcode is taken from the raw TTL, before the top-bit rule'
      );

      // # A size below 512 reads as 512 (RFC 6891 §6.2.5) and re-encodes
      $small = $hex('0001 0000 0000 0000 0000 0001 00 0029 0000 00000000 0000');
      $Decoded = DNS::decode($small);
      yield assert(
         assertion: $Decoded instanceof Message && $Decoded->size === 512 && DNS::encode($Decoded) !== $small
            && DNS::encode($Decoded) === $hex('0001 0000 0000 0000 0000 0001 00 0029 0200 00000000 0000'),
         description: 'an OPT class below 512 decodes as 512, so the message re-encodes'
      );

      // # OPT version, DO flag and options are not kept
      $options = $hex('0001 0000 0000 0000 0000 0001 00 0029 04d0 00018000 0008 000a 0004 01020304');
      $Decoded = DNS::decode($options);
      yield assert(
         assertion: $Decoded instanceof Message && $Decoded->size === 1232 && $Decoded->rcode === 0
            && $Decoded->Additionals === [],
         description: 'an OPT with version 1, DO and a cookie option decodes to its size only'
      );
   }
);
