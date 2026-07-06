<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'SMTP_Client::send() union: Message unpacking guards (local, no socket)',
   test: function () {
      // ! Nothing listens on this port — every assertion below must fire
      //   BEFORE any socket work
      $Client = new SMTP_Client(new Config(['host' => '127.0.0.1', 'port' => 9899]));

      $Message = new Message();
      $Message->from = 'no-reply@example.com';
      $Message->to = 'user@example.net';

      // @ Message + explicit recipients is ambiguous
      $caught = false;
      try {
         $Client->send($Message, ['extra@example.net']);
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'a Message plus explicit recipients throws locally'
      );

      // @ Message + explicit data is ambiguous
      $caught = false;
      try {
         $Client->send($Message, [], 'raw data');
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'a Message plus explicit data throws locally'
      );

      // @ A Message without `from` fails at render, before any socket work
      $caught = false;
      try {
         $Client->send(new Message());
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'a Message without `from` throws locally'
      );

      // @ Envelope hooks feeding the union
      $Message = new Message();
      $Message->from = 'Bootgly <no-reply@example.com>';
      $Message->to = ['user@example.net', 'Ana <ana@example.net>'];
      $Message->cc = 'copy@example.net';
      $Message->bcc = ['user@example.net', 'hidden@example.net'];

      yield assert(
         assertion: $Message->sender === 'no-reply@example.com',
         description: '$sender strips the display name'
      );
      yield assert(
         assertion: $Message->recipients === [
            'user@example.net', 'ana@example.net', 'copy@example.net', 'hidden@example.net'
         ],
         description: '$recipients merges to→cc→bcc in order, deduplicated across fields'
      );
   }
);
