<?php

use Bootgly\ABI\Data\__String;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: '',
   test: function () {
      // @ Valid
      // ASCII text
      $ASCII = new __String('Bootgly is efficient!', 'ASCII');
      yield assert(
         assertion: $ASCII->lowercase === 'bootgly is efficient!',
         description: 'ASCII text converted to lowercase: ' . $ASCII->lowercase
      );
      // UTF-8 text
      $ASCII = new __String('Bootgly é eficiente!');
      yield assert(
         assertion: $ASCII->lowercase === 'bootgly é eficiente!',
         description: 'UTF-8 text converted to lowercase: ' . $ASCII->lowercase
      );
      // @ Invalid
      // ...
   }
);
