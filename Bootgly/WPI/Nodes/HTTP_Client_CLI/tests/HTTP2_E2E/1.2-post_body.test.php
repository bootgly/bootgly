<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Specification(
   description: 'It should POST a body over HTTP/2 and the server must receive it intact',
   test: new Assertions(Case: function (): Generator {
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Client->configure('127.0.0.1', 8087, enableHTTP2: true);

      $Response = $Client->request(method: 'POST', URI: '/submit', body: 'hello-h2');

      yield new Assertion(description: "response protocol is HTTP/2: {$Response->protocol}")
         ->expect($Response->protocol)
         ->to->be('HTTP/2')
         ->assert();

      yield new Assertion(description: "server received the request body: {$Response->Body->raw}")
         ->expect($Response->Body->raw)
         ->to->be('method=POST;uri=/submit;protocol=HTTP/2;body=hello-h2')
         ->assert();
   })
);
