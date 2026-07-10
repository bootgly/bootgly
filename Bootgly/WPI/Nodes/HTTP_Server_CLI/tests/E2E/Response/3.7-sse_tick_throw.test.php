<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// ! A throwing Tick producer must not become a silent zombie stream: the
//   supervisor contains the exception, reports it and ends the stream
//   deterministically — head, then the terminal chunk, then close.
$expected = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/event-stream\r\n"
   . "Cache-Control: no-cache\r\n"
   . "X-Accel-Buffering: no\r\n"
   . "Transfer-Encoding: chunked\r\n"
   . "\r\n"
   . "0\r\n\r\n";

return new Specification(
   description: 'It should end the SSE stream when the Tick producer throws',

   request: function () {
      return "GET /sse-tick-throw HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   responseLength: strlen($expected),
   response: function (Request $Request, Response $Response): Response {
      $SSE = $Response->SSE;
      $SSE->heartbeat = 0;

      $SSE->open(
         Tick: static function (): void {
            throw new RuntimeException('tick-failure');
         },
         interval: 1
      );

      return $Response;
   },

   test: function ($response) use ($expected) {
      // @ Assert — the stream ends with the terminal chunk, no zombie
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Throwing Tick did not end the SSE stream deterministically';
      }

      return true;
   }
);
