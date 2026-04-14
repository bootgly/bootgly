<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;


return new Specification(
   description: 'It should reset Request state to defaults',
   test: function () {
      $Request = new Request;
      $Request('POST', '/api/data', ['X-Custom' => 'value'], 'body content');

      // @ Verify state before reset
      yield assert(
         assertion: $Request->method === 'POST',
         description: 'Before reset - method: POST'
      );

      yield assert(
         assertion: $Request->body !== '',
         description: 'Before reset - body not empty'
      );

      // @ Reset
      $Request->reset();

      yield assert(
         assertion: $Request->method === 'GET',
         description: 'After reset - method: ' . $Request->method
      );

      yield assert(
         assertion: $Request->URI === '/',
         description: 'After reset - URI: ' . $Request->URI
      );

      yield assert(
         assertion: $Request->headers === [],
         description: 'After reset - headers: empty'
      );

      yield assert(
         assertion: $Request->body === '',
         description: 'After reset - body: empty'
      );
   }
);
