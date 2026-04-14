<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Raw\Header;


return new Specification(
   description: 'It should build raw header string from fields',
   test: function () {
      $Header = new Header;
      $Header->set('Host', 'example.com');
      $Header->set('Accept', 'text/html');

      $raw = $Header->build();

      yield assert(
         assertion: str_contains($raw, "Host: example.com\r\n"),
         description: 'Raw contains Host header'
      );

      yield assert(
         assertion: str_contains($raw, "Accept: text/html\r\n"),
         description: 'Raw contains Accept header'
      );

      // @ Multi-value header
      $Header2 = new Header;
      $Header2->append('Set-Cookie', 'a=1');
      $Header2->append('Set-Cookie', 'b=2');

      $raw2 = $Header2->build();

      yield assert(
         assertion: str_contains($raw2, "Set-Cookie: a=1\r\n"),
         description: 'Raw contains first multi-value'
      );

      yield assert(
         assertion: str_contains($raw2, "Set-Cookie: b=2\r\n"),
         description: 'Raw contains second multi-value'
      );
   }
);
