<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Exceptions\TransientException;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: a 450 recipient refusal is transient and aborts via RSET',
   test: function () {
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9998,
         'secure' => 'none',
         'domain' => 'transient',
         'timeout' => 5.0,
         'wait' => 5.0
      ]));

      $caught = null;
      try {
         $Client->send('no-reply@example.com', 'user@example.net', "Subject: x\r\n\r\nbody");
      }
      catch (TransientException $Exception) {
         $caught = $Exception;
      }

      yield assert(
         assertion: $caught instanceof TransientException && $caught->getCode() === 450,
         description: 'a 450 RCPT refusal throws TransientException carrying the code'
      );
      yield assert(
         assertion: $caught instanceof TransientException && $caught->status === '4.2.0',
         description: 'the enhanced status is carried for retry policies'
      );
      yield assert(
         assertion: $Client->connected === true,
         description: 'the session stays connected and reusable after the RSET abort'
      );

      $Client->disconnect();
   }
);
