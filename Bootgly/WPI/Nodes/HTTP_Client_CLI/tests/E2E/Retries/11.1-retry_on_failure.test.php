<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   Separator: new Separator(line: 'Retries'),
   description: 'It should retry on connection failure and succeed',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      // First connection: server closes immediately (simulates failure)
      function () {
         return ''; // Empty response triggers connection close
      },
      // Second connection (retry): success
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 7\r\nConnection: close\r\n\r\nRetried";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxRetries = 2;
         $Client->retryDelay = 0.1;
         $response = $Client->request(method: 'GET', URI: '/flaky');
         $Client->maxRetries = 0; // @ Restore default
         return $response;
      },
      function (HTTP_Client_CLI $Client): Response {
         $r = new Response;
         $r->code = -1;
         return $r;
      },
   ],

   test: function (Response $Response1, Response $Response2) {
      yield assert(
         assertion: $Response1->code === 200,
         description: "Retry succeeded with status 200: {$Response1->code}"
      );

      yield assert(
         assertion: $Response1->Body->raw === 'Retried',
         description: "Body matches retried content: {$Response1->Body->raw}"
      );
   }
);
