<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// ! Expected wire bytes: chunked framing only — a stale Content-Length
//   from EVERY serialization source (set()/prepare()/queue()/preset(),
//   canonical AND case-variant names) must be stripped by open(), never
//   emitted alongside Transfer-Encoding: chunked; and exactly one
//   Transfer-Encoding survives when a case-variant duplicate exists
$head = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/event-stream\r\n"
   . "Cache-Control: no-cache\r\n"
   . "X-Accel-Buffering: no\r\n"
   . "Transfer-Encoding: chunked\r\n"
   . "X-App: demo\r\n"
   . "\r\n";
$event = "data: framed\n\n";
$expected = $head . dechex(strlen($event)) . "\r\n{$event}\r\n" . "0\r\n\r\n";

return new Specification(
   description: 'It should strip a stale Content-Length before opening the SSE stream',

   request: function () {
      return "GET /events HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      // @ Stale lengths from every mutable header source, in every case
      $Response(headers: ['Content-Length' => '999', 'X-App' => 'demo']);
      $Response->Header->set('Content-Length', '123');
      $Response->Header->set('content-length', '7');
      $Response->Header->queue('Content-Length', '77');
      $Response->Header->preset('content-length', '55');
      // @ Case-variant framing duplicate — open() must leave exactly one
      $Response->Header->set('transfer-encoding', 'identity');

      $SSE = $Response->SSE;
      $SSE->heartbeat = 0;

      $SSE->open();
      $SSE->send('framed');
      $SSE->close();

      // ! Restore the worker-persistent preset (open() only masks it for
      //   this response; the entry itself would leak into later specs)
      $Response->Header->preset('content-length', null);

      return $Response;
   },
   responseLength: strlen($expected),

   test: function ($response) use ($expected) {
      // @ Assert — byte-exact wire AND a case-insensitive leak scan
      if ($response !== $expected || stripos($response, 'content-length') !== false) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Content-Length leaked into the SSE head';
      }

      return true;
   }
);
