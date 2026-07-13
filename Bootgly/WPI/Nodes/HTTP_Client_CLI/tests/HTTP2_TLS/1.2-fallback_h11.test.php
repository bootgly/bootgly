<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Specification(
   description: 'It should stay on HTTP/1.1 over TLS when the client disables HTTP/2 (no ALPN h2 offer)',
   test: new Assertions(Case: function (): Generator {
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      // ! enableHTTP2 false: the client never offers 'h2' — the h2-capable
      //   server must transparently serve the h1 path
      $Client->configure('127.0.0.1', 8088, secure: [
         'verify_peer' => false,
         'verify_peer_name' => false,
         'allow_self_signed' => true,
      ], enableHTTP2: false);

      $Response = $Client->request(method: 'GET', URI: '/fallback');

      yield new Assertion(description: "the exchange stayed on HTTP/1.1: {$Response->protocol}")
         ->expect($Response->protocol)
         ->to->be('HTTP/1.1')
         ->assert();

      yield new Assertion(description: "status code is 200: {$Response->code}")
         ->expect($Response->code)
         ->to->be(200)
         ->assert();

      yield new Assertion(description: "server served the h1 path: {$Response->Body->raw}")
         ->expect($Response->Body->raw)
         ->to->be('method=GET;uri=/fallback;protocol=HTTP/1.1')
         ->assert();
   })
);
