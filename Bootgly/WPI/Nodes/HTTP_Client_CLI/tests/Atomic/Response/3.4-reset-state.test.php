<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;


return new Specification(
   description: 'It should reset Response state to defaults',
   test: function () {
      $Response = new Response;

      // @ Modify state
      $Response->protocol = 'HTTP/1.0';
      $Response->code = 404;
      $Response->status = 'Not Found';
      $Response->closeConnection = true;

      // @ Reset
      $Response->reset();

      yield assert(
         assertion: $Response->protocol === 'HTTP/1.1',
         description: 'After reset - protocol: ' . $Response->protocol
      );

      yield assert(
         assertion: $Response->code === 0,
         description: 'After reset - code: ' . $Response->code
      );

      yield assert(
         assertion: $Response->status === '',
         description: 'After reset - status: empty'
      );

      yield assert(
         assertion: $Response->closeConnection === false,
         description: 'After reset - closeConnection: false'
      );

      yield assert(
         assertion: $Response->body === '',
         description: 'After reset - body: empty'
      );

      yield assert(
         assertion: $Response->headers === [],
         description: 'After reset - headers: empty'
      );
   }
);
