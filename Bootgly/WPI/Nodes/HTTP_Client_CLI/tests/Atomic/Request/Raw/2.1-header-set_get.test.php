<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Raw\Header;


return new Specification(
   description: 'It should set and get header fields',
   test: function () {
      $Header = new Header;

      // @ Set and get
      $Header->set('Content-Type', 'application/json');

      yield assert(
         assertion: $Header->get('Content-Type') === 'application/json',
         description: 'Get exact name: ' . $Header->get('Content-Type')
      );

      // @ Get non-existent
      yield assert(
         assertion: $Header->get('X-Missing') === null,
         description: 'Get non-existent: null'
      );

      // @ Overwrite
      $Header->set('Content-Type', 'text/plain');

      yield assert(
         assertion: $Header->get('Content-Type') === 'text/plain',
         description: 'Overwrite value: ' . $Header->get('Content-Type')
      );
   }
);
