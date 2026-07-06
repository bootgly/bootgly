<?php

use Bootgly\ACI\Mail\SMTP_Client\Encoder;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'SMTP_Client\Encoder: command lines and injection guard',
   test: function () {
      $Encoder = new Encoder();

      // @ Command encoding
      yield assert(
         assertion: $Encoder->encode('MAIL', 'FROM:<a@b>') === "MAIL FROM:<a@b>\r\n",
         description: 'verb + argument encode to `VERB argument\r\n`'
      );
      yield assert(
         assertion: $Encoder->encode('DATA') === "DATA\r\n",
         description: 'bare verb encodes without a trailing space'
      );
      yield assert(
         assertion: $Encoder->encode('EHLO', 'client.example.com') === "EHLO client.example.com\r\n",
         description: 'EHLO encodes with the client name'
      );

      // @ Injection guard
      $caught = false;
      try {
         $Encoder->encode("MAIL\r\nRCPT", 'TO:<x@y>');
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'CRLF in the verb throws (command injection)'
      );

      $caught = false;
      try {
         $Encoder->encode('MAIL', "FROM:<a@b>\r\nRCPT TO:<evil@x>");
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'CRLF in the argument throws (command injection)'
      );

      $caught = false;
      try {
         $Encoder->encode('MAIL', "FROM:<a\0b>");
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'NUL in the argument throws'
      );

      $caught = false;
      try {
         $Encoder->encode('MAIL', "FROM:<a@b>\rX");
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'a lone CR in the argument throws'
      );
   }
);
