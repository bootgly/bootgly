<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: AUTH LOGIN challenge dance (334/334 → 235)',
   test: function () {
      // ! Scenario `auth-login` advertises only AUTH LOGIN — proves the
      //   mechanism fallback (PLAIN unavailable) and the challenge dance
      $trace = [];
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9998,
         'secure' => 'none',
         'insecure' => true,
         'domain' => 'auth-login',
         'username' => 'user@example.com',
         'password' => 'secret',
         'timeout' => 5.0,
         'wait' => 5.0,
         'trace' => function (string $direction, string $line) use (&$trace): void {
            $trace[] = "{$direction} {$line}";
         }
      ]));

      yield assert(
         assertion: $Client->connect() === true && $Client->authenticated === true,
         description: 'AUTH LOGIN authenticates through the 334 challenges'
      );

      // @ Both challenge answers are redacted in the trace
      $leaked = false;
      foreach ($trace as $line) {
         if (
            str_contains($line, base64_encode('user@example.com'))
            || str_contains($line, base64_encode('secret'))
         ) {
            $leaked = true;
         }
      }
      yield assert(
         assertion: $leaked === false,
         description: 'the trace redacts both base64 challenge answers'
      );

      $Client->disconnect();
   }
);
