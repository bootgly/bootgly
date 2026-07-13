<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Specification(
   description: 'It should follow a same-origin 302 redirect as a NEW stream on the same HTTP/2 Session',
   test: new Assertions(Case: function (): Generator {
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Client->configure('127.0.0.1', 8087, enableHTTP2: true);

      $Response = $Client->request(method: 'GET', URI: '/redirect');

      yield new Assertion(description: "final status code is 200: {$Response->code}")
         ->expect($Response->code)
         ->to->be(200)
         ->assert();

      yield new Assertion(description: "the redirect landed: {$Response->Body->raw}")
         ->expect($Response->Body->raw)
         ->to->be('landed')
         ->assert();

      yield new Assertion(description: "still HTTP/2 after the redirect: {$Response->protocol}")
         ->expect($Response->protocol)
         ->to->be('HTTP/2')
         ->assert();
   })
);
