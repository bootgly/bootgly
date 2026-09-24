<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Test(
   description: 'It should follow an HTTP/2 redirect to another origin with the replayed body and without the caller credentials',
   test: new Assertions(Case: function (): Generator {
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: 8087, enableHTTP2: true));
      $Client->timeout = 3;

      // ! 127.0.0.2 is the same server, reached as another origin
      $Response = $Client->request('POST', '/cross-kept', ['Authorization' => 'Bearer SECRET'], 'card=4111');

      yield new Assertion(description: "the cross-origin 307 was followed with its body, not its credentials: {$Response->Body->raw}")
         ->expect([$Response->code, $Response->Body->raw])
         ->to->be([200, 'method=POST;authorization=absent;content-type=text/plain;body=card=4111'])
         ->assert();

      yield new Assertion(description: "the next origin was reached over HTTP/2 too: {$Response->protocol}")
         ->expect($Response->protocol)
         ->to->be('HTTP/2')
         ->assert();

      yield new Assertion(description: "the client is back on its own origin: {$Client->host}:{$Client->port}")
         ->expect([$Client->host, $Client->port])
         ->to->be(['127.0.0.1', 8087])
         ->assert();
   })
);
