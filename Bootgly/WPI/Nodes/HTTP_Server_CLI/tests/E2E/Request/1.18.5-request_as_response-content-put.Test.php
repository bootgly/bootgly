<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Regression (REQ-1) — the `$fields` get hook parses the body on its
 * media-type, not on the request method. A PUT carrying the byte-identical
 * body of 1.18.4 must yield the byte-identical fields.
 *
 * No `receive()` call here on purpose: calling it is the escape hatch that
 * hides the defect, since `receive()` never gated on the method.
 */
return new Test(
   description: 'It should process request fields on PUT (application/json)!',

   request: function () {

      return
      <<<HTTP
      PUT / HTTP/1.1\r
      Host: lab.bootgly.com:8080\r
      User-Agent: insomnia/2023.4.0\r
      Content-Type: application/json\r
      Accept: */*\r
      Content-Length: 35\r
      \r
      {"test1":"value1","test2":"value2"}\r\n
      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response->JSON->send($Request->fields);
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: application/json\r
      Content-Length: 35\r
      \r
      {"test1":"value1","test2":"value2"}
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
