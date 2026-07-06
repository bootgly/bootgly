<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Exceptions\PermanentException;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: a payload over the advertised SIZE fails locally, before MAIL',
   test: function () {
      $trace = [];
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9998,
         'secure' => 'none',
         'domain' => 'size',   // the mock advertises SIZE 1024
         'timeout' => 5.0,
         'wait' => 5.0,
         'trace' => function (string $direction, string $line) use (&$trace): void {
            $trace[] = "{$direction} {$line}";
         }
      ]));

      $caught = null;
      try {
         $Client->send(
            'no-reply@example.com',
            'user@example.net',
            str_repeat('x', 2048)
         );
      }
      catch (PermanentException $Exception) {
         $caught = $Exception;
      }

      yield assert(
         assertion: $caught instanceof PermanentException && $caught->getCode() === 552,
         description: 'exceeding the advertised SIZE throws PermanentException with 552 semantics'
      );
      yield assert(
         assertion: $caught instanceof PermanentException && $caught->status === '5.3.4',
         description: 'the local pre-flight carries the 5.3.4 enhanced status'
      );

      // @ The refusal is local — MAIL never touched the wire
      $sent = false;
      foreach ($trace as $line) {
         if (str_starts_with($line, '> MAIL')) {
            $sent = true;
         }
      }
      yield assert(
         assertion: $sent === false,
         description: 'no MAIL command reached the wire (local pre-flight refusal)'
      );
      yield assert(
         assertion: $Client->connected === true,
         description: 'the session stays connected (no transaction was started)'
      );

      $Client->disconnect();
   }
);
