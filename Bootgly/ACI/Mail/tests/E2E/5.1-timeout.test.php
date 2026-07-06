<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Exceptions\ConnectionException;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: a silent server trips the per-reply timeout',
   test: function () {
      // ! Port 9994 greets then never answers EHLO
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9994,
         'secure' => 'none',
         'timeout' => 5.0,
         'wait' => 0.5
      ]));

      $start = microtime(true);
      $caught = null;
      try {
         $Client->connect();
      }
      catch (ConnectionException $Exception) {
         $caught = $Exception;
      }
      $elapsed = microtime(true) - $start;

      yield assert(
         assertion: $caught instanceof ConnectionException,
         description: 'a silent server throws ConnectionException'
      );
      yield assert(
         assertion: $caught instanceof ConnectionException
            && str_contains($caught->getMessage(), 'timed out'),
         description: 'the exception names the timeout'
      );
      yield assert(
         assertion: $elapsed < 2.0,
         description: 'the wait deadline is honored (no indefinite block)'
      );
      yield assert(
         assertion: $Client->connected === false,
         description: 'the timed-out session is torn down'
      );
   }
);
