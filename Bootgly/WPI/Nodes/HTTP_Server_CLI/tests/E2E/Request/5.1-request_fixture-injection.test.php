<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fixtures\Probe;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


$Probe = new Probe([
   'requestHost'    => null,
   'requestIndex'   => null,
   'requestFixture' => false,
]);

return new Specification(
   description: 'HTTP request closure should receive Fixture only after runner arguments',
   Fixture: $Probe,

   request: function (string $host, int $index, Probe $Probe): string {
      $Probe->State->update('requestHost', $host);
      $Probe->State->update('requestIndex', $index);
      $Probe->State->update('requestFixture', true);

      return "GET /fixture-injection HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'FIXTURE-INJECTED');
   },

   test: function (string $response, Probe $Probe): bool|string {
      if (! str_contains($response, 'FIXTURE-INJECTED')) {
         return 'Fixture injection response body not found.';
      }

      if (! is_string($Probe->fetch('requestHost')) || ! str_contains($Probe->fetch('requestHost'), ':')) {
         return 'Request closure did not receive host:port as the first argument.';
      }

      if (! is_int($Probe->fetch('requestIndex'))) {
         return 'Request closure did not receive index as the second argument.';
      }

      if ($Probe->fetch('requestFixture') !== true) {
         return 'Request closure did not receive the Probe fixture as the third argument.';
      }

      return true;
   }
);
