<?php

namespace Bootgly\API\Projects;


use function assert;
use ArgumentCountError;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should require the explicit exportable flag on every Project',
   test: function () {
      // ! Exportable project
      $Exportable = new Project(
         boot: static function (): void {},
         exportable: true,
         name: 'Exportable'
      );

      // ! Private project
      $Private = new Project(
         boot: static function (): void {},
         exportable: false,
         name: 'Private'
      );

      // @ Valid
      yield assert(
         assertion: $Exportable->exportable === true,
         description: 'Exportable projects expose the flag as true'
      );
      yield assert(
         assertion: $Private->exportable === false,
         description: 'Private projects expose the flag as false'
      );

      // @ Invalid
      $refused = false;
      try {
         new Project(boot: static function (): void {});
      }
      catch (ArgumentCountError) {
         $refused = true;
      }

      yield assert(
         assertion: $refused === true,
         description: 'Omitting the exportable flag is refused — the declaration is not optional'
      );
   }
);
