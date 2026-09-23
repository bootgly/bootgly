<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? H-HCLI-4 — a body refused mid-collection by the wire cap still reports the bytes
//   collected before the refusing read, like every other end of an incomplete body.
return new Test(
   description: 'It should keep the collected body bytes when the wire cap refuses a response mid-body',

   response: function (): Generator {
      yield "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\nabc";
      // ! Far enough apart to land in separate reads, even on a loaded host
      usleep(200_000);
      yield 'def';
      usleep(200_000);
      yield str_repeat('x', 4096);
   },

   request: function (HTTP_Client_CLI $Client): Response {
      $default = $Client->maxResponseBytes;
      // ! 38-byte head + 6 body bytes fit; any byte of the third write trips it
      $Client->maxResponseBytes = 48;
      $Response = $Client->request(method: 'GET', URI: '/overflow/mid-body');
      $Client->maxResponseBytes = $default; // @ Restore default

      return $Response;
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 0 && $Response->status === 'Response Too Large',
         description: "the read past the cap refuses the response: {$Response->code} {$Response->status}"
      );
      yield assert(
         assertion: $Response->Body->raw === 'abcdef'
            && $Response->Body->downloaded === 6
            && $Response->Body->waiting,
         description: 'the bytes collected before the refusal are kept: ' . json_encode($Response->Body->raw) . " ({$Response->Body->downloaded})"
      );
   }
);
