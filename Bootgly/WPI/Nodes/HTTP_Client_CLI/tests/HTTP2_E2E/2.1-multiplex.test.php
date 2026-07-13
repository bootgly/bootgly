<?php

use function count;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Specification(
   description: 'It should multiplex 10 batched requests as streams over ONE HTTP/2 connection',
   test: new Assertions(Case: function (): Generator {
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Client->configure('127.0.0.1', 8087, enableHTTP2: true);

      // @ Batch 10 requests with distinct URIs
      $Client->batch();
      $Responses = [];
      for ($i = 0; $i < 10; $i++) {
         $Responses[$i] = $Client->request(method: 'GET', URI: "/mux/{$i}");
      }
      $Client->drain();

      $correct = 0;
      foreach ($Responses as $i => $Response) {
         if (
            $Response->code === 200
            && $Response->protocol === 'HTTP/2'
            && $Response->Body->raw === "method=GET;uri=/mux/{$i};protocol=HTTP/2;body="
         ) {
            $correct++;
         }
      }

      yield new Assertion(description: "all 10 multiplexed responses are correct: {$correct}")
         ->expect($correct)
         ->to->be(10)
         ->assert();

      $sessions = count($Client->Sessions);
      yield new Assertion(description: "exactly ONE h2 Session (single connection) carried them: {$sessions}")
         ->expect($sessions)
         ->to->be(1)
         ->assert();
   })
);
