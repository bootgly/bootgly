<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environment\Configs;
use Bootgly\API\Projects\Configs as ProjectConfigs;


return new Specification(
   description: 'Configs: .env values are isolated from process environment',
   test: function () {
      $basedir = __DIR__ . '/fixtures/configs/';
      $projectBase = __DIR__ . '/fixtures/project/configs/';

      // @ Clean env
      putenv('BOOTGLY_ENV');
      putenv('DB_HOST');
      putenv('DB_PORT');
      putenv('DB_NAME');
      putenv('DB_USER');
      putenv('DB_PASS');

      // @ .env should bind config values without exporting to process env
      $Configs = new Configs($basedir);
      $Configs->load('database');

      $Database = $Configs->Scopes->get('database');
      yield assert(
         assertion: $Database->Connections->MySQL->Host->get() === 'localhost',
         description: '.env value binds into config object'
      );
      yield assert(
         assertion: getenv('DB_HOST') === false,
         description: '.env value is not exported to process environment'
      );

      // @ Real process env should still win and remain unchanged
      putenv('DB_HOST=runtime-db.local');
      $Configs2 = new Configs($basedir);
      $Configs2->load('database');

      $Database2 = $Configs2->Scopes->get('database');
      yield assert(
         assertion: $Database2->Connections->MySQL->Host->get() === 'runtime-db.local',
         description: 'runtime env has precedence over .env file'
      );
      yield assert(
         assertion: getenv('DB_HOST') === 'runtime-db.local',
         description: 'runtime env remains unchanged after load'
      );

      // @ Project overlay should not leak project .env into process env
      putenv('DB_HOST');
      $Framework = new Configs($basedir);
      $Project = new ProjectConfigs($projectBase);
      $Project->overlay($Framework, 'database');

      yield assert(
         assertion: getenv('DB_HOST') === false,
         description: 'project overlay does not leak .env values to process env'
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
