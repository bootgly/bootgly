<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Raw\Header;


return new Specification(
   description: 'It should parse raw header string into fields',
   test: function () {
      $Header = new Header;

      $raw = "Server: Bootgly\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Length: 13";
      $Header->define($raw);

      // @ Parse fields
      yield assert(
         assertion: $Header->get('Server') === 'Bootgly',
         description: 'Server header: ' . $Header->get('Server')
      );

      yield assert(
         assertion: $Header->get('Content-Type') === 'text/html; charset=UTF-8',
         description: 'Content-Type: ' . $Header->get('Content-Type')
      );

      yield assert(
         assertion: $Header->get('Content-Length') === '13',
         description: 'Content-Length: ' . $Header->get('Content-Length')
      );

      // @ Non-existent header
      yield assert(
         assertion: $Header->get('X-Missing') === null,
         description: 'Missing header: null'
      );

      // @ Raw preserved
      yield assert(
         assertion: $Header->raw === $raw,
         description: 'Raw string preserved'
      );

      // @ Length set
      yield assert(
         assertion: $Header->length === strlen($raw),
         description: 'Header length set: ' . $Header->length
      );
   }
);
