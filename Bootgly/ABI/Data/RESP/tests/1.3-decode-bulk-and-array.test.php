<?php

use Bootgly\ABI\Data\RESP\Decoder;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'RESP\Decoder: bulk strings, nested arrays, empty and null arrays',
   test: function () {
      yield assert(
         assertion: new Decoder()->decode("\$5\r\nhello\r\n") === ['hello'],
         description: 'Bulk string decodes'
      );
      yield assert(
         assertion: new Decoder()->decode("*2\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\n") === [['foo', 'bar']],
         description: 'Array of bulk strings decodes'
      );
      yield assert(
         assertion: new Decoder()->decode("*0\r\n") === [[]],
         description: 'Empty array decodes to []'
      );
      yield assert(
         assertion: new Decoder()->decode("*-1\r\n") === [null],
         description: 'Null array decodes to null'
      );
   }
);
