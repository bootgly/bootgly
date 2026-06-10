<?php

use Bootgly\ABI\Data\RESP\Decoder;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'RESP\Decoder: multiple replies in one buffer and reset()',
   test: function () {
      $Decoder = new Decoder();

      yield assert(
         assertion: $Decoder->decode(":1\r\n:2\r\n:3\r\n") === [1, 2, 3],
         description: 'Several replies in one buffer decode in order'
      );

      $Decoder->reset();

      yield assert(
         assertion: $Decoder->buffer === '',
         description: 'reset() clears the buffer'
      );
      yield assert(
         assertion: $Decoder->decode("+PONG\r\n") === ['PONG'],
         description: 'Decoder is reusable after reset()'
      );
   }
);
