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
      assert(
         assertion: $ASCII->length === 21,
         description: 'ASCII length: ' . $ASCII->length
      );

      // UTF-8 text
      $UTF8 = new __String('Bootgly é eficiente!');
      assert(
         assertion: $UTF8->length === 20,
         description: 'UTF8 length: ' . $UTF8->length
      );

      // Empty string
      $stringEmpty = new __String('');
      assert(
         assertion: $stringEmpty->length === 0,
         description: 'Empty string length: ' . $stringEmpty->length
      );

      // Special characters
      $stringSpecialChars = new __String('Hello   World!');
      assert(
         assertion: $stringSpecialChars->length === 14,
         description: 'String with special characters length: ' . $stringSpecialChars->length
      );

      // UTF-16 encoded string
      $stringUTF16 = new __String('Bootgly é eficiente!', 'UTF-16');
      assert(
         assertion: $stringUTF16->length === 11,
         description: 'UTF-16 encoded string length: ' . $stringUTF16->length
      );

      // Multibyte characters string
      $stringMultibyte = new __String('αβγδε', 'UTF-8');
      assert(
         assertion: $stringMultibyte->length === 5,
         description: 'Multibyte characters string length: ' . $stringMultibyte->length
      );
      // @ Invalid
      // ...

      return true;
   }
];
