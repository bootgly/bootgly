<?php

use Bootgly\ABI\Data\RESP\Encoder;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'RESP\Encoder: commands become RESP2 multibulk arrays of bulk strings',
   test: function () {
      $Encoder = new Encoder();

      yield assert(
         assertion: $Encoder->encode(['SET', 'k', 'v']) === "*3\r\n\$3\r\nSET\r\n\$1\r\nk\r\n\$1\r\nv\r\n",
         description: 'SET command encodes to multibulk'
      );
      yield assert(
         assertion: $Encoder->encode(['PING']) === "*1\r\n\$4\r\nPING\r\n",
         description: 'Single-verb command encodes'
      );
      yield assert(
         assertion: $Encoder->encode(['INCRBY', 'c', 5]) === "*3\r\n\$6\r\nINCRBY\r\n\$1\r\nc\r\n\$1\r\n5\r\n",
         description: 'Numeric arguments are stringified'
      );
   }
);
