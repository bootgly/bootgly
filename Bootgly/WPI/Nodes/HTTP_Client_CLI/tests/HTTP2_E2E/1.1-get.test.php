<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Specification(
   description: 'It should GET over HTTP/2 (h2c prior knowledge) against the real Bootgly server',
   test: new Assertions(Case: function (): Generator {
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Client->configure('127.0.0.1', 8087, enableHTTP2: true);

      $Response = $Client->request(method: 'GET', URI: '/echo');

      yield new Assertion(description: "response protocol is HTTP/2: {$Response->protocol}")
         ->expect($Response->protocol)
         ->to->be('HTTP/2')
         ->assert();

      yield new Assertion(description: "status code is 200: {$Response->code}")
         ->expect($Response->code)
         ->to->be(200)
         ->assert();

      yield new Assertion(description: "server echoed the request line: {$Response->Body->raw}")
         ->expect($Response->Body->raw)
         ->to->be('method=GET;uri=/echo;protocol=HTTP/2;body=')
         ->assert();
   })
);
