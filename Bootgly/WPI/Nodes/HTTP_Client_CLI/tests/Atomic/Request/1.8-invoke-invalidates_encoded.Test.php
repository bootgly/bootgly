<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;


return new Test(
   description: 'It should drop the memoized encoding whenever the request is rewritten',
   test: function () {
      // ! Anything that changes what the request IS must invalidate the bytes
      //   encoded from it, or a later dispatch would replay the old ones
      $Request = new Request;
      $Request->encoded = 'GET /stale HTTP/1.1' . "\r\n\r\n";
      $Request->encodedHost = '127.0.0.1';
      $Request->encodedPort = 8080;

      $Request('POST', '/fresh', ['X-Custom' => 'value'], 'body content');

      yield assert(
         assertion: $Request->encoded === null,
         description: 'Rewriting the request drops the encoding: '
            . var_export($Request->encoded, true)
      );

      // @ clear() is the redirect method-change path (301/302/303)
      $Request->encoded = 'stale';
      $Request->clear();

      yield assert(
         assertion: $Request->encoded === null,
         description: 'Clearing headers and body drops the encoding: '
            . var_export($Request->encoded, true)
      );

      // @ reset() returns the object to a pristine state
      $Request->encoded = 'stale';
      $Request->encodedHost = 'example.com';
      $Request->encodedPort = 443;
      $Request->reset();

      yield assert(
         assertion: $Request->encoded === null
            && $Request->encodedHost === null
            && $Request->encodedPort === null,
         description: 'Reset clears the encoding and the origin it was built for: '
            . var_export($Request->encoded, true) . ' '
            . var_export($Request->encodedHost, true) . ' '
            . var_export($Request->encodedPort, true)
      );
   }
);
