<?php

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Mail\Message\Attachment;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Message\Attachment + Message attach()/embed()',
   test: function () {
      $fixtures = __DIR__ . '/fixtures';

      // @ From raw bytes
      $Attachment = new Attachment('raw-bytes', name: 'data.bin');
      yield assert(
         assertion: $Attachment->contents === 'raw-bytes'
            && $Attachment->name === 'data.bin'
            && $Attachment->type === 'application/octet-stream'
            && $Attachment->disposition === Attachment::ATTACHMENT
            && $Attachment->cid === '',
         description: 'raw bytes + name build an octet-stream attachment'
      );

      $caught = false;
      try {
         new Attachment('raw-bytes');
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'raw bytes without a name throw'
      );

      // @ From File
      $Attachment = new Attachment(new File("{$fixtures}/bootgly.png"));
      yield assert(
         assertion: $Attachment->name === 'bootgly.png'
            && $Attachment->type === 'image/png'
            && $Attachment->contents !== '' && strlen($Attachment->contents) === 70,
         description: 'a File attachment detects name (basename) and MIME type'
      );

      $Attachment = new Attachment(new File("{$fixtures}/note.txt"), name: 'renamed.txt', type: 'text/plain');
      yield assert(
         assertion: $Attachment->name === 'renamed.txt' && $Attachment->type === 'text/plain',
         description: 'explicit name/type override detection'
      );

      $caught = false;
      try {
         new Attachment(new File("{$fixtures}/missing.bin"));
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'a missing file throws'
      );

      // @ Guards
      $caught = false;
      try {
         new Attachment('x', name: 'a.bin', disposition: 'sideways');
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'an unknown disposition throws'
      );

      $caught = false;
      try {
         new Attachment('x', name: "evil\r\n.bin");
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'CR/LF in the name throws (header injection)'
      );

      // @ Message->attach() / embed()
      $Message = new Message();
      $Chained = $Message->attach('bytes', name: 'a.bin')->attach('more', name: 'b.bin');
      yield assert(
         assertion: $Chained === $Message && count($Message->Attachments) === 2
            && $Message->Attachments[1]->name === 'b.bin',
         description: 'attach() returns self and appends to $Attachments'
      );

      $uri = $Message->embed('png-bytes', name: 'pixel.png', type: 'image/png', cid: 'fixed-cid');
      yield assert(
         assertion: $uri === 'cid:fixed-cid'
            && count($Message->Embeds) === 1
            && $Message->Embeds[0]->disposition === Attachment::INLINE
            && $Message->Embeds[0]->cid === 'fixed-cid',
         description: 'embed() returns the cid: URI and appends an INLINE part'
      );

      $uri = $Message->embed('png-bytes', name: 'pixel2.png', type: 'image/png');
      yield assert(
         assertion: preg_match('/^cid:[0-9a-f]{32}$/', $uri) === 1,
         description: 'embed() without a cid generates a 32-hex one'
      );

      // @ Envelope hooks (skeleton behavior)
      $Message = new Message();
      $Message->from = 'Bootgly <no-reply@example.com>';
      $Message->to = 'user@example.net';
      $Message->cc = ['Ana <ana@example.net>', 'user@example.net'];
      $Message->bcc = ['hidden@example.net'];
      yield assert(
         assertion: $Message->sender === 'no-reply@example.com',
         description: '$sender derives the bare email of `from`'
      );
      yield assert(
         assertion: $Message->recipients === ['user@example.net', 'ana@example.net', 'hidden@example.net'],
         description: '$recipients merges to+cc+bcc emails, deduplicated, in order'
      );
   }
);
