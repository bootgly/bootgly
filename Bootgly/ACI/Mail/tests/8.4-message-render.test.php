<?php

use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Message::render(): byte-exact snapshot and idempotence',
   test: function () {
      $Message = new Message();
      $Message->from = 'no-reply@example.com';
      $Message->to = 'user@example.net';
      $Message->subject = 'Snapshot';
      $Message->text = 'Plain.';
      $Message->html = '<p>HTML</p>';
      $Message->id = 'i@example.com';
      $Message->date = 'D';
      $Message->boundary = 'seed';
      $Message->attach('DATA', name: 'a.bin');

      $expected = implode("\r\n", [
         'Date: D',
         'From: no-reply@example.com',
         'To: user@example.net',
         'Subject: Snapshot',
         'Message-ID: <i@example.com>',
         'MIME-Version: 1.0',
         'Content-Type: multipart/mixed; boundary="=_seed.1"',
         '',
         '--=_seed.1',
         'Content-Type: multipart/alternative; boundary="=_seed.3"',
         '',
         '--=_seed.3',
         'Content-Type: text/plain; charset=UTF-8',
         'Content-Transfer-Encoding: 7bit',
         '',
         'Plain.',
         '--=_seed.3',
         'Content-Type: text/html; charset=UTF-8',
         'Content-Transfer-Encoding: 7bit',
         '',
         '<p>HTML</p>',
         '--=_seed.3--',
         '--=_seed.1',
         'Content-Type: application/octet-stream; name="a.bin"',
         'Content-Transfer-Encoding: base64',
         'Content-Disposition: attachment; filename="a.bin"',
         '',
         'REFUQQ==',
         '--=_seed.1--',
         ''
      ]);

      $raw = $Message->render();

      yield assert(
         assertion: $raw === $expected,
         description: 'the rendered message matches the byte-exact snapshot'
      );
      yield assert(
         assertion: $Message->render() === $raw,
         description: 'rendering twice is byte-identical (idempotence)'
      );
   }
);
