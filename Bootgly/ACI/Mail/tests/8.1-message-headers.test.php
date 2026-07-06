<?php

use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Message::render(): header block, order, Bcc exclusion, guards',
   test: function () {
      // @ Deterministic text-only message
      $Message = new Message();
      $Message->from = 'Bootgly <no-reply@example.com>';
      $Message->reply = 'support@example.com';
      $Message->to = ['user@example.net', 'Ana <ana@example.net>'];
      $Message->cc = 'copy@example.net';
      $Message->bcc = 'hidden@example.net';
      $Message->subject = 'Hello';
      $Message->text = 'Body line.';
      $Message->id = 'fixed-token@example.com';
      $Message->date = 'Mon, 06 Jul 2026 20:00:00 +0000';
      $Message->headers = ['X-Mailer' => 'Bootgly'];

      $raw = $Message->render();
      [$head] = explode("\r\n\r\n", $raw, 2);
      $lines = explode("\r\n", $head);

      yield assert(
         assertion: $lines === [
            'Date: Mon, 06 Jul 2026 20:00:00 +0000',
            'From: Bootgly <no-reply@example.com>',
            'Reply-To: support@example.com',
            'To: user@example.net, Ana <ana@example.net>',
            'Cc: copy@example.net',
            'Subject: Hello',
            'Message-ID: <fixed-token@example.com>',
            'X-Mailer: Bootgly',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 7bit'
         ],
         description: 'the header block is byte-exact and in canonical order'
      );
      yield assert(
         assertion: str_contains($raw, 'Bcc') === false
            && str_contains($raw, 'hidden@example.net') === false,
         description: 'bcc never appears in the rendered output'
      );

      // @ Lazy defaults are generated AND persisted (idempotent render)
      $Message = new Message();
      $Message->from = 'no-reply@example.com';
      $Message->text = 'x';
      $first = $Message->render();

      yield assert(
         assertion: $Message->id !== '' && $Message->date !== '' && $Message->boundary !== '',
         description: 'id/date/boundary are persisted after the first render'
      );
      yield assert(
         assertion: preg_match('/^[0-9a-f]{32}@example\.com$/', $Message->id) === 1,
         description: 'the generated Message-ID uses the from domain'
      );
      yield assert(
         assertion: $Message->render() === $first,
         description: 'a second render is byte-identical (idempotence)'
      );

      // @ Non-ASCII subject → encoded-word
      $Message = new Message();
      $Message->from = 'no-reply@example.com';
      $Message->subject = 'Olá';
      $Message->id = 'i@example.com';
      $Message->date = 'D';
      yield assert(
         assertion: str_contains($Message->render(), 'Subject: =?UTF-8?B?T2zDoQ==?='),
         description: 'a non-ASCII subject is RFC 2047 encoded'
      );

      // @ Long To list folds
      $Message = new Message();
      $Message->from = 'no-reply@example.com';
      $Message->to = array_map(
         fn (int $i): string => "recipient-{$i}@example-domain.net",
         range(1, 8)
      );
      $Message->id = 'i@example.com';
      $Message->date = 'D';
      $folded = true;
      [$head] = explode("\r\n\r\n", $Message->render(), 2);
      foreach (explode("\r\n", $head) as $line) {
         if (strlen($line) > 78) {
            $folded = false;
         }
      }
      yield assert(
         assertion: $folded,
         description: 'long address headers are folded ≤ 78 chars'
      );

      // @ Guards
      $caught = false;
      try {
         new Message()->render();
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'render() without `from` throws'
      );

      foreach ([
         ['headers', ['Bcc' => 'evil@x.com'], 'a reserved custom header name throws'],
         ['headers', ['Content-Type' => 'text/evil'], 'Content-Type as a custom header throws'],
         ['headers', ["X-Bad Name" => 'v'], 'a header name with a space throws'],
         ['subject', "evil\r\nBcc: victim@x.com", 'CR/LF injection through the subject throws'],
      ] as [$property, $value, $label]) {
         $Message = new Message();
         $Message->from = 'no-reply@example.com';
         $Message->id = 'i@example.com';
         $Message->date = 'D';
         $Message->{$property} = $value;

         $caught = false;
         try {
            $Message->render();
         }
         catch (InvalidArgumentException) {
            $caught = true;
         }
         yield assert(
            assertion: $caught,
            description: $label
         );
      }
   }
);
