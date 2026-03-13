<?php

use Bootgly\ABI\Data\__String;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: '',
   test: function () {
      // @ Valid
      $String1 = new __String('Bootgly é eficiente!');

      $result = $String1->pad(22, '_', STR_PAD_BOTH);

      yield assert(
         assertion: $result === '_Bootgly é eficiente!_',
         description: 'String1 with pad: ' . $result
      );
      // @ Invalid
      // ...
   }
);
