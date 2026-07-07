<?php

use Bootgly\ABI\Data\__String\Theme;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'apply() appending uses the appending value, not the prepending one (bug fix)',
   test: function () {
      $Theme = new Theme;
      $Theme->add(['fixed' => [
         'options' => [
            'prepending' => ['type' => 'string',   'value' => '<'],
            'appending'  => ['type' => 'callback', 'value' => fn (string $c = '') => ">$c"]
         ],
         'values' => ['k' => ['END']]
      ]]);
      $Theme->select('fixed');

      $result = $Theme->apply('k', 'MID');

      // Old bug: appending callback invoked $prepending['value'] ('<', not callable) => dropped ">END".
      yield assert(
         assertion: $result === '<MID>END',
         description: 'apply(k, MID) = ' . $result . ' (expected <MID>END)'
      );
   }
);
