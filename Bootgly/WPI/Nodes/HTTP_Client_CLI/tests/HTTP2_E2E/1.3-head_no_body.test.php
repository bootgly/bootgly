<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Specification(
   description: 'It should complete a HEAD request over HTTP/2 without a response body',
   test: new Assertions(Case: function (): Generator {
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Client->configure('127.0.0.1', 8087, enableHTTP2: true);

      $Response = $Client->request(method: 'HEAD', URI: '/echo');

      yield new Assertion(description: "status code is 200: {$Response->code}")
         ->expect($Response->code)
         ->to->be(200)
         ->assert();

      yield new Assertion(description: "HEAD response carries no body: '{$Response->Body->raw}'")
         ->expect($Response->Body->raw)
         ->to->be('')
         ->assert();
   })
);
