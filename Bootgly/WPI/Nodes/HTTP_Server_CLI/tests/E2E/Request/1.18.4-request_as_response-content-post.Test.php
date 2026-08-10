<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Control for the 1.18.5-1.18.7 method family (REQ-1).
 *
 * 1.18.2 and 1.18.3 call `receive()` before reading `$Request->fields`, so they
 * exercise the parser and never the `$fields` get hook. This one reads the
 * property directly on a POST — the shape the hook has always admitted — and
 * must stay byte-identical while the hook stops gating on the method.
 */
return new Test(
   description: 'It should parse a POST body read straight from $Request->fields!',

   request: function () {

      return
      <<<HTTP
      POST / HTTP/1.1\r
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
