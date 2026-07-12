<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should finalize a legal close-delimited body on peer EOF',
   response: function (): string {
      return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nConnection: close\r\n\r\nclose-body";
   },
   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request('GET', '/close-delimited');
   },
   test: function (Response $Response): Generator {
      yield assert(
         assertion: $Response->code === 200,
         description: 'peer EOF completes a legal close-delimited response'
      );
      yield assert(
         assertion: $Response->Body->raw === 'close-body'
            && $Response->Body->downloaded === 10
            && $Response->Body->waiting === false,
         description: 'the complete close-delimited body is retained'
      );
   }
);
