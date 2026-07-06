<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Exceptions\AuthenticationException;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: AUTH PLAIN over TLS (initial response) + rejection',
   test: function () {
      // @ Correct credentials over implicit TLS
      $trace = [];
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9997,
         'secure' => 'tls',
         'verify' => false,
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
         description: 'AUTH PLAIN with the initial response authenticates'
      );

      // @ Credential redaction in the wire trace
      $leaked = false;
      $redacted = false;
      foreach ($trace as $line) {
         if (str_contains($line, 'secret') || str_contains($line, base64_encode("\0user@example.com\0secret"))) {
            $leaked = true;
         }
         if ($line === '> AUTH PLAIN ****') {
            $redacted = true;
         }
      }
      yield assert(
         assertion: $leaked === false && $redacted === true,
         description: 'the trace redacts the AUTH blob (no credential bytes leak)'
      );

      $Client->disconnect();

      // @ Wrong password
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9997,
         'secure' => 'tls',
         'verify' => false,
         'username' => 'user@example.com',
         'password' => 'wrong',
         'timeout' => 5.0,
         'wait' => 5.0
      ]));

      $caught = null;
      try {
         $Client->connect();
      }
      catch (AuthenticationException $Exception) {
         $caught = $Exception;
      }

      yield assert(
         assertion: $caught instanceof AuthenticationException && $caught->getCode() === 535,
         description: 'wrong credentials throw AuthenticationException with the 535 code'
      );
      yield assert(
         assertion: $Client->connected === false && $Client->authenticated === false,
         description: 'the rejected session is torn down'
      );
   }
);
