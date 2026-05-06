<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environment\Configs;
use Bootgly\API\Projects\Configs as ProjectConfigs;


return new Specification(
   description: 'Projects\Configs: overlay deep-merges project over framework',
   test: function () {
      // @ Clean env
      putenv('BOOTGLY_ENV');
      putenv('DB_HOST');
      putenv('DB_PORT');
      putenv('DB_NAME');
      putenv('DB_USER');
      putenv('DB_PASS');

      $frameworkBase = __DIR__ . '/fixtures/configs/';
      $projectBase = __DIR__ . '/fixtures/project/configs/';

      $Framework = new Configs($frameworkBase);
      $Project = new ProjectConfigs($projectBase);

      // @ Load project scope
      $Project->load('database');
      $Database = $Project->Scopes->get('database');

      // @ Before overlay — project has its own values
      $default = $Database->Default->get();
      yield assert(
         assertion: $default === 'pgsql',
         description: 'project config has own default before overlay'
      );

      // @ Overlay: merge framework under project
      $Project->overlay($Framework, 'database');

      // @ After overlay — project value wins
      $default = $Database->Default->get();
      yield assert(
         assertion: $default === 'pgsql',
         description: 'project value preserved after overlay'
      );

      // @ Framework host was overridden by project .env
      $host = $Database->Connections->MySQL->Host->get();
      yield assert(
         assertion: $host === 'project-db.local',
         description: 'project .env host overrides framework'
      );

      // @ Framework charset preserved (not in project config)
      $charset = $Database->Connections->MySQL->Charset->get();
      yield assert(
         assertion: $charset === 'utf8mb4',
         description: 'framework charset preserved through overlay'
      );

      // @ Overlay does not export framework/project .env values
      yield assert(
         assertion: getenv('DB_HOST') === false,
         description: 'overlay does not leak .env values to process env'
      );

      // @ Cleanup
      putenv('DB_HOST');
      putenv('DB_PORT');
      putenv('DB_NAME');
      putenv('DB_USER');
      putenv('DB_PASS');
   }
);
