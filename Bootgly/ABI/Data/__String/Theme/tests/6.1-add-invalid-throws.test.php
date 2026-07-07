<?php

use Bootgly\ABI\Data\__String\Theme;
use Bootgly\ABI\Data\__String\Theme\ThemeException;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'add() rejects an invalid theme structure with ThemeException',
   test: function () {
      $threw = false;
      try {
         // Missing the required "options" key.
         (new Theme)->add(['bad' => ['values' => []]]);
      }
      catch (ThemeException) {
         $threw = true;
      }

      yield assert(
         assertion: $threw === true,
         description: 'ThemeException thrown for missing options'
      );
   }
);
