<?php

use Bootgly\ABI\Code\__String\Theme;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'check(), select() and list() over the theme registry',
   test: function () {
      yield assert(
         assertion: Theme::check(Theme::DARK) === true,
         description: 'check(dark) = true'
      );
      yield assert(
         assertion: Theme::check('nope') === false,
         description: 'check(nope) = false'
      );

      $Theme = new Theme;
      yield assert(
         assertion: $Theme->select('nope') === false,
         description: 'select(nope) = false'
      );
      yield assert(
         assertion: $Theme->select(Theme::DARK) === true,
         description: 'select(dark) = true'
      );
      yield assert(
         assertion: $Theme->active === Theme::DARK,
         description: 'active = ' . (string) $Theme->active
      );

      $names = Theme::list();
      $hasAll = in_array(Theme::DARK, $names, true)
         && in_array(Theme::LIGHT, $names, true)
         && in_array(Theme::MONO, $names, true);
      yield assert(
         assertion: $hasAll,
         description: 'list() has dark/light/mono: ' . implode(', ', $names)
      );
   }
);
