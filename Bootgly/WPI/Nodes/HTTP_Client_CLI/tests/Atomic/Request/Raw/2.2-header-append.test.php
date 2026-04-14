<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Raw\Header;


return new Specification(
   description: 'It should append header values',
   test: function () {
      $Header = new Header;

      // @ Append to non-existent creates single value
      $Header->append('Accept', 'text/html');

      yield assert(
         assertion: $Header->get('Accept') === 'text/html',
         description: 'First append: ' . $Header->get('Accept')
      );

      // @ Append again creates array
      $Header->append('Accept', 'application/json');

      yield assert(
         assertion: $Header->get('Accept') === 'text/html, application/json',
         description: 'Second append (joined): ' . $Header->get('Accept')
      );

      // @ Verify fields is array
      yield assert(
         assertion: is_array($Header->fields['Accept']),
         description: 'Fields value is array after multiple appends'
      );
   }
);
