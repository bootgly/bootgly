<?php

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Levels::fetch() resolves names case-insensitively and label()/values follow RFC5424',
   test: function () {
      yield assert(
         assertion: Levels::fetch('error') === Levels::Error,
         description: 'fetch() resolves a lowercase level name'
      );

      yield assert(
         assertion: Levels::fetch('INFO') === Levels::Info,
         description: 'fetch() is case-insensitive'
      );

      yield assert(
         assertion: Levels::fetch('nope') === null,
         description: 'fetch() returns null for an unknown name'
      );

      yield assert(
         assertion: Levels::Error->render() === 'ERROR',
         description: 'render() returns the uppercase severity'
      );

      yield assert(
         assertion: Levels::Emergency->value === 1 && Levels::Debug->value === 8,
         description: 'backing values follow RFC5424 (1 = most severe, 8 = least)'
      );
   }
);
