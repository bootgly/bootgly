<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environment\Configs;


return new Specification(
   description: 'Configs: .env and .env.<environment> resolution chain',
   test: function () {
      $basedir = __DIR__ . '/fixtures/configs/';

      // @ Without BOOTGLY_ENV — only shared .env loaded
      putenv('BOOTGLY_ENV');
      $Configs = new Configs($basedir);
      $Configs->load('database');

      $Database = $Configs->Scopes->get('database');
      $host = $Database->Connections->MySQL->Host->get();
      yield assert(
         assertion: $host === 'localhost',
         description: 'shared .env value used when no BOOTGLY_ENV'
      );

      // ---

      // @ With BOOTGLY_ENV=development — .env.development overrides
      putenv('BOOTGLY_ENV=development');
      $Configs2 = new Configs($basedir);
      $Configs2->load('database');

      $Database2 = $Configs2->Scopes->get('database');
      $host = $Database2->Connections->MySQL->Host->get();
      yield assert(
         assertion: $host === '127.0.0.1',
         description: '.env.development overrides shared .env'
      );

      $name = $Database2->Connections->MySQL->Database->get();
      yield assert(
         assertion: $name === 'bootgly_dev',
         description: '.env.development overrides DB_NAME'
      );

      // ---

      // @ Non-overridden value preserved from shared .env
      $port = $Database2->Connections->MySQL->Port->get();
      yield assert(
         assertion: $port === 3306,
         description: 'non-overridden value preserved from shared .env'
      );

      // @ Cleanup
      putenv('BOOTGLY_ENV');
      putenv('DB_HOST');
      putenv('DB_PORT');
      putenv('DB_NAME');
      putenv('DB_USER');
      putenv('DB_PASS');
   }
);
