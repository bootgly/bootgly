<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? H-HCLI-4 — a timeout in the middle of a body keeps the bytes that arrived before
//   it: the collector hands them to the Response at the timeout, as any other end.
return new Test(
   description: 'It should keep the received body bytes when the response times out mid-body',

   response: function (): Generator {
      yield "HTTP/1.1 200 OK\r\nContent-Length: 20\r\nConnection: close\r\n\r\nabc";
      // ! Far enough apart to land in separate reads, even on a loaded host
      usleep(200_000);
      yield 'def';
      sleep(2);
      yield 'ghijklmnopqrst';
   },

   request: function (HTTP_Client_CLI $Client): Response {
      $Client->timeout = 1;
      $Response = $Client->request(method: 'GET', URI: '/timeout/mid-body');
      $Client->timeout = 30; // @ Restore default

      return $Response;
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 0 && $Response->status === 'Timeout',
         description: "the response times out: {$Response->code} {$Response->status}"
      );
      yield assert(
         assertion: $Response->Body->raw === 'abcdef'
            && $Response->Body->downloaded === 6
            && $Response->Body->waiting,
         description: 'the bytes received before the timeout are kept: ' . json_encode($Response->Body->raw) . " ({$Response->Body->downloaded})"
      );
   }
);
