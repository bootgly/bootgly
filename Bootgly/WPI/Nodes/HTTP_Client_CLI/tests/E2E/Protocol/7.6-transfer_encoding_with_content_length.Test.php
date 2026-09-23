<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? M2 — a response framed by both Transfer-Encoding and Content-Length may be a smuggling
//   attempt: Transfer-Encoding frames it and the connection is never reused (RFC 9112 §6.3).
return new Test(
   description: 'It should honor Transfer-Encoding over Content-Length and close the connection',

   response: function (): string {
      return "HTTP/1.1 200 OK\r\nContent-Length: 3\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n0\r\n\r\n";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(method: 'GET', URI: '/framing/te-cl');
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 200 && $Response->Body->raw === 'hello',
         description: "the chunked body wins over Content-Length: 3: {$Response->code} {$Response->Body->raw}"
      );
      yield assert(
         assertion: $Response->closeConnection === true,
         description: 'the connection is closed after it, never reused'
      );
   }
);
