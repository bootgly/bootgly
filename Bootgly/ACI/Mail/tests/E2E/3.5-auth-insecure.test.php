<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Exceptions\AuthenticationException;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: plaintext AUTH is refused locally unless `insecure` opts in',
   test: function () {
      // @ Gate: credentials + unencrypted session + insecure=false (default)
      $trace = [];
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9998,
         'secure' => 'none',
         'username' => 'user@example.com',
         'password' => 'secret',
         'timeout' => 5.0,
         'wait' => 5.0,
         'trace' => function (string $direction, string $line) use (&$trace): void {
            $trace[] = "{$direction} {$line}";
         }
      ]));

      $caught = null;
      try {
         $Client->connect();
      }
      catch (AuthenticationException $Exception) {
         $caught = $Exception;
      }

      yield assert(
         assertion: $caught instanceof AuthenticationException,
         description: 'plaintext AUTH without opt-in throws AuthenticationException'
      );

      // @ The refusal is local — no AUTH command may have touched the wire
      $sent = false;
      foreach ($trace as $line) {
         if (str_starts_with($line, '> AUTH')) {
            $sent = true;
         }
      }
      yield assert(
         assertion: $sent === false,
         description: 'no credential bytes reached the wire (local pre-flight refusal)'
      );

      // @ Explicit opt-in proceeds
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9998,
         'secure' => 'none',
         'insecure' => true,
         'username' => 'user@example.com',
         'password' => 'secret',
         'timeout' => 5.0,
         'wait' => 5.0
      ]));

      yield assert(
         assertion: $Client->connect() === true && $Client->authenticated === true,
         description: 'with `insecure` explicitly true the AUTH proceeds and succeeds'
      );

      $Client->disconnect();
   }
);
