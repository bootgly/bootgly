<?php

use Bootgly\ACI\Mail\Exceptions\ProtocolException;
use Bootgly\ACI\Mail\SMTP_Client\Decoder;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'SMTP_Client\Decoder: malformed input throws ProtocolException',
   test: function () {
      // @ Non-numeric line
      $caught = false;
      try {
         new Decoder()->decode("garbage line\r\n");
      }
      catch (ProtocolException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'a line without a 3-digit code throws'
      );

      // @ Mixed codes in a multiline reply
      $caught = false;
      try {
         new Decoder()->decode("250-first\r\n354 second\r\n");
      }
      catch (ProtocolException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'mixed codes inside a multiline reply throw'
      );

      // @ Oversized line (terminated)
      $caught = false;
      try {
         new Decoder()->decode('250 ' . str_repeat('x', 5000) . "\r\n");
      }
      catch (ProtocolException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'a terminated line over the 4096-byte limit throws'
      );

      // @ Oversized line (unterminated — buffer growth guard)
      $caught = false;
      try {
         new Decoder()->decode(str_repeat('x', 5000));
      }
      catch (ProtocolException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'an unterminated line over the 4096-byte limit throws'
      );

      // @ Too many lines in one reply
      $caught = false;
      try {
         new Decoder()->decode(str_repeat("250-x\r\n", 129) . "250 end\r\n");
      }
      catch (ProtocolException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'a reply over the 128-line limit throws'
      );

      // @ 2-digit and 4-digit codes are malformed
      $caught = false;
      try {
         new Decoder()->decode("25 OK\r\n");
      }
      catch (ProtocolException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'a 2-digit code line throws'
      );
   }
);
