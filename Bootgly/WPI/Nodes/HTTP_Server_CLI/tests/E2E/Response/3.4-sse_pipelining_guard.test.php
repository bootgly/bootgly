<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// ! Expected wire bytes: only the SSE stream — the pipelined second request
//   in the same payload is discarded by Decoder_Streaming (the hijacked
//   connection is write-only), so no second response head may appear.
$head = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/event-stream\r\n"
   . "Cache-Control: no-cache\r\n"
   . "X-Accel-Buffering: no\r\n"
   . "Transfer-Encoding: chunked\r\n"
   . "\r\n";
$guarded = "data: guarded\n\n";
$expected = $head . dechex(strlen($guarded)) . "\r\n{$guarded}\r\n";

return new Specification(
   description: 'It should discard pipelined requests on a hijacked SSE connection',

   request: function () {
      // @ Two pipelined requests in one payload — the second must never
      //   interleave a response into the open stream
      return "GET /events HTTP/1.1\r\nHost: localhost\r\n\r\n"
         . "GET /other HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      $SSE = $Response->SSE;
      $SSE->heartbeat = 0;

      $SSE->open();
      $SSE->send('guarded');

      return $Response;
   },
   responseLength: strlen($expected),

   test: function ($response) use ($expected) {
      // @ Assert — byte-exact and exactly one response head
      if ($response !== $expected || substr_count($response, 'HTTP/1.1') !== 1) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Pipelined request leaked into the SSE stream';
      }

      return true;
   }
);
