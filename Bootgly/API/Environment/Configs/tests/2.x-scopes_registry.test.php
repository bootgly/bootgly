<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\Scopes;


return new Specification(
   description: 'Scopes: add, get, check, list operations',
   test: function () {
      $Scopes = new Scopes;

      // @ Empty
      yield assert(
         assertion: $Scopes->check('database') === false,
         description: 'check returns false for unknown scope'
      );
      yield assert(
         assertion: $Scopes->get('database') === null,
         description: 'get returns null for unknown scope'
      );
      yield assert(
         assertion: $Scopes->list() === [],
         description: 'list returns empty array initially'
      );

      // @ Add
      $Config = new Config(scope: 'database');
      $Scopes->add($Config);

      yield assert(
         assertion: $Scopes->check('database') === true,
         description: 'check returns true after add'
      );
      yield assert(
         assertion: $Scopes->get('database') === $Config,
         description: 'get returns same Config instance'
      );
      yield assert(
         assertion: count($Scopes->list()) === 1,
         description: 'list returns one entry'
      );

      // @ Add second
      $Config2 = new Config(scope: 'server');
      $Scopes->add($Config2);

      yield assert(
         assertion: count($Scopes->list()) === 2,
         description: 'list returns two entries'
      );
      yield assert(
         assertion: $Scopes->get('server') === $Config2,
         description: 'second scope accessible by name'
      );
   }
);
