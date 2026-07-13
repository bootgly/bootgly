<?php

use function microtime;

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

$elapsed = null;

return new Specification(
   description: 'It should give up retrying when the next delay would exceed the wall-clock budget',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      // Single connection: server closes immediately; the 1.0s backoff delay
      // exceeds the 0.5s budget, so NO retry connection may ever arrive.
      function () { return ''; },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$elapsed): Response {
         $Client->maxRetries = 5;
         $Client->retryDelay = 1.0;
         $Client->retryJitter = 0.0;
         $Client->retryTimeout = 0.5; // budget vetoes the very first 1.0s delay

         $started = microtime(true);
         $Response = $Client->request(method: 'GET', URI: '/budget');
         $elapsed = microtime(true) - $started;

         // @ Restore defaults
         $Client->maxRetries = 0;
         $Client->retryJitter = 0.25;
         $Client->retryTimeout = 60.0;

         return $Response;
      },
   ],

   test: function (Response $Response1) use (&$elapsed) {
      yield assert(
         assertion: $Response1->code === 0,
         description: "The request failed without retrying: {$Response1->code}"
      );

      yield assert(
         assertion: $elapsed < 1.0,
         description: "The campaign gave up fast (no oversleep past the budget): {$elapsed}"
      );
   }
);
