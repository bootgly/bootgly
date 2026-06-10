<?php

use Bootgly\ABI\Data\RESP\Decoder;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'RESP\Decoder: incremental parsing across partial byte feeds',
   test: function () {
      // # Header split across feeds
      $Decoder = new Decoder();
      yield assert(
         assertion: $Decoder->decode("+OK\r") === [],
         description: 'Incomplete reply yields nothing yet'
      );
      yield assert(
         assertion: $Decoder->decode("\n") === ['OK'],
         description: 'Reply completes when the rest arrives'
      );

      // # Bulk payload split across feeds
      $Bulk = new Decoder();
      yield assert(
         assertion: $Bulk->decode("\$5\r\nhel") === [],
         description: 'Partial bulk payload yields nothing yet'
      );
      yield assert(
         assertion: $Bulk->decode("lo\r\n") === ['hello'],
         description: 'Bulk completes across feeds'
      );
   }
);
