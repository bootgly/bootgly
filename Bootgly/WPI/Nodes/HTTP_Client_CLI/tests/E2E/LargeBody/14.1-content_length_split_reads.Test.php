<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? HCLI-10 regression — an origin that flushes its headers before the body
//   splits the response across read events. Before the fix, the header-only
//   first event charged consumed = headers + Content-Length (> buffer), the
//   caller wiped $pendingBuffer, and the request died as code 0
//   'Truncated Response' even though every byte arrived.
return new Test(
   description: 'It should reassemble a Content-Length body flushed after the headers',

   response: function (): Generator {
      yield "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 11\r\nConnection: close\r\n\r\n";
      yield 'hello world';
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'GET',
         URI: '/split-reads'
      );
   },

   test: function (Response $Response): Generator {
      yield assert(
         assertion: $Response->code === 200,
         description: "a header-first flush still completes: code {$Response->code} ({$Response->status})"
      );
      yield assert(
         assertion: $Response->Body->raw === 'hello world'
            && $Response->Body->waiting === false,
         description: 'the body flushed after the headers is fully reassembled'
      );
   }
);
