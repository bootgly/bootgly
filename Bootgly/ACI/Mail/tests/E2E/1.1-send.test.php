<?php

use Bootgly\ACI\Mail;
use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\SMTP_Client\Encoder;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: happy-path send through the Mail facade (wire sha1 proof)',
   test: function () {
      $Mail = new Mail([
         'host' => '127.0.0.1',
         'port' => 9998,
         'secure' => 'none',
         'domain' => 'happy',
         'timeout' => 5.0,
         'wait' => 5.0,
         'drain' => 5.0
      ]);

      // ! Message with dot-lines and deliberately mixed EOLs
      $message = "From: no-reply@example.com\r\n"
         . "To: user@example.net\n"
         . "Subject: E2E happy path\r\n"
         . "\n"
         . ".leading dot line\r\n"
         . "normal line\n"
         . ".\r\n"
         . "last line without EOL";

      // @ send() connects lazily — no explicit connect() call
      $Receipt = $Mail->send(
         sender: 'no-reply@example.com',
         recipients: ['user@example.net', 'other@example.net'],
         data: $message
      );

      // ! Expected wire bytes, computed by the (unit-proven) pure Encoder
      $expected = new Encoder()->stuff($message);
      $hash = sha1($expected);

      yield assert(
         assertion: $Receipt->code === 250,
         description: 'the server accepts the message with a final 250'
      );
      yield assert(
         assertion: $Receipt->status === '2.0.0',
         description: 'the Receipt carries the enhanced status'
      );
      yield assert(
         assertion: str_contains($Receipt->reply, "sha1={$hash}"),
         description: 'the wire bytes match the dot-stuffed payload (mock sha1 proof)'
      );
      yield assert(
         assertion: $Receipt->recipients === ['user@example.net', 'other@example.net'],
         description: 'the Receipt lists the accepted envelope recipients'
      );
      yield assert(
         assertion: $Receipt->size === strlen($expected),
         description: 'the Receipt size matches the transmitted payload bytes'
      );

      // @ Session reuse — a second transaction on the same connection
      $Receipt = $Mail->send(
         sender: 'no-reply@example.com',
         recipients: 'user@example.net',
         data: "Subject: again\r\n\r\nsecond message"
      );
      yield assert(
         assertion: $Receipt->code === 250 && $Receipt->recipients === ['user@example.net'],
         description: 'the session is reusable for a second send (string recipient form)'
      );

      $Mail->disconnect();

      // @ Local envelope validation (nothing touches the wire)
      $caught = false;
      try {
         $Mail->send('no-reply@example.com', [], 'body');
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'an empty recipient list throws InvalidArgumentException'
      );

      $caught = false;
      try {
         $Mail->send('no-reply@example.com', ['bad <address>@x'], 'body');
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'an envelope address with spaces/brackets throws InvalidArgumentException'
      );
   }
);
