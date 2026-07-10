<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// ! Quoted-pair (RFC 9110 §5.6.4): the escaped DQUOTE inside the extension
//   value must not close the quoted string — the comma and the `no-cache`
//   text inside stay VALUE data, so a separate top-level `no-cache` is
//   still appended.
$head = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/event-stream\r\n"
   . "Cache-Control: foo=\"a\\\", no-cache, b\", no-cache\r\n"
   . "X-Accel-Buffering: no\r\n"
   . "Transfer-Encoding: chunked\r\n"
   . "\r\n";
$event = "data: quoted\n\n";
$expected = $head . dechex(strlen($event)) . "\r\n{$event}\r\n" . "0\r\n\r\n";

return new Specification(
   description: 'It should not let a quoted-pair hide the missing no-cache directive',

   request: function () {
      return "GET /events-cache-quoted HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   responseLength: strlen($expected),
   response: function (Request $Request, Response $Response): Response {
      $Response->Header->set('Cache-Control', 'foo="a\\", no-cache, b"');

      $SSE = $Response->SSE;
      $SSE->heartbeat = 0;

      $SSE->open();
      $SSE->send('quoted');
      $SSE->close();

      return $Response;
   },

   test: function ($response) use ($expected) {
      // @ Assert — byte-exact: value data untouched, mandatory directive
      //   appended at the top level
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Quoted-pair Cache-Control was not merged correctly';
      }

      return true;
   }
);
