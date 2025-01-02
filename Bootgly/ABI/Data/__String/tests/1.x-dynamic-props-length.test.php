<?php

use Bootgly\ABI\Data\__String;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      // ASCII text
      $ASCII = new __String('Bootgly is efficient!');
      yield assert(
         assertion: $ASCII->length === 21,
         description: 'ASCII length: ' . $ASCII->length
      );

      // UTF-8 text
      $UTF8 = new __String('Bootgly é eficiente!');
      yield assert(
         assertion: $UTF8->length === 20,
         description: 'UTF8 length: ' . $UTF8->length
      );

      // Empty string
      $stringEmpty = new __String('');
      yield assert(
         assertion: $stringEmpty->length === 0,
         description: 'Empty string length: ' . $stringEmpty->length
      );

      // Special characters
      $stringSpecialChars = new __String('Hello   World!');
      yield assert(
         assertion: $stringSpecialChars->length === 14,
         description: 'String with special characters length: ' . $stringSpecialChars->length
      );

      // UTF-8 encoded string
      $stringUTF8 = new __String('Bootgly é eficiente!', 'UTF-8');
      yield assert(
         assertion: $stringUTF8->length === 20,
         description: 'UTF-8 encoded string length: ' . $stringUTF8->length
      );

      // Multibyte characters string
      $stringMultibyte = new __String('αβγδε', 'UTF-8');
      yield assert(
         assertion: $stringMultibyte->length === 5,
         description: 'Multibyte characters string length: ' . $stringMultibyte->length
      );
      // @ Invalid
      // ...
   }
];
