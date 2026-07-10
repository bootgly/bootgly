<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// ! Expected wire bytes (no `Date` preset in test mode)
$head = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/event-stream\r\n"
   . "Cache-Control: no-cache\r\n"
   . "X-Accel-Buffering: no\r\n"
   . "Transfer-Encoding: chunked\r\n"
   . "\r\n";
$retry = "retry: 3000\n\n";
$resume = "data: 41\n\n";
$expected = $head
   . dechex(strlen($retry)) . "\r\n{$retry}\r\n"
   . dechex(strlen($resume)) . "\r\n{$resume}\r\n"
   . "0\r\n\r\n";

return new Specification(
   description: 'It should expose Last-Event-ID and send the retry field once after open',

   request: function () {
      return "GET /events HTTP/1.1\r\nHost: localhost\r\nLast-Event-ID: 41\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      $SSE = $Response->SSE;
      $SSE->heartbeat = 0;
      $SSE->retry = 3000;

      $SSE->open();
      $SSE->send($SSE->last);
      $SSE->close();

      return $Response;
   },
   responseLength: strlen($expected),

   test: function ($response) use ($expected) {
      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'SSE retry/Last-Event-ID bytes not matched';
      }

      return true;
   }
);
