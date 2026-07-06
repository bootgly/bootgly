<?php

use Bootgly\ACI\Mail\Message\Encoder;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Message\Encoder: quoted-printable, wrapped base64 and header folding',
   test: function () {
      $Encoder = new Encoder();

      // @ quote() — quoted-printable
      yield assert(
         assertion: $Encoder->quote('plain ascii') === 'plain ascii',
         description: 'quote(): plain ASCII stays readable'
      );
      yield assert(
         assertion: $Encoder->quote('a=b') === 'a=3Db',
         description: 'quote(): the equals sign is escaped'
      );
      yield assert(
         assertion: $Encoder->quote('ação') === 'a=C3=A7=C3=A3o',
         description: 'quote(): UTF-8 bytes are hex-escaped'
      );
      yield assert(
         assertion: $Encoder->quote("line1\nline2") === "line1\r\nline2",
         description: 'quote(): lone LF is normalized to CRLF, never encoded as =0A'
      );

      $quoted = $Encoder->quote(str_repeat('é', 100));
      $sound = true;
      foreach (explode("\r\n", $quoted) as $line) {
         if (strlen($line) > 76) {
            $sound = false;
         }
      }
      yield assert(
         assertion: $sound && quoted_printable_decode($quoted) === str_repeat('é', 100),
         description: 'quote(): soft breaks keep lines ≤ 76 and the payload decodes back exactly'
      );

      // @ wrap() — base64 76/CRLF
      $bytes = random_bytes(300);
      $wrapped = $Encoder->wrap($bytes);
      yield assert(
         assertion: $wrapped === chunk_split(base64_encode($bytes), 76, "\r\n"),
         description: 'wrap() equals the canonical chunk_split(base64, 76, CRLF)'
      );
      $lines = explode("\r\n", rtrim($wrapped, "\r\n"));
      $sound = true;
      foreach ($lines as $index => $line) {
         if (strlen($line) > 76 || ($index < count($lines) - 1 && strlen($line) !== 76)) {
            $sound = false;
         }
      }
      yield assert(
         assertion: $sound && str_ends_with($wrapped, "\r\n"),
         description: 'wrap(): every line is 76 chars (last may be shorter), trailing CRLF present'
      );
      yield assert(
         assertion: base64_decode(str_replace("\r\n", '', $wrapped), true) === $bytes,
         description: 'wrap(): payload decodes back to the exact bytes'
      );

      // @ fold() — header folding at 78
      $short = 'Subject: short line';
      yield assert(
         assertion: $Encoder->fold($short) === $short,
         description: 'fold(): a short line is untouched'
      );

      $names = 'To: ' . implode(', ', array_map(
         fn (int $i): string => "user{$i}@example.com",
         range(1, 10)
      ));
      $folded = $Encoder->fold($names);
      $sound = true;
      foreach (explode("\r\n", $folded) as $index => $line) {
         if (strlen($line) > 78) {
            $sound = false;
         }
         if ($index > 0 && ($line === '' || $line[0] !== ' ')) {
            $sound = false;   // continuation must start with WSP
         }
      }
      yield assert(
         assertion: $sound && str_replace("\r\n", '', $folded) === $names,
         description: 'fold(): long lines fold ≤ 78 with WSP continuations, unfolding restores the input'
      );

      $prefolded = "Subject: =?UTF-8?B?QQ==?=\r\n =?UTF-8?B?Qg==?=";
      yield assert(
         assertion: $Encoder->fold($prefolded) === $prefolded,
         description: 'fold(): already-folded input (encoded-words) is never re-broken'
      );

      $unfoldable = str_repeat('x', 100);
      yield assert(
         assertion: $Encoder->fold($unfoldable) === $unfoldable,
         description: 'fold(): a line without a foldable space stays long'
      );

      $token = str_repeat('x', 100);
      $header = "X-Token: {$token}";
      yield assert(
         assertion: $Encoder->fold($header) === "X-Token:\r\n {$token}",
         description: 'fold(): a long single-word value folds once at the label and the word stays whole'
      );
   }
);
