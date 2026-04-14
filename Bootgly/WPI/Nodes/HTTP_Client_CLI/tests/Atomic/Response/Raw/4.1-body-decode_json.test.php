<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Raw\Body;


return new Specification(
   description: 'It should decode JSON body content',
   test: function () {
      $Body = new Body;
      $Body->raw = '{"name":"Bootgly","version":1}';

      // @ Decode as associative array
      $decoded = $Body->decode('json', true);

      yield assert(
         assertion: is_array($decoded),
         description: 'Decoded result is array'
      );

      yield assert(
         assertion: $decoded['name'] === 'Bootgly',
         description: 'Decoded name: ' . $decoded['name']
      );

      yield assert(
         assertion: $decoded['version'] === 1,
         description: 'Decoded version: ' . $decoded['version']
      );

      // @ Decode as object
      $decoded2 = $Body->decode('json', false);

      yield assert(
         assertion: is_object($decoded2),
         description: 'Decoded as object'
      );

      yield assert(
         assertion: $decoded2->name === 'Bootgly',
         description: 'Object name: ' . $decoded2->name
      );

      // @ Invalid JSON returns null
      $Body2 = new Body;
      $Body2->raw = 'not json';
      $result = $Body2->decode('json');

      yield assert(
         assertion: $result === null,
         description: 'Invalid JSON returns null'
      );

      // @ Empty body returns null
      $Body3 = new Body;
      $result2 = $Body3->decode('json');

      yield assert(
         assertion: $result2 === null,
         description: 'Empty body returns null'
      );
   }
);
