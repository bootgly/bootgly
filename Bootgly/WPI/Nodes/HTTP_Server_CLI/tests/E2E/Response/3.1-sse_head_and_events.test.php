<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// ! Expected wire bytes (test mode: no `Date` preset — removed by
//   Encoder_Testing on the worker's first response)
$head = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/event-stream\r\n"
   . "Cache-Control: no-cache\r\n"
   . "X-Accel-Buffering: no\r\n"
   . "Transfer-Encoding: chunked\r\n"
   . "\r\n";
$greet = "event: greet\nid: 1\ndata: hello\n\n";
$multi = "data: multi\ndata: line\n\n";
$expected = $head
   . dechex(strlen($greet)) . "\r\n{$greet}\r\n"
   . dechex(strlen($multi)) . "\r\n{$multi}\r\n"
   . "0\r\n\r\n";

return new Specification(
   description: 'It should stream chunked events and end with the terminal chunk',

   request: function () {
      return "GET /events HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      $SSE = $Response->SSE;
      $SSE->heartbeat = 0;

      $SSE->open();
      $SSE->send('hello', event: 'greet', id: '1');
      $SSE->send("multi\nline");
      $SSE->close();

      return $Response;
   },
   responseLength: strlen($expected),

   test: function ($response) use ($expected) {
      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'SSE stream bytes not matched';
      }

      return true;
   }
);
