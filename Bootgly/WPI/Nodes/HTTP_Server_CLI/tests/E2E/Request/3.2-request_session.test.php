<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should start a session and persist data',
   Separator: new Separator(left: 'Session'),

   request: function ($host) {
      $request = <<<HTTP
      GET / HTTP/1.1\r
      Host: {$host}\r
      \r\n
      HTTP;

      return $request;
   },
   response: function (Request $Request, Response $Response) {
      $Session = $Request->Session;
      $Session->set('foo', 'bar');

      return $Response
         ->send($Session->get('foo'));
   },

   test: function ($response) {
      // @ Verify Set-Cookie header
      if (strpos($response, 'Set-Cookie: PHPSID=') === false) {
         return false;
      }

      // @ Verify Session Data
      if (strpos($response, "\r\n\r\nbar") === false) {
         return false;
      }

      return true;
   }
);
