<?php

use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Mail\Message: export()/import() round-trip (queued Job payload shape)',
   test: function () {
      // ! A full message with every transferable feature
      $Message = new Message();
      $Message->from = 'Bootgly <no-reply@bootgly.com>';
      $Message->reply = 'support@bootgly.com';
      $Message->to = ['user@example.net', 'Ana <ana@example.net>'];
      $Message->cc = 'copy@example.net';
      $Message->bcc = 'hidden@example.net';
      $Message->subject = 'Relatório de exportação';
      $Message->text = 'Plain body.';
      $Message->html = '<p>HTML body.</p>';
      $Message->data = ['name' => 'Ana', 'count' => 2];
      $Message->id = 'transfer@bootgly.com';
      $Message->date = 'Mon, 06 Jul 2026 10:00:00 -0300';
      $Message->boundary = 'transferseed';
      $Message->headers = ['X-Tag' => 'transfer'];
      $Message->attach("\x00\x01\xFFbinary", 'data.bin', 'application/octet-stream');
      $cid = $Message->embed("\x89PNG fake pixel", 'pixel.png', 'image/png', 'pix-1');

      $payload = $Message->export();

      // @ The payload is scalars/arrays only — it must cross processes inside
      //   a serialized Job whose unserialize() allows no payload classes
      $scalar = true;
      $walk = function (array $values) use (&$walk, &$scalar): void {
         foreach ($values as $value) {
            if (is_array($value) === true) {
               $walk($value);
            }
            elseif ($value !== null && is_scalar($value) === false) {
               $scalar = false;
            }
         }
      };
      $walk($payload);
      yield assert(
         assertion: $scalar,
         description: 'export() emits scalars and arrays only (no objects)'
      );
      yield assert(
         assertion: unserialize(serialize($payload), ['allowed_classes' => false]) === $payload,
         description: 'the payload survives a class-less serialize round-trip'
      );

      // @ Import rebuilds an equivalent message
      $Imported = Message::import($payload);

      yield assert(
         assertion: $Imported->render() === $Message->render(),
         description: 'import(export()) renders byte-identical mail'
      );
      yield assert(
         assertion: $Imported->recipients === $Message->recipients,
         description: 'the derived envelope recipients survive the round-trip (bcc included)'
      );
      yield assert(
         assertion: $Imported->sender === 'no-reply@bootgly.com',
         description: 'the derived envelope sender survives the round-trip'
      );
      yield assert(
         assertion: $cid === 'cid:pix-1'
            && $Imported->Embeds[0]->cid === 'pix-1'
            && $Imported->Attachments[0]->contents === "\x00\x01\xFFbinary",
         description: 'attachments and embeds (binary contents + stable cid) survive the round-trip'
      );
      yield assert(
         assertion: $Imported->data === ['name' => 'Ana', 'count' => 2]
            && $Imported->headers === ['X-Tag' => 'transfer'],
         description: 'template data and custom headers survive the round-trip'
      );

      // @ Mutating the original after export never leaks into the import
      $Message->subject = 'mutated';
      yield assert(
         assertion: Message::import($payload)->subject === 'Relatório de exportação',
         description: 'the exported payload is a detached snapshot'
      );

      // @ Malformed payload values fall back to defaults (defence in depth)
      $Broken = Message::import([
         'from' => ['not-a-string'],
         'to' => [42, 'ok@example.net'],
         'headers' => ['X-Ok' => 'yes', 'X-Bad' => 42],
         'attachments' => [['name' => 'x.bin']],
         'unknown' => 'ignored'
      ]);
      yield assert(
         assertion: $Broken->from === ''
            && $Broken->to === ['ok@example.net']
            && $Broken->headers === ['X-Ok' => 'yes']
            && $Broken->Attachments === [],
         description: 'import() ignores malformed values and unknown keys'
      );
   }
);
