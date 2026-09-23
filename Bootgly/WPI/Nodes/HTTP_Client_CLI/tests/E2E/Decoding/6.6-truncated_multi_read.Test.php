<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? H-HCLI-4 — a waiting body is collected outside the Response until it ends. A
//   Content-Length body cut short after several reads still reports every byte that
//   arrived, never only the bytes of the head's read.
return new Test(
   description: 'It should keep every received byte of a Content-Length body truncated after several reads',

   response: function (): Generator {
      yield "HTTP/1.1 200 OK\r\nContent-Length: 20\r\nConnection: close\r\n\r\nabc";
      // ! Far enough apart to land in separate reads, even on a loaded host
      usleep(200_000);
      yield 'def';
      usleep(200_000);
      yield 'gh';
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request('GET', '/truncated/multi-read');
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 0 && $Response->status === 'Truncated Response',
         description: "a short body is a truncation: {$Response->code} {$Response->status}"
      );
      yield assert(
         assertion: $Response->Body->raw === 'abcdefgh'
            && $Response->Body->downloaded === 8
            && $Response->Body->length === 20
            && $Response->Body->waiting,
         description: 'every received byte is kept, never blessed as complete: ' . json_encode($Response->Body->raw) . " ({$Response->Body->downloaded}/{$Response->Body->length})"
      );
   }
);
