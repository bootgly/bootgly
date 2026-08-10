<?php

use Bootgly\ABI\Code\__String\Theme;
use Bootgly\ABI\Code\__String\Theme\ThemeException;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
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
