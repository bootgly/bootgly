<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: implicit TLS (SMTPS) session',
   test: function () {
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9997,
         'secure' => 'tls',
         'verify' => false,
         'timeout' => 5.0,
         'wait' => 5.0
      ]));

      yield assert(
         assertion: $Client->connect() === true,
         description: 'connect() establishes the implicit TLS session'
      );
      yield assert(
         assertion: $Client->encrypted === true,
         description: 'the session is encrypted from the first byte'
      );

      $Client->disconnect();
      yield assert(
         assertion: $Client->connected === false,
         description: 'disconnect() closes the session'
      );
   }
);
