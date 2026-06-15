<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {

      return <<<HTTP
      GET / HTTP/1.1\r
      Host: lab.bootgly.com\r
      User-Agent: Bootgly/TCP-Server\r
      Accept-Language: en-US,en;q=0.9\r
      \r

      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $headers = $Request->headers;
      return $Response->JSON->send($headers);
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: application/json\r
      Content-Length: 96\r
      \r
      {"host":"lab.bootgly.com","user-agent":"Bootgly\/TCP-Server","accept-language":"en-US,en;q=0.9"}
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump($response, $expected);
         return 'Response raw not matched';
      }

      return true;
   }
);
