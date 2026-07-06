<?php

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Message::render(): full MIME tree (related/mixed nesting, cid, base64)',
   test: function () {
      $fixtures = __DIR__ . '/fixtures';
      $png = file_get_contents("{$fixtures}/bootgly.png");

      $build = function (): Message {
         $Message = new Message();
         $Message->from = 'no-reply@example.com';
         $Message->id = 'i@example.com';
         $Message->date = 'D';
         $Message->boundary = 'seed';

         return $Message;
      };

      // @ Embeds only → related wrapping the base node
      $Message = $build();
      $Message->html = '<img src="' . $Message->embed(new File("{$fixtures}/bootgly.png"), cid: 'logo-cid') . '">';
      $raw = $Message->render();

      yield assert(
         assertion: str_contains($raw, 'Content-Type: multipart/related; boundary="=_seed.2"')
            && str_contains($raw, 'multipart/mixed') === false,
         description: 'embeds without attachments produce related (no mixed level)'
      );
      yield assert(
         assertion: str_contains($raw, 'Content-ID: <logo-cid>')
            && str_contains($raw, 'Content-Disposition: inline; filename="bootgly.png"')
            && str_contains($raw, 'Content-Type: image/png; name="bootgly.png"'),
         description: 'the inline part carries Content-ID, inline disposition and the detected type'
      );
      yield assert(
         assertion: str_contains($raw, 'src="cid:logo-cid"'),
         description: 'the html body references the embed via the returned cid: URI'
      );

      // @ Attachments only → mixed (no related level)
      $Message = $build();
      $Message->text = 'See attachment.';
      $Message->attach('raw-data', name: 'data.bin');
      $raw = $Message->render();

      yield assert(
         assertion: str_contains($raw, 'Content-Type: multipart/mixed; boundary="=_seed.1"')
            && str_contains($raw, 'multipart/related') === false,
         description: 'attachments without embeds produce mixed (no related level)'
      );
      yield assert(
         assertion: str_contains($raw, 'Content-Disposition: attachment; filename="data.bin"')
            && str_contains($raw, 'Content-ID') === false,
         description: 'a regular attachment has no Content-ID'
      );

      // @ Full tree → mixed { related { alternative, embed }, attachment }
      $Message = $build();
      $Message->text = 'Plain.';
      $Message->html = '<img src="' . $Message->embed(new File("{$fixtures}/bootgly.png"), cid: 'pix') . '">';
      $Message->attach(new File("{$fixtures}/note.txt"));
      $raw = $Message->render();

      $mixed = strpos($raw, 'multipart/mixed; boundary="=_seed.1"');
      $related = strpos($raw, 'multipart/related; boundary="=_seed.2"');
      $alternative = strpos($raw, 'multipart/alternative; boundary="=_seed.3"');
      yield assert(
         assertion: $mixed !== false && $related !== false && $alternative !== false
            && $mixed < $related && $related < $alternative,
         description: 'the full tree nests mixed > related > alternative'
      );
      yield assert(
         assertion: substr_count($raw, '--=_seed.1') === 3
            && substr_count($raw, '--=_seed.2') === 3
            && substr_count($raw, '--=_seed.3') === 3,
         description: 'each level has two opening boundaries plus its closing one'
      );

      // # The base64 payload of the embed decodes back to the fixture bytes
      $expected = chunk_split(base64_encode($png), 76, "\r\n");
      yield assert(
         assertion: str_contains($raw, $expected),
         description: 'the embedded image payload is the exact wrapped base64 of the fixture'
      );

      // # 7-bit safety of the whole message
      yield assert(
         assertion: preg_match('/[\x80-\xFF]/', $raw) !== 1,
         description: 'the rendered message is pure 7-bit ASCII'
      );
   }
);
