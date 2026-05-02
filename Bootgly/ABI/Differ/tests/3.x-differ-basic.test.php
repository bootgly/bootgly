<?php

use Bootgly\ABI\Differ;
use Bootgly\ABI\Differ\Codes;
use Bootgly\ABI\Differ\Outputs\Unified;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Differ: compose() returns tagged diff array',
   test: function () {
      $Differ = new Differ(new Unified);

      // @ Identical → all OLD
      $diff = $Differ->compose("a\nb\n", "a\nb\n");
      $allOld = true;
      foreach ($diff as $entry) {
         if ($entry[1] !== Codes::OLD->value) {
            $allOld = false;
            break;
         }
      }
      yield assert(
         assertion: $allOld && count($diff) === 2,
         description: 'identical inputs → all OLD entries'
      );

      // @ Pure replace
      $diff = $Differ->compose("a\n", "b\n");
      $codes = array_column($diff, 1);
      yield assert(
         assertion: in_array(Codes::REMOVED->value, $codes, true)
            && in_array(Codes::ADDED->value, $codes, true),
         description: 'replace produces REMOVED + ADDED'
      );

      // @ Array input
      $diff = $Differ->compose(['a', 'b'], ['a', 'c']);
      yield assert(
         assertion: end($diff)[1] === Codes::ADDED->value,
         description: 'array input: trailing ADDED'
      );
   }
);
