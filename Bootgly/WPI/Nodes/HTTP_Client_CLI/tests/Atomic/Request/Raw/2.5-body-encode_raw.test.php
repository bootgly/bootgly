<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Raw\Body;


return new Specification(
   description: 'It should encode raw string body',
   test: function () {
      $Body = new Body;
      $Body->encode('Hello World');

      yield assert(
         assertion: $Body->raw === 'Hello World',
         description: 'Raw body: ' . $Body->raw
      );

      yield assert(
         assertion: $Body->length === 11,
         description: 'Body length: ' . $Body->length
      );

      yield assert(
         assertion: $Body->input === 'Hello World',
         description: 'Input matches raw'
      );
   }
);
