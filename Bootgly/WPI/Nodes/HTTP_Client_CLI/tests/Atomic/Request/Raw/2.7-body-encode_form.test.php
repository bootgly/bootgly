<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Raw\Body;


return new Specification(
   description: 'It should encode form-urlencoded body',
   test: function () {
      $Body = new Body;
      $data = ['username' => 'bootgly', 'password' => 's3cret'];
      $Body->encode($data, 'form');

      $expected = http_build_query($data);

      yield assert(
         assertion: $Body->raw === $expected,
         description: 'Form encoded body: ' . $Body->raw
      );

      yield assert(
         assertion: str_contains($Body->raw, 'username=bootgly'),
         description: 'Contains username field'
      );

      yield assert(
         assertion: str_contains($Body->raw, 'password=s3cret'),
         description: 'Contains password field'
      );

      yield assert(
         assertion: $Body->length === strlen($expected),
         description: 'Form body length: ' . $Body->length
      );
   }
);
