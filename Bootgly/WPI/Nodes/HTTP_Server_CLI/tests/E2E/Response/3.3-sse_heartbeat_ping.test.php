<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// ! Expected wire bytes: head + one keep-alive comment chunk pushed by the
//   supervisor Timer (heartbeat: 1s — fires within the 2s read window).
//   The stream is left open; the runner's stale-drain + reconnect between
//   tests disposes of it.
$head = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/event-stream\r\n"
   . "Cache-Control: no-cache\r\n"
   . "X-Accel-Buffering: no\r\n"
   . "Transfer-Encoding: chunked\r\n"
   . "\r\n";
$expected = $head . "3\r\n:\n\n\r\n";

return new Specification(
   description: 'It should push a keep-alive comment after heartbeat seconds of silence',

   request: function () {
      return "GET /events HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      $SSE = $Response->SSE;
      $SSE->heartbeat = 1;

      $SSE->open();

      return $Response;
   },
   responseLength: strlen($expected),

   test: function ($response) use ($expected) {
      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'SSE heartbeat bytes not matched';
      }

      return true;
   }
);
