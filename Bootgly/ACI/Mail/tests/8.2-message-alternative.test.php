<?php

use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Message::render(): body collapse (text/html/alternative) and CTE selection',
   test: function () {
      $build = function (): Message {
         $Message = new Message();
         $Message->from = 'no-reply@example.com';
         $Message->id = 'i@example.com';
         $Message->date = 'D';
         $Message->boundary = 'seed';

         return $Message;
      };

      // @ text-only → bare text/plain
      $Message = $build();
      $Message->text = 'Just text.';
      $raw = $Message->render();
      yield assert(
         assertion: str_contains($raw, 'Content-Type: text/plain; charset=UTF-8')
            && str_contains($raw, 'multipart') === false
            && str_ends_with($raw, "Just text.\r\n"),
         description: 'text-only collapses to a bare text/plain message'
      );

      // @ html-only → bare text/html
      $Message = $build();
      $Message->html = '<p>Only HTML.</p>';
      $raw = $Message->render();
      yield assert(
         assertion: str_contains($raw, 'Content-Type: text/html; charset=UTF-8')
            && str_contains($raw, 'multipart') === false,
         description: 'html-only collapses to a bare text/html message'
      );

      // @ both → multipart/alternative, plain first
      $Message = $build();
      $Message->text = 'Plain version.';
      $Message->html = '<p>Rich version.</p>';
      $raw = $Message->render();

      yield assert(
         assertion: str_contains($raw, 'Content-Type: multipart/alternative; boundary="=_seed.3"'),
         description: 'both bodies produce multipart/alternative with the seeded boundary'
      );
      $plain = strpos($raw, 'Content-Type: text/plain');
      $html = strpos($raw, 'Content-Type: text/html');
      yield assert(
         assertion: $plain !== false && $html !== false && $plain < $html,
         description: 'the plain part comes before the html part (increasing fidelity)'
      );
      yield assert(
         assertion: substr_count($raw, '--=_seed.3') === 3
            && str_contains($raw, "--=_seed.3--\r\n"),
         description: 'two opening boundaries plus the closing one'
      );

      // @ CTE selection
      $Message = $build();
      $Message->text = 'pure ascii body';
      $raw = $Message->render();
      yield assert(
         assertion: str_contains($raw, 'Content-Transfer-Encoding: 7bit')
            && str_contains($raw, "pure ascii body\r\n"),
         description: 'an ASCII body ships verbatim as 7bit'
      );

      $Message = $build();
      $Message->text = 'ação atômica';
      $raw = $Message->render();
      [, $payload] = explode("\r\n\r\n", $raw, 2);
      yield assert(
         assertion: str_contains($raw, 'Content-Transfer-Encoding: quoted-printable')
            && preg_match('/[\x80-\xFF]/', $raw) !== 1
            && str_contains(quoted_printable_decode($payload), 'ação atômica'),
         description: 'a UTF-8 body becomes quoted-printable and the output is 7-bit safe'
      );

      // @ Mixed EOLs in the body normalize
      $Message = $build();
      $Message->text = "line1\nline2\rline3";
      $raw = $Message->render();
      yield assert(
         assertion: str_ends_with($raw, "line1\r\nline2\r\nline3\r\n"),
         description: 'body EOLs are normalized to CRLF'
      );
   }
);
