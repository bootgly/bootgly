<?php

use Bootgly\ABI\Code\__String;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: '',
   test: function () {
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
      $StringEmpty = new __String('');
      yield assert(
         assertion: $StringEmpty->length === 0,
         description: 'Empty string length: ' . $StringEmpty->length
      );

      // Special characters
      $StringSpecialChars = new __String('Hello   World!');
      yield assert(
         assertion: $StringSpecialChars->length === 14,
         description: 'String with special characters length: ' . $StringSpecialChars->length
      );

      // UTF-8 encoded string
      $StringUTF8 = new __String('Bootgly é eficiente!', 'UTF-8');
      yield assert(
         assertion: $StringUTF8->length === 20,
         description: 'UTF-8 encoded string length: ' . $StringUTF8->length
      );

      // Multibyte characters string
      $StringMultibyte = new __String('αβγδε', 'UTF-8');
      yield assert(
         assertion: $StringMultibyte->length === 5,
         description: 'Multibyte characters string length: ' . $StringMultibyte->length
      );
      // @ Invalid
      // ...
   }
);
