<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;


return new Specification(
   description: 'It should prepare a POST request with string body',
   test: function () {
      $Request = new Request;
      $Request('POST', '/api/data', [], 'Hello World');

      yield assert(
         assertion: $Request->method === 'POST',
         description: 'Method: ' . $Request->method
      );

      yield assert(
         assertion: $Request->body === 'Hello World',
         description: 'Body raw string: ' . $Request->body
      );

      yield assert(
         assertion: $Request->Header->get('Content-Type') === 'text/plain',
         description: 'Content-Type auto-set: ' . $Request->Header->get('Content-Type')
      );

      yield assert(
         assertion: $Request->Header->get('Content-Length') === '11',
         description: 'Content-Length auto-set: ' . $Request->Header->get('Content-Length')
      );
   }
);
