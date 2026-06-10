<?php

use function count;

use Bootgly\ABI\Data\RESP\Decoder;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use RuntimeException;


return new Specification(
   description: 'RESP\Decoder: simple string, integer, error and null bulk replies',
   test: function () {
      yield assert(
         assertion: new Decoder()->decode("+OK\r\n") === ['OK'],
         description: 'Simple string decodes'
      );
      yield assert(
         assertion: new Decoder()->decode(":42\r\n") === [42],
         description: 'Integer decodes to int'
      );

      $replies = new Decoder()->decode("-ERR bad request\r\n");
      yield assert(
         assertion: count($replies) === 1
            && $replies[0] instanceof RuntimeException
            && $replies[0]->getMessage() === 'ERR bad request',
         description: 'Error reply decodes to a (non-thrown) RuntimeException'
      );

      yield assert(
         assertion: new Decoder()->decode("\$-1\r\n") === [null],
         description: 'Null bulk string decodes to null'
      );
   }
);
