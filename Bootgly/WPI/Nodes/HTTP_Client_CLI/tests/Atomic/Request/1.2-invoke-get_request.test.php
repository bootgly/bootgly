<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;


return new Specification(
   description: 'It should prepare a GET request with custom URI and headers',
   test: function () {
      $Request = new Request;
      $Request('GET', '/api/users', ['Accept' => 'application/json']);

      yield assert(
         assertion: $Request->method === 'GET',
         description: 'Method: ' . $Request->method
      );

      yield assert(
         assertion: $Request->URI === '/api/users',
         description: 'URI: ' . $Request->URI
      );

      yield assert(
         assertion: $Request->Header->get('Accept') === 'application/json',
         description: 'Accept header: ' . $Request->Header->get('Accept')
      );

      yield assert(
         assertion: $Request->body === '',
         description: 'Body: empty (GET has no body)'
      );
   }
);
