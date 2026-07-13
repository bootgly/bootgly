<?php

use function microtime;

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

$elapsed = null;

return new Specification(
   description: 'It should back off exponentially between retries (base doubles per attempt)',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      // First connection: server closes immediately (failure #1)
      function () { return ''; },
      // Second connection: closes again (failure #2)
      function () { return ''; },
      // Third connection: success
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Length: 7\r\nConnection: close\r\n\r\nbackoff";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$elapsed): Response {
         $Client->maxRetries = 2;
         $Client->retryDelay = 0.2;
         $Client->retryJitter = 0.0;   // deterministic timing
         $Client->retryTimeout = 60.0;

         $started = microtime(true);
         $Response = $Client->request(method: 'GET', URI: '/backoff');
         $elapsed = microtime(true) - $started;

         // @ Restore defaults
         $Client->maxRetries = 0;
         $Client->retryDelay = 1.0;
         $Client->retryJitter = 0.25;

         return $Response;
      },
      function (HTTP_Client_CLI $Client): Response {
         $Dummy = new Response;
         $Dummy->code = -1;
         return $Dummy;
      },
      function (HTTP_Client_CLI $Client): Response {
         $Dummy = new Response;
         $Dummy->code = -1;
         return $Dummy;
      },
   ],

   test: function (Response $Response1, Response $Response2, Response $Response3) use (&$elapsed) {
      yield assert(
         assertion: $Response1->code === 200 && $Response1->Body->raw === 'backoff',
         description: "Second retry succeeded: {$Response1->code} '{$Response1->Body->raw}'"
      );

      // ! Delays: 0.2 (retry #1) + 0.4 (retry #2, doubled) = 0.6s minimum
      yield assert(
         assertion: $elapsed >= 0.6,
         description: "Exponential backoff waited at least 0.6s: {$elapsed}"
      );

      yield assert(
         assertion: $elapsed < 5.0,
         description: "Backoff did not overshoot: {$elapsed}"
      );
   }
);
