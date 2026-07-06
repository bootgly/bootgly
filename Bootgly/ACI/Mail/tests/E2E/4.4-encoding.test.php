<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Exceptions\PermanentException;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: 8-bit payloads and UTF-8 headers fail closed without 8BITMIME/SMTPUTF8',
   test: function () {
      $connect = function (string $scenario, array &$trace): SMTP_Client {
         return new SMTP_Client(new Config([
            'host' => '127.0.0.1',
            'port' => 9998,
            'secure' => 'none',
            'domain' => $scenario,
            'timeout' => 5.0,
            'wait' => 5.0,
            'trace' => function (string $direction, string $line) use (&$trace): void {
               $trace[] = "{$direction} {$line}";
            }
         ]));
      };
      $mailed = function (array $trace): bool {
         foreach ($trace as $line) {
            if (str_starts_with($line, '> MAIL')) {
               return true;
            }
         }

         return false;
      };

      // @ 8-bit body against a server without 8BITMIME (the `no-starttls`
      //   scenario advertises HELP only) — refused locally, before MAIL
      $trace = [];
      $Client = $connect('no-starttls', $trace);

      $caught = null;
      try {
         $Client->send(
            'no-reply@example.com',
            'user@example.net',
            "Subject: hi\r\n\r\nCaf\xC3\xA9 is 8-bit.\r\n"
         );
      }
      catch (PermanentException $Exception) {
         $caught = $Exception;
      }

      yield assert(
         assertion: $caught instanceof PermanentException
            && $caught->getCode() === 554 && $caught->status === '5.6.1',
         description: '8-bit body without 8BITMIME throws PermanentException with 554/5.6.1 semantics'
      );
      yield assert(
         assertion: $mailed($trace) === false,
         description: 'no MAIL command reached the wire (local pre-flight refusal)'
      );
      $Client->disconnect();

      // @ UTF-8 header field against a server without SMTPUTF8 (the default
      //   scenario advertises 8BITMIME but not SMTPUTF8)
      $trace = [];
      $Client = $connect('happy', $trace);

      $caught = null;
      try {
         $Client->send(
            'no-reply@example.com',
            'user@example.net',
            "Subject: Ol\xC3\xA1\r\n\r\nplain ascii body.\r\n"
         );
      }
      catch (PermanentException $Exception) {
         $caught = $Exception;
      }

      yield assert(
         assertion: $caught instanceof PermanentException
            && $caught->getCode() === 553 && $caught->status === '5.6.7',
         description: 'UTF-8 header without SMTPUTF8 throws PermanentException with 553/5.6.7 semantics'
      );
      yield assert(
         assertion: $mailed($trace) === false,
         description: 'no MAIL command reached the wire (header pre-flight refusal)'
      );
      $Client->disconnect();

      // @ The same internationalized message goes through when SMTPUTF8 is
      //   advertised (the `utf8` scenario) — with the negotiated parameters
      $trace = [];
      $Client = $connect('utf8', $trace);

      $Receipt = $Client->send(
         'no-reply@example.com',
         'user@example.net',
         "Subject: Ol\xC3\xA1\r\n\r\nCaf\xC3\xA9 body.\r\n"
      );

      $mail = '';
      foreach ($trace as $line) {
         if (str_starts_with($line, '> MAIL')) {
            $mail = $line;
         }
      }
      yield assert(
         assertion: $Receipt->code === 250,
         description: 'the internationalized message is accepted when SMTPUTF8 is advertised'
      );
      yield assert(
         assertion: str_contains($mail, 'SMTPUTF8') && str_contains($mail, 'BODY=8BITMIME'),
         description: 'MAIL FROM carries the SMTPUTF8 and BODY=8BITMIME parameters'
      );

      $Client->disconnect();
   }
);
