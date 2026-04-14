<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Raw\Body;


return new Specification(
   description: 'It should encode JSON body',
   test: function () {
      $Body = new Body;
      $data = ['key' => 'value', 'number' => 42];
      $Body->encode($data, 'json');

      $expected = json_encode($data);

      yield assert(
         assertion: $Body->raw === $expected,
         description: 'JSON encoded body: ' . $Body->raw
      );

      yield assert(
         assertion: $Body->length === strlen($expected),
         description: 'JSON body length: ' . $Body->length
      );
   }
);
