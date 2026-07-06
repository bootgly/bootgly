<?php

use Bootgly\ACI\Mail\Message\Address;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Message\Address: parsing, display names and validation',
   test: function () {
      // @ Forms
      $Address = new Address('user@example.com');
      yield assert(
         assertion: $Address->email === 'user@example.com' && $Address->name === '',
         description: 'a bare email parses with an empty name'
      );

      $Address = new Address('Ana Silva <ana@example.com>');
      yield assert(
         assertion: $Address->email === 'ana@example.com' && $Address->name === 'Ana Silva',
         description: 'Name <email> parses both parts'
      );

      $Address = new Address('"Silva, Ana" <ana@example.com>');
      yield assert(
         assertion: $Address->name === 'Silva, Ana',
         description: 'a quoted-string display name is unwrapped'
      );

      $Address = new Address('"Quote \" and \\\\ slash" <q@example.com>');
      yield assert(
         assertion: $Address->name === 'Quote " and \\ slash',
         description: 'escaped quotes/backslashes inside a quoted name are unescaped'
      );

      $Address = new Address('  spaced@example.com  ');
      yield assert(
         assertion: $Address->email === 'spaced@example.com',
         description: 'surrounding whitespace is trimmed'
      );

      $Address = new Address('José <josé@exãmple.com>');
      yield assert(
         assertion: $Address->email === 'josé@exãmple.com' && $Address->name === 'José',
         description: 'UTF-8 bytes are allowed (SMTPUTF8 is a transport concern)'
      );

      // @ Rejections
      foreach ([
         '' => 'empty address',
         'no-at-sign' => 'address without @',
         'local@' => 'empty domain',
         '@domain.com' => 'empty local part',
         'two words@example.com' => 'whitespace inside the email',
         'Name <a@b> <c@d>' => 'brackets inside the email',
      ] as $invalid => $label) {
         $caught = false;
         try {
            new Address($invalid);
         }
         catch (InvalidArgumentException) {
            $caught = true;
         }
         yield assert(
            assertion: $caught,
            description: "{$label} throws InvalidArgumentException"
         );
      }

      $caught = false;
      try {
         new Address("evil@example.com\r\nBcc: victim@x.com");
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'CR/LF injection through an address throws'
      );
   }
);
