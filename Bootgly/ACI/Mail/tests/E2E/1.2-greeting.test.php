<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Exceptions\TransientException;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: greeting handling (multiline 220, 421 busy)',
   test: function () {
      // @ Multiline 220 greeting
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9996,
         'secure' => 'none',
         'timeout' => 5.0,
         'wait' => 5.0
      ]));

      yield assert(
         assertion: $Client->connect() === true && $Client->connected === true,
         description: 'a multiline 220 greeting is accepted'
      );

      $Client->disconnect();
      yield assert(
         assertion: $Client->connected === false,
         description: 'disconnect() closes the session'
      );

      // @ 421 busy greeting
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9995,
         'secure' => 'none',
         'timeout' => 5.0,
         'wait' => 5.0
      ]));

      $caught = null;
      try {
         $Client->connect();
      }
      catch (TransientException $Exception) {
         $caught = $Exception;
      }

      yield assert(
         assertion: $caught instanceof TransientException && $caught->getCode() === 421,
         description: 'a 421 greeting throws TransientException carrying the reply code'
      );
      yield assert(
         assertion: $caught instanceof TransientException && $caught->status === '4.3.2',
         description: 'the enhanced status is extracted from the 421 greeting'
      );
      yield assert(
         assertion: $Client->connected === false,
         description: 'the failed session is torn down'
      );
   }
);
