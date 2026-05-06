<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environment\Configs\Config\Types;
use Bootgly\API\Environment\Configs\Config;


return new Specification(
   description: 'Config: bind resolves env vars with defaults and casting',
   test: function () {
      // @ Clean env
      putenv('TEST_HOST');
      putenv('TEST_PORT');

      // @ bind with default (no env var set)
      $Config = new Config(scope: 'test');
      $Config->Host->bind(key: 'TEST_HOST', default: 'localhost');

      yield assert(
         assertion: $Config->Host->get() === 'localhost',
         description: 'bind uses default when env not set'
      );

      // @ bind reads env var
      putenv('TEST_HOST=192.168.1.1');
      $Config2 = new Config(scope: 'test2');
      $Config2->Host->bind(key: 'TEST_HOST', default: 'localhost');

      yield assert(
         assertion: $Config2->Host->get() === '192.168.1.1',
         description: 'bind reads env var'
      );

      // @ bind with type cast
      putenv('TEST_PORT=8080');
      $Config3 = new Config(scope: 'test3');
      $Config3->Port->bind(key: 'TEST_PORT', default: 3306, cast: Types::Integer);

      yield assert(
         assertion: $Config3->Port->get() === 8080,
         description: 'bind casts to integer'
      );
      yield assert(
         assertion: $Config3->Port->get() !== '8080',
         description: 'cast result is not string'
      );

      // @ bind without key uses default directly
      $Config4 = new Config(scope: 'test4');
      $Config4->Driver->bind(key: '', default: 'mysql');

      yield assert(
         assertion: $Config4->Driver->get() === 'mysql',
         description: 'bind with empty key uses default directly'
      );

      // @ bind returns parent for chaining
      $Config5 = new Config(scope: 'test5');
      $returned = $Config5->Host->bind(key: '', default: 'localhost');

      yield assert(
         assertion: $returned === $Config5,
         description: 'bind returns parent for chaining'
      );

      // @ Cleanup
      putenv('TEST_HOST');
      putenv('TEST_PORT');
   }
);
