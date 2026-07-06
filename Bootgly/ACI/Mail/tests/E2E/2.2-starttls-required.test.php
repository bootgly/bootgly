<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Exceptions\CryptoException;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: starttls mode hard-fails when the server lacks STARTTLS',
   test: function () {
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9998,
         'secure' => 'starttls',
         'verify' => false,
         'domain' => 'no-starttls',
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
         description: 'a server without STARTTLS throws CryptoException (no silent downgrade)'
      );
      yield assert(
         assertion: $Client->connected === false,
         description: 'the refused session is torn down'
      );
   }
);
