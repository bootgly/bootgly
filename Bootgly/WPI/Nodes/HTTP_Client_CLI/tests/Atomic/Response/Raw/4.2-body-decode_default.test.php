<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Raw\Body;


return new Specification(
   description: 'It should return raw body for default/unknown decode type',
   test: function () {
      $Body = new Body;
      $Body->raw = 'Hello World';

      // @ Default type returns raw string
      $result = $Body->decode('raw');

      yield assert(
         assertion: $result === 'Hello World',
         description: 'Default decode returns raw: ' . $result
      );

      // @ Unknown type also returns raw string
      $result2 = $Body->decode('xml');

      yield assert(
         assertion: $result2 === 'Hello World',
         description: 'Unknown type returns raw: ' . $result2
      );
   }
);
