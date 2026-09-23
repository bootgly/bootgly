<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


// ? M2 — the 16 MiB default makes the HTTP/2 response cap reachable without any setting: a
//   stream past maxResponseBytes must fail as 'Response Too Large' — deterministic, never
//   retried — not as the RST_STREAM code the client sent ('HTTP/2 Stream Error: Cancel').
return new Test(
   description: 'It should fail an HTTP/2 stream past maxResponseBytes as Response Too Large, without retrying it',
   test: new Assertions(Case: function (): Generator {
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: 8087, enableHTTP2: true));
      $Client->maxResponseBytes = 65536;
      $Client->maxRetries = 1;
      $Client->retryDelay = 1.0;
      $Client->retryJitter = 0;

      $started = microtime(true);
      $Response = $Client->request(method: 'GET', URI: '/large');
      $elapsed = microtime(true) - $started;

      yield new Assertion(description: "the stream fails as Response Too Large: {$Response->code} {$Response->status}")
         ->expect([$Response->code, $Response->status])
         ->to->be([0, 'Response Too Large'])
         ->assert();

      yield new Assertion(description: sprintf('no retry backoff was spent (%.2fs < 0.9s)', $elapsed))
         ->expect($elapsed < 0.9)
         ->to->be(true)
         ->assert();
   })
);
