<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Raw\Header;


return new Specification(
   description: 'It should remove header fields',
   test: function () {
      $Header = new Header;
      $Header->set('X-Custom', 'value');
      $Header->set('X-Other', 'other');

      // @ Verify exists
      yield assert(
         assertion: $Header->get('X-Custom') === 'value',
         description: 'Before remove: ' . $Header->get('X-Custom')
      );

      // @ Remove
      $Header->remove('X-Custom');

      yield assert(
         assertion: $Header->get('X-Custom') === null,
         description: 'After remove: null'
      );

      // @ Other header untouched
      yield assert(
         assertion: $Header->get('X-Other') === 'other',
         description: 'Other header intact: ' . $Header->get('X-Other')
      );
   }
);
