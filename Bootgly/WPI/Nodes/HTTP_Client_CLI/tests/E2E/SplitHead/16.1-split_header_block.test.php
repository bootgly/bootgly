<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;


// ? HCLI-1 guard regression — Decoder_ is stateless, so while the HEADER
//   block is still incomplete its null return means "keep my buffer and
//   re-parse". Only the chunked decoder owns fed bytes. This spec splits
//   the header block itself across two writes: an unconditional
//   pending-buffer clear on null (dropping the instanceof guard) would
//   discard the first header fragment and the response could never parse.
return new Specification(
   description: 'It should reassemble a header block split across reads',

   response: function (): Generator {
      yield "HTTP/1.1 200 OK\r\nContent-Ty";
      yield "pe: text/plain\r\nContent-Length: 5\r\nConnection: close\r\n\r\nhello";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'GET',
         URI: '/split-head'
      );
   },

   test: function (Response $Response): Generator {
      yield assert(
         assertion: $Response->code === 200,
         description: "a split header block still parses: code {$Response->code} ({$Response->status})"
      );
      yield assert(
         assertion: $Response->Body->raw === 'hello'
            && $Response->Header->get('Content-Type') === 'text/plain',
         description: 'headers and body survive the split intact'
      );
   }
);
