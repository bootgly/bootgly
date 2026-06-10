<?php

use Bootgly\ABI\Data\RESP\Decoder;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'RESP\Decoder: RESP3 null, boolean, double and map replies',
   test: function () {
      yield assert(
         assertion: new Decoder()->decode("_\r\n") === [null],
         description: 'RESP3 null decodes to null'
      );
      yield assert(
         assertion: new Decoder()->decode("#t\r\n") === [true],
         description: 'RESP3 boolean true decodes'
      );
      yield assert(
         assertion: new Decoder()->decode("#f\r\n") === [false],
         description: 'RESP3 boolean false decodes'
      );
      yield assert(
         assertion: new Decoder()->decode(",3.14\r\n") === [3.14],
         description: 'RESP3 double decodes to float'
      );
      yield assert(
         assertion: new Decoder()->decode("%2\r\n\$1\r\na\r\n:1\r\n\$1\r\nb\r\n:2\r\n") === [['a' => 1, 'b' => 2]],
         description: 'RESP3 map decodes to associative array'
      );
   }
);
