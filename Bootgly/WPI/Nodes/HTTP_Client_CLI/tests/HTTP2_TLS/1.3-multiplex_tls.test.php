<?php

use function count;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Specification(
   description: 'It should multiplex batched requests over ONE ALPN-negotiated TLS connection',
   test: new Assertions(Case: function (): Generator {
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Client->configure('127.0.0.1', 8088, secure: [
         'verify_peer' => false,
         'verify_peer_name' => false,
         'allow_self_signed' => true,
      ]);

      $Client->batch();
      $Responses = [];
      for ($i = 0; $i < 6; $i++) {
         $Responses[$i] = $Client->request(method: 'GET', URI: "/tls/{$i}");
      }
      $Client->drain();

      $correct = 0;
      foreach ($Responses as $i => $Response) {
         if (
            $Response->code === 200
            && $Response->protocol === 'HTTP/2'
            && $Response->Body->raw === "method=GET;uri=/tls/{$i};protocol=HTTP/2"
         ) {
            $correct++;
         }
      }

      yield new Assertion(description: "all 6 multiplexed TLS responses are correct: {$correct}")
         ->expect($correct)
         ->to->be(6)
         ->assert();

      $sessions = count($Client->Sessions);
      yield new Assertion(description: "exactly ONE h2 Session over TLS carried them: {$sessions}")
         ->expect($sessions)
         ->to->be(1)
         ->assert();
   })
);
