<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs;


return new Specification(
   description: 'Config: unbound nodes return null, bound falsy values preserved',
   test: function () {
      // @ Unbound root node returns null
      $Config = new Config(scope: 'test');
      yield assert(
         assertion: $Config->get() === null,
         description: 'unbound root node get() returns null'
      );

      // @ Unbound child node returns null
      yield assert(
         assertion: $Config->Host->get() === null,
         description: 'unbound child node get() returns null'
      );

      // @ Unbound deep node returns null
      yield assert(
         assertion: $Config->Connections->MySQL->Host->get() === null,
         description: 'unbound deep node get() returns null'
      );

      // ---

      // @ Intermediate node without bind returns null
      $Config2 = new Config(scope: 'database');
      $Config2->Connections->MySQL->Host->bind(key: '', default: 'localhost');

      yield assert(
         assertion: $Config2->Connections->get() === null,
         description: 'intermediate node without bind returns null'
      );
      yield assert(
         assertion: $Config2->Connections->MySQL->get() === null,
         description: 'nested intermediate node without bind returns null'
      );

      // @ Unbound sibling of bound node returns null
      yield assert(
         assertion: $Config2->Connections->MySQL->Port->get() === null,
         description: 'unbound sibling of bound node returns null'
      );

      // ---

      // @ Bound falsy values distinguished from unbound null
      $Config3 = new Config(scope: 'falsy');
      $Config3->Zero->bind(key: '', default: 0);
      $Config3->Empty->bind(key: '', default: '');
      $Config3->False->bind(key: '', default: false);

      yield assert(
         assertion: $Config3->Zero->get() === 0,
         description: 'bound 0 returns 0, not null'
      );
      yield assert(
         assertion: $Config3->Empty->get() === '',
         description: 'bound empty string returns empty string'
      );
      yield assert(
         assertion: $Config3->False->get() === false,
         description: 'bound false returns false'
      );

      // ---

      // @ Loaded scope — unbound child returns null
      $base = __DIR__ . '/fixtures/configs/';
      $Configs = new Configs($base);
      $Configs->load('database');

      $Database = $Configs->Scopes->get('database');
      yield assert(
         assertion: $Database->Nonexistent->get() === null,
         description: 'loaded scope unbound child get() returns null'
      );
      yield assert(
         assertion: $Database->Default->get() === 'mysql',
         description: 'loaded scope bound value returns correct value'
      );
      yield assert(
         assertion: $Database->Connections->PostgreSQL->get() === null,
         description: 'loaded scope unbound nested child get() returns null'
      );
      yield assert(
         assertion: $Database->Connections->MySQL->Host->get() === 'localhost',
         description: 'loaded scope nested bound value returns correct value'
      );
   }
);
