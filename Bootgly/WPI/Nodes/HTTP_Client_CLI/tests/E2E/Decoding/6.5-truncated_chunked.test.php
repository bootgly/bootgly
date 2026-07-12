<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should reject peer EOF before the terminal chunk',
   response: function (): string {
      return "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n5\r\nabc";
   },
   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request('GET', '/truncated-chunked');
   },
   test: function (Response $Response): Generator {
      yield assert(
         assertion: $Response->code === 0 && $Response->status === 'Truncated Response',
         description: 'truncated chunked framing is a transport failure'
      );
      yield assert(
         assertion: $Response->Body->waiting,
         description: 'an incomplete chunk stream is never marked complete'
      );
   }
);
