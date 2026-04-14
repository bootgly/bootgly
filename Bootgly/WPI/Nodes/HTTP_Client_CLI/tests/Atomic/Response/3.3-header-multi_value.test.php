<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Raw\Header;


return new Specification(
   description: 'It should handle multi-value headers (e.g. Set-Cookie)',
   test: function () {
      $Header = new Header;

      $raw = "Set-Cookie: a=1\r\nSet-Cookie: b=2\r\nServer: Bootgly";
      $Header->define($raw);

      // @ Multi-value returned as comma-joined
      yield assert(
         assertion: $Header->get('Set-Cookie') === 'a=1, b=2',
         description: 'Multi-value Set-Cookie: ' . $Header->get('Set-Cookie')
      );

      // @ Single-value still works
      yield assert(
         assertion: $Header->get('Server') === 'Bootgly',
         description: 'Single-value Server: ' . $Header->get('Server')
      );

      // @ getAll() returns array for multi-value headers
      yield assert(
         assertion: $Header->getAll('Set-Cookie') === ['a=1', 'b=2'],
         description: 'getAll(Set-Cookie) returns array'
      );
   }
);
