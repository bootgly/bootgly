<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should return fresh when unmodified since the date',

   request: function () {

      return
      <<<HTTP
      GET / HTTP/1.1\r
      Host: lab.bootgly.com:8080\r
      User-Agent: insomnia/2023.4.0\r
      If-Modified-Since: Fri, 14 Jul 2023 11:00:00 GMT\r
      Accept: */*\r
      \r\n\r\n
      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $Response->Header->set('Last-Modified', 'Fri, 14 Jul 2023 10:00:00 GMT');

      if ($Request->fresh) {
         return $Response(code: 304);
      } else {
         return $Response(body: 'test')->send();
      }
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 304 Not Modified\r
      Server: Bootgly\r
      Last-Modified: Fri, 14 Jul 2023 10:00:00 GMT\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 0\r
      \r
      
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response raw not matched';
      }

      return true;
   }
);
