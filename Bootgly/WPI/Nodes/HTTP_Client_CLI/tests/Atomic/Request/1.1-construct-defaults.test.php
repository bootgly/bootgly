<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;


return new Specification(
   description: 'It should construct Request with default values',
   test: function () {
      $Request = new Request;

      yield assert(
         assertion: $Request->method === 'GET',
         description: 'Default method: ' . $Request->method
      );

      yield assert(
         assertion: $Request->URI === '/',
         description: 'Default URI: ' . $Request->URI
      );

      yield assert(
         assertion: $Request->protocol === 'HTTP/1.1',
         description: 'Default protocol: ' . $Request->protocol
      );

      yield assert(
         assertion: $Request->headers === [],
         description: 'Default headers: empty array'
      );

      yield assert(
         assertion: $Request->body === '',
         description: 'Default body: empty string'
      );
   }
);
