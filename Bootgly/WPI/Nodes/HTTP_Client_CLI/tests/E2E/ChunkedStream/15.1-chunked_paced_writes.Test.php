<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? HCLI-1 regression — a paced origin delivers each chunk in its own write,
//   so the chunked decoder sees the stream across 3+ read events. Before the
//   fix the caller kept $pendingBuffer after the decoder had already absorbed
//   it, re-fed the whole accumulation on every event, and the body came back
//   as a replay salad (e.g. 'ALPHA1ALPHA1BRAVO2...') under a 200 OK.
return new Test(
   description: 'It should reassemble a chunked body paced one chunk per write',

   response: function (): Generator {
      yield "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nContent-Type: text/plain\r\nConnection: close\r\n\r\n";
      yield "6\r\nALPHA1\r\n";
      yield "6\r\nBRAVO2\r\n";
      yield "6\r\nCHARL3\r\n";
      yield "0\r\n\r\n";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'GET',
         URI: '/chunked-paced'
      );
   },

   test: function (Response $Response): Generator {
      yield assert(
         assertion: $Response->code === 200,
         description: "paced chunked response completes: code {$Response->code} ({$Response->status})"
      );
      yield assert(
         assertion: $Response->Body->raw === 'ALPHA1BRAVO2CHARL3',
         description: "chunks arrive exactly once, in order (got '{$Response->Body->raw}')"
      );
      yield assert(
         assertion: $Response->Body->length === 18 && $Response->Body->waiting === false,
         description: 'body length matches the true payload'
      );
   }
);
