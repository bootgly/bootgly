<?php

use Bootgly\ABI\Code\__String;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: '',
   test: function () {
      // @ Valid (array maps)
      $caseFolding = __String::mapping('caseFolding');

      yield assert(
         assertion: is_array($caseFolding) && $caseFolding !== [],
         description: 'caseFolding mapping is a non-empty array'
      );

      $lowerCase = __String::mapping('lowerCase');

      yield assert(
         assertion: is_array($lowerCase) && $lowerCase !== [],
         description: 'lowerCase mapping is a non-empty array'
      );

      $upperCase = __String::mapping('upperCase');

      yield assert(
         assertion: is_array($upperCase) && $upperCase !== [],
         description: 'upperCase mapping is a non-empty array'
      );

      // @ Valid (string map — not an array, unlike the others)
      $titleCaseRegexp = __String::mapping('titleCaseRegexp');

      yield assert(
         assertion: is_string($titleCaseRegexp) && $titleCaseRegexp !== '',
         description: 'titleCaseRegexp mapping is a non-empty string'
      );

      // @ Invalid
      yield assert(
         assertion: __String::mapping('nonexistent') === false,
         description: 'Unknown mapping returns false'
      );
   }
);
