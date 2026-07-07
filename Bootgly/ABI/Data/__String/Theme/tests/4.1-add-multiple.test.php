<?php

use Bootgly\ABI\Data\__String\Theme;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'add() registers more than one theme in a single call',
   test: function () {
      $Theme = new Theme;

      $spec = [
         'options' => [
            'prepending' => ['type' => 'string', 'value' => ''],
            'appending'  => ['type' => 'string', 'value' => '']
         ],
         'values' => []
      ];
      $Theme->add(['multiA' => $spec, 'multiB' => $spec]);

      yield assert(
         assertion: Theme::check('multiA') === true,
         description: 'multiA registered'
      );
      yield assert(
         assertion: Theme::check('multiB') === true,
         description: 'multiB registered'
      );
   }
);
