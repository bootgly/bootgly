<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;


return new Specification(
   description: 'It should prepare a POST request with JSON body',
   test: function () {
      $Request = new Request;
      $Request('POST', '/api/users', [], ['name' => 'Bootgly', 'version' => 1]);

      yield assert(
         assertion: $Request->method === 'POST',
         description: 'Method: ' . $Request->method
      );

      yield assert(
         assertion: $Request->URI === '/api/users',
         description: 'URI: ' . $Request->URI
      );

      $expected = json_encode(['name' => 'Bootgly', 'version' => 1]);
      yield assert(
         assertion: $Request->body === $expected,
         description: 'Body JSON encoded: ' . $Request->body
      );

      yield assert(
         assertion: $Request->Header->get('Content-Type') === 'application/json',
         description: 'Content-Type auto-set: ' . $Request->Header->get('Content-Type')
      );

      yield assert(
         assertion: $Request->Header->get('Content-Length') === (string) strlen($expected),
         description: 'Content-Length auto-set: ' . $Request->Header->get('Content-Length')
      );
   }
);
