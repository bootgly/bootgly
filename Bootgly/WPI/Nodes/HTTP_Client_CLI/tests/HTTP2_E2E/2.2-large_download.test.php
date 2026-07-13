<?php

use function strlen;
use function str_repeat;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Specification(
   description: 'It should download a body far beyond the initial 65535 flow-control window (recv replenish)',
   test: new Assertions(Case: function (): Generator {
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Client->configure('127.0.0.1', 8087, enableHTTP2: true);

      $Response = $Client->request(method: 'GET', URI: '/large');

      yield new Assertion(description: "status code is 200: {$Response->code}")
         ->expect($Response->code)
         ->to->be(200)
         ->assert();

      $length = strlen($Response->Body->raw);
      yield new Assertion(description: "the full 200000-byte body arrived: {$length}")
         ->expect($length)
         ->to->be(200000)
         ->assert();

      yield new Assertion(description: 'the body content is byte-exact')
         ->expect($Response->Body->raw === str_repeat('L', 200000))
         ->to->be(true)
         ->assert();
   })
);
