<?php

use Bootgly\ACI\Mail;
use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Mail\SMTP_Client\Encoder;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: full MIME Message through the facade (wire sha1 proof)',
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

      // ! A full tree: alternative + inline image + attachment, non-ASCII
      //   subject, duplicate recipient across to/bcc — all deterministic
      $png = base64_decode(
         'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
      );

      $Message = new Message();
      $Message->from = 'Bootgly <no-reply@example.com>';
      $Message->to = ['user@example.net', 'Ana <ana@example.net>'];
      $Message->cc = 'copy@example.net';
      $Message->bcc = ['user@example.net', 'hidden@example.net'];
      $Message->subject = 'Relatório de teste 🎉';
      $Message->text = 'Plain body with ação.';
      $Message->id = 'e2e-message@example.com';
      $Message->date = 'Mon, 06 Jul 2026 20:00:00 +0000';
      $Message->boundary = 'e2eseed';

      $cid = $Message->embed($png, name: 'pixel.png', type: 'image/png', cid: 'e2e-pixel');
      $Message->html = "<p>Rich body</p><img src=\"{$cid}\">";
      $Message->attach('attachment-bytes', name: 'report.bin');

      // @ Single-argument union form through the facade
      $Receipt = $Mail->send($Message);

      // ! The wire proof: what the server received must be byte-identical
      //   to render()+stuff() computed locally
      $expected = new Encoder()->stuff($Message->render());

      yield assert(
         assertion: $Receipt->code === 250
            && str_contains($Receipt->reply, 'sha1=' . sha1($expected)),
         description: 'the wire bytes match the rendered MIME message (mock sha1 proof)'
      );
      yield assert(
         assertion: $Receipt->size === strlen($expected),
         description: 'the Receipt size matches the transmitted payload'
      );
      yield assert(
         assertion: $Receipt->recipients === [
            'user@example.net', 'ana@example.net', 'copy@example.net', 'hidden@example.net'
         ],
         description: 'the envelope carried to+cc+bcc deduplicated (bcc delivered)'
      );

      // @ Bcc is envelope-only
      $raw = $Message->render();
      yield assert(
         assertion: str_contains($raw, 'Bcc') === false
            && str_contains($raw, 'hidden@example.net') === false,
         description: 'the rendered message leaks no bcc header or address'
      );

      // @ Structure sanity on the wire payload
      yield assert(
         assertion: substr_count($raw, '--=_e2eseed.1') === 3
            && substr_count($raw, '--=_e2eseed.2') === 3
            && substr_count($raw, '--=_e2eseed.3') === 3
            && str_contains($raw, 'Content-ID: <e2e-pixel>')
            && preg_match('/[\x80-\xFF]/', $raw) !== 1,
         description: 'full mixed>related>alternative tree, inline cid present, 7-bit safe'
      );

      $Mail->disconnect();
   }
);
