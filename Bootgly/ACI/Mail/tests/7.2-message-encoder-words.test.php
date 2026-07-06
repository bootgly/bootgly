<?php

use Bootgly\ACI\Mail\Message\Address;
use Bootgly\ACI\Mail\Message\Encoder;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Message\Encoder: RFC 2047 encoded-words, address formatting, injection guard',
   test: function () {
      $Encoder = new Encoder();

      // @ encode()
      yield assert(
         assertion: $Encoder->encode('Plain ASCII subject') === 'Plain ASCII subject',
         description: 'pure printable ASCII passes through byte-identical'
      );
      yield assert(
         assertion: $Encoder->encode('Olá') === '=?UTF-8?B?T2zDoQ==?=',
         description: 'a short non-ASCII value becomes one exact encoded-word'
      );

      // # Long UTF-8 subject: chunking discipline
      $subject = str_repeat('ação é ', 20);
      $encoded = $Encoder->encode($subject);
      $words = explode("\r\n ", $encoded);

      $compliant = true;
      $decoded = '';
      foreach ($words as $word) {
         if (preg_match('/^=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=$/', $word, $matches) !== 1) {
            $compliant = false;
            break;
         }
         if (strlen($word) > 75) {
            $compliant = false;
            break;
         }
         $bytes = base64_decode($matches[1], true);
         if ($bytes === false || preg_match('//u', $bytes) !== 1) {
            $compliant = false;   // a chunk split a multibyte character
            break;
         }
         $decoded .= $bytes;
      }
      yield assert(
         assertion: $compliant && count($words) > 1,
         description: 'long input folds into multiple valid encoded-words ≤ 75 chars, never splitting a UTF-8 char'
      );
      yield assert(
         assertion: $decoded === $subject,
         description: 'decoding and reassembling the words restores the input exactly'
      );

      // @ format()
      yield assert(
         assertion: $Encoder->format(new Address('a@b.com')) === 'a@b.com',
         description: 'format(): bare email stays bare'
      );
      yield assert(
         assertion: $Encoder->format(new Address('Ana Silva <a@b.com>')) === 'Ana Silva <a@b.com>',
         description: 'format(): atom-safe name is kept plain'
      );
      yield assert(
         assertion: $Encoder->format(new Address('"Silva, Ana" <a@b.com>')) === '"Silva, Ana" <a@b.com>',
         description: 'format(): a name with specials becomes a quoted-string'
      );
      yield assert(
         assertion: $Encoder->format(new Address('"Q\" B\\\\S" <a@b.com>')) === '"Q\" B\\\\S" <a@b.com>',
         description: 'format(): quotes and backslashes are re-escaped in the quoted-string'
      );
      yield assert(
         assertion: $Encoder->format(new Address('José <a@b.com>')) === '=?UTF-8?B?Sm9zw6k=?= <a@b.com>',
         description: 'format(): a non-ASCII name becomes an encoded-word'
      );

      // @ check()
      yield assert(
         assertion: $Encoder->check('safe value') === 'safe value',
         description: 'check() returns a safe value unchanged'
      );
      foreach (["a\rb", "a\nb", "a\0b"] as $evil) {
         $caught = false;
         try {
            $Encoder->check($evil);
         }
         catch (InvalidArgumentException) {
            $caught = true;
         }
         yield assert(
            assertion: $caught,
            description: 'check() throws on CR/LF/NUL'
         );
      }
   }
);
