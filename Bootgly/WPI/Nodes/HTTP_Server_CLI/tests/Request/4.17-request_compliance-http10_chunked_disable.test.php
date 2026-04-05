<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should disable chunked Transfer-Encoding for HTTP/1.0 clients (RFC 9110 §2.5)',

   request: function () {
      return "GET / HTTP/1.0\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      // Activate chunked mode (should be disabled for HTTP/1.0 in encode)
      $Response->chunked;
      $Response->Body->raw = 'hello';

      return $Response;
   },

   test: function ($response) {
      // @ Assert: no Transfer-Encoding header, has Content-Length, HTTP/1.0 status-line
      if (\strpos($response, 'Transfer-Encoding') !== false) {
         return 'Transfer-Encoding should not be present for HTTP/1.0';
      }

      if (\strpos($response, 'Content-Length: 5') === false) {
         Vars::$labels = ['HTTP Response:'];
         dump(json_encode($response));
         return 'Content-Length: 5 expected for non-chunked body';
      }

      if (\strpos($response, 'HTTP/1.0 200 OK') !== 0) {
         Vars::$labels = ['HTTP Response:'];
         dump(json_encode($response));
         return 'Status-line should be HTTP/1.0';
      }

      return true;
   }
);
