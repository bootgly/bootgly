<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage;


use function assert;
use function hex2bin;
use function json_encode;
use function md5;
use function range;
use function str_repeat;
use function strtoupper;

use Bootgly\ABI\Resources\Storage\Drivers\S3\Reader;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * The ETag a Multipart Upload completes into, offline: the MD5 of the parts' binary MD5s in part
 * order, then `-` and the number of parts — and no answer when a part's ETag is not an MD5.
 */
return new Test(
   description: 'Storage(S3) offline: Reader folds the part ETags into the ETag of the completed object, or tells it cannot',
   test: function () {
      $first = md5('first part');
      $second = md5('second part');
      $third = md5('third part');
      $expected = '"' . md5((string) hex2bin($first) . (string) hex2bin($second) . (string) hex2bin($third)) . '-3"';

      // # Part order, not array order; quoted or not, any letter case
      $folded = [
         'ordered' => Reader::fold([1 => ['etag' => "\"{$first}\""], 2 => ['etag' => "\"{$second}\""], 3 => ['etag' => "\"{$third}\""]]),
         'shuffled' => Reader::fold([3 => ['etag' => "\"{$third}\""], 1 => ['etag' => "\"{$first}\""], 2 => ['etag' => "\"{$second}\""]]),
         'bare-upper' => Reader::fold([1 => ['etag' => strtoupper($first)], 2 => ['etag' => strtoupper($second)], 3 => ['etag' => strtoupper($third)]]),
         'one' => Reader::fold([1 => ['etag' => "\"{$first}\""]]),
      ];
      yield assert(
         assertion: $folded['ordered'] === $expected
            && $folded['shuffled'] === $expected
            && $folded['bare-upper'] === $expected
            && $folded['one'] === '"' . md5((string) hex2bin($first)) . '-1"',
         description: 'The parts fold in part order into "<md5>-<count>", found: ' . json_encode($folded)
      );

      // # Nothing to tell: no parts, more than 10,000, or a part ETag that is not an MD5
      $many = [];
      foreach (range(1, 10001) as $number) {
         $many[$number] = ['etag' => $first];
      }
      $unknown = [
         'empty' => Reader::fold([]),
         'too-many' => Reader::fold($many),
         'not-hex' => Reader::fold([1 => ['etag' => "\"{$first}\""], 2 => ['etag' => '"' . str_repeat('z', 32) . '"']]),
         'short' => Reader::fold([1 => ['etag' => '"abc123"']]),
         'multipart' => Reader::fold([1 => ['etag' => "\"{$first}-2\""]]),
         'missing' => Reader::fold([1 => []]),
      ];
      yield assert(
         assertion: $unknown === ['empty' => null, 'too-many' => null, 'not-hex' => null, 'short' => null, 'multipart' => null, 'missing' => null],
         description: 'Parts that cannot be folded give no ETag, found: ' . json_encode($unknown)
      );

      // # 32 hex digits that are not the part's MD5 (an encrypted part): folded all the same — it
      //   is the object's ETag that will not match
      $opaque = Reader::fold([1 => ['etag' => '"' . str_repeat('0a', 16) . '"'], 2 => ['etag' => "\"{$second}\""]]);
      yield assert(
         assertion: $opaque === '"' . md5(hex2bin(str_repeat('0a', 16)) . hex2bin($second)) . '-2"',
         description: 'A part ETag of 32 hex digits folds whatever it digests, found: ' . json_encode($opaque)
      );
   }
);
