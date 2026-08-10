<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// RES-1 regression: `end(400)` used to fall through into the 416 preset,
// shipping `416 Range Not Satisfiable`, a one-space body and a cleaned
// header set. The code passed to end() must reach the wire as-is, with
// every header the handler set still present.

// ! Expected wire bytes (test mode: no `Date` preset)
$expected = "HTTP/1.1 400 Bad Request\r\n"
   . "Server: Bootgly\r\n"
   . "X-Request-Id: poc-42\r\n"
   . "Content-Type: text/html; charset=UTF-8\r\n"
   . "Content-Length: 0\r\n"
   . "\r\n";

return new Specification(
   description: 'It should honor the status passed to end() and keep handler headers',

   request: function () {
      return "GET /end/400 HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   responseLength: strlen($expected),
   response: function (Request $Request, Response $Response): Response {
      $Response->Header->set('X-Request-Id', 'poc-42');
      return $Response->end(400);
   },

   test: function ($response) use ($expected) {
      // @ Assert — byte-exact: real 400 status line, handler header kept,
      //   no 416 artifacts (no Content-Range, no one-space body)
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'end(400) must ship a real 400 and preserve handler headers';
      }

      return true;
   }
);
