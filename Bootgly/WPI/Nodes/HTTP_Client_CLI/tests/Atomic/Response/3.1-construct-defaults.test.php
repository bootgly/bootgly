<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;


return new Specification(
   description: 'It should construct Response with default values',
   test: function () {
      $Response = new Response;

      yield assert(
         assertion: $Response->protocol === 'HTTP/1.1',
         description: 'Default protocol: ' . $Response->protocol
      );

      yield assert(
         assertion: $Response->code === 0,
         description: 'Default status code: ' . $Response->code
      );

      yield assert(
         assertion: $Response->status === '',
         description: 'Default status text: empty'
      );

      yield assert(
         assertion: $Response->body === '',
         description: 'Default body: empty'
      );

      yield assert(
         assertion: $Response->closeConnection === false,
         description: 'Default closeConnection: false'
      );

      yield assert(
         assertion: $Response->headers === [],
         description: 'Default headers: empty'
      );
   }
);
