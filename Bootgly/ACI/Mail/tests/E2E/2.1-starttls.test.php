<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: STARTTLS upgrade (advertise → 220 → handshake → re-EHLO)',
   test: function () {
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9998,
         'secure' => 'starttls',
         'verify' => false,
         'domain' => 'starttls',
         'timeout' => 5.0,
         'wait' => 5.0
      ]));

      yield assert(
         assertion: $Client->connect() === true,
         description: 'connect() completes the STARTTLS upgrade and the re-EHLO over TLS'
      );
      yield assert(
         assertion: $Client->encrypted === true,
         description: 'the session is encrypted after the upgrade'
      );
      yield assert(
         assertion: $Client->connect() === true && $Client->connected === true,
         description: 'connect() is idempotent while connected'
      );

      $Client->disconnect();
      yield assert(
         assertion: $Client->connected === false && $Client->encrypted === false,
         description: 'disconnect() resets the session state'
      );
   }
);
