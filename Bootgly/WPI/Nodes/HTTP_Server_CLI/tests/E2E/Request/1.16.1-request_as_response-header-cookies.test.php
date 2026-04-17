<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {

      return
      <<<HTTP
      GET / HTTP/1.1\r
      Host: localhost\r
      Cookie: cookie1=value1; Expires=Wed, 21 Oct 2023 07:28:00 GMT; Path=/\r
      Cookie: cookie2=value2; Expires=Wed, 21 Oct 2023 07:28:00 GMT; Path=/\r
      Cookie: cookie3=value3; Expires=Wed, 21 Oct 2023 07:28:00 GMT; Path=/\r
      \r

      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $cookies = $Request->cookies;
      return $Response->Json->send($cookies);
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: application/json\r
      Content-Length: 226\r
      \r
      [{"cookie1":"value1","Expires":"Wed, 21 Oct 2023 07:28:00 GMT","Path":"\/"},{"cookie2":"value2","Expires":"Wed, 21 Oct 2023 07:28:00 GMT","Path":"\/"},{"cookie3":"value3","Expires":"Wed, 21 Oct 2023 07:28:00 GMT","Path":"\/"}]
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
