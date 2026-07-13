<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Specification(
   description: 'It should negotiate HTTP/2 via TLS-ALPN by default (no explicit opt-in needed)',
   test: new Assertions(Case: function (): Generator {
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      // ! enableHTTP2 stays null: secure + default = offer 'h2,http/1.1' via ALPN
      $Client->configure('127.0.0.1', 8088, secure: [
         'verify_peer' => false,
         'verify_peer_name' => false,
         'allow_self_signed' => true,
      ]);

      $Response = $Client->request(method: 'GET', URI: '/alpn');

      yield new Assertion(description: "ALPN negotiated HTTP/2: {$Response->protocol}")
         ->expect($Response->protocol)
         ->to->be('HTTP/2')
         ->assert();

      yield new Assertion(description: "status code is 200: {$Response->code}")
         ->expect($Response->code)
         ->to->be(200)
         ->assert();

      yield new Assertion(description: "server saw an HTTP/2 exchange: {$Response->Body->raw}")
         ->expect($Response->Body->raw)
         ->to->be('method=GET;uri=/alpn;protocol=HTTP/2')
         ->assert();
   })
);
