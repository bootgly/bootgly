<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Exceptions\PermanentException;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: a 550 recipient refusal is permanent (not retryable)',
   test: function () {
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9998,
         'secure' => 'none',
         'domain' => 'permanent',
         'timeout' => 5.0,
         'wait' => 5.0
      ]));

      $caught = null;
      try {
         $Client->send('no-reply@example.com', 'unknown@example.net', "Subject: x\r\n\r\nbody");
      }
      catch (PermanentException $Exception) {
         $caught = $Exception;
      }

      yield assert(
         assertion: $caught instanceof PermanentException && $caught->getCode() === 550,
         description: 'a 550 RCPT refusal throws PermanentException carrying the code'
      );
      yield assert(
         assertion: $caught instanceof PermanentException && $caught->status === '5.1.1',
         description: 'the enhanced status is carried in the exception'
      );
      yield assert(
         assertion: $Client->connected === true,
         description: 'the session survives (RSET) — only the transaction is dead'
      );

      $Client->disconnect();
   }
);
