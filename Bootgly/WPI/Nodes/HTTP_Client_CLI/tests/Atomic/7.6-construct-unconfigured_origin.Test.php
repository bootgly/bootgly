<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Test(
   description: 'It should expose a null origin before configure() instead of throwing',
   test: function () {
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);

      // ! Reading an origin the caller never set must not be an Error: the
      //   encode and redirect paths fall back on it (HCLI-2)
      $host = null;
      $port = null;
      $Thrown = null;
      try {
         $host = $Client->host;
         $port = $Client->port;
      }
      catch (Throwable $Throwable) {
         $Thrown = $Throwable;
      }

      yield assert(
         assertion: $Thrown === null,
         description: 'Unconfigured origin is readable: '
            . ($Thrown === null ? 'no throw' : $Thrown->getMessage())
      );

      yield assert(
         assertion: $host === null && $port === null,
         description: 'Unconfigured origin is null: '
            . var_export($host, true) . ':' . var_export($port, true)
      );
   }
);
