<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Pretest fixture — a minimal valid E2E Specification
return new Specification(
   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function ($Request, $Response) {
      return $Response(body: 'probe');
   },
   test: function ($response) {
      return true;
   }
);
