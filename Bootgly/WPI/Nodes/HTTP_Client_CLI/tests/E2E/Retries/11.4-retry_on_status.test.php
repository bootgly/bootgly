<?php

use function microtime;

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

$elapsed = null;

return new Specification(
   description: 'It should retry opt-in HTTP statuses (503) honoring Retry-After delta-seconds',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      // First response: 503 asking the client to come back in 1 second
      function () {
         return "HTTP/1.1 503 Service Unavailable\r\nRetry-After: 1\r\nContent-Length: 4\r\nConnection: close\r\n\r\nbusy";
      },
      // Second connection (HTTP-level retry): success
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Length: 9\r\nConnection: close\r\n\r\nrecovered";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$elapsed): Response {
         $Client->maxRetries = 1;
         $Client->retryDelay = 0.1;
         $Client->retryJitter = 0.0;
         $Client->retryOn = [503];

         $started = microtime(true);
         $Response = $Client->request(method: 'GET', URI: '/unavailable');
         $elapsed = microtime(true) - $started;

         // @ Restore defaults
         $Client->maxRetries = 0;
         $Client->retryDelay = 1.0;
         $Client->retryJitter = 0.25;
         $Client->retryOn = [];

         return $Response;
      },
      function (HTTP_Client_CLI $Client): Response {
         $Dummy = new Response;
         $Dummy->code = -1;
         return $Dummy;
      },
   ],

   test: function (Response $Response1, Response $Response2) use (&$elapsed) {
      yield assert(
         assertion: $Response1->code === 200 && $Response1->Body->raw === 'recovered',
         description: "HTTP-level retry recovered the request: {$Response1->code} '{$Response1->Body->raw}'"
      );

      // ! Retry-After: 1 outweighs the 0.1s backoff base
      yield assert(
         assertion: $elapsed >= 1.0,
         description: "Retry-After was honored (waited >= 1s): {$elapsed}"
      );
   }
);
