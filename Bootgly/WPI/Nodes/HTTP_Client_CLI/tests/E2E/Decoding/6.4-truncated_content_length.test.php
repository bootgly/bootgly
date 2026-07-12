<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should reject peer EOF before the declared Content-Length',
   response: function (): string {
      return "HTTP/1.1 200 OK\r\nContent-Length: 10\r\nConnection: close\r\n\r\nshort";
   },
   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request('GET', '/truncated-content-length');
   },
   test: function (Response $Response): Generator {
      yield assert(
         assertion: $Response->code === 0 && $Response->status === 'Truncated Response',
         description: 'truncated Content-Length framing is a transport failure'
      );
      yield assert(
         assertion: $Response->Body->raw === 'short'
            && $Response->Body->downloaded === 5
            && $Response->Body->waiting,
         description: 'partial Content-Length bytes are never blessed as complete'
      );
   }
);
