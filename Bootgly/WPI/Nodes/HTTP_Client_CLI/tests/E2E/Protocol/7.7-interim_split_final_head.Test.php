<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? HCLI-17 — the bytes after a 102 that are only a FRAGMENT of the final head must survive
//   until the rest arrives: the final response is whole, never parsed from mid-header.
return new Test(
   description: 'It should keep a final head split across reads after an interim response',

   response: function (): Generator {
      yield "HTTP/1.1 102 Processing\r\n\r\nHTTP/1.1 200 OK\r\nContent-Ty";
      // ! Far enough apart to land in two reads, even on a loaded host
      usleep(200_000);
      yield "pe: text/plain\r\nContent-Length: 5\r\nConnection: close\r\n\r\nhello";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(method: 'GET', URI: '/interim/split');
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 200 && $Response->status === 'OK' && $Response->Body->raw === 'hello',
         description: "the final response is whole: {$Response->code} " . json_encode($Response->status) . " {$Response->Body->raw}"
      );
      yield assert(
         assertion: $Response->Header->get('Content-Type') === 'text/plain',
         description: 'the split field is read whole'
      );
   }
);
