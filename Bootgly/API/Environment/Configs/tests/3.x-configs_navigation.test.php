<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs;


return new Specification(
   description: 'Configs: object access for nested config values',
   test: function () {
      $Configs = new Configs(basedir: __DIR__ . '/fixtures/configs/');
      $Configs->load('database');

      $Database = $Configs->Scopes->get('database');

      // @ Top-level returns Config object
      yield assert(
         assertion: $Database instanceof Config,
         description: 'scope returns Config object'
      );

      // @ First level
      $default = $Database->Default->get();
      yield assert(
         assertion: $default === 'mysql',
         description: 'first-level value resolves correctly'
      );

      // @ Deep nested
      $host = $Database->Connections->MySQL->Host->get();
      yield assert(
         assertion: $host === 'localhost',
         description: 'deep nested value resolves correctly'
      );

      // @ Another deep nested
      $charset = $Database->Connections->MySQL->Charset->get();
      yield assert(
         assertion: $charset === 'utf8mb4',
         description: 'another deep nested value resolves'
      );
   }
);
