<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Exceptions\CryptoException;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: certificate verification is fail-closed by default',
   test: function () {
      // ! Default config: verify = true — the mock cert is self-signed
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9997,
         'secure' => 'tls',
         'timeout' => 5.0,
         'wait' => 5.0
      ]));

      $caught = null;
      try {
         $Client->connect();
      }
      catch (CryptoException $Exception) {
         $caught = $Exception;
      }

      yield assert(
         assertion: $caught instanceof CryptoException,
         description: 'default verification refuses a self-signed certificate'
      );
      yield assert(
         assertion: $Client->connected === false,
         description: 'no session survives a failed verification'
      );
   }
);
