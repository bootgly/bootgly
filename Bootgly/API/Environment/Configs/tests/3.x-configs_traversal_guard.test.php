<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environment\Configs;


return new Specification(
   description: 'Configs: path traversal guard blocks unsafe scopes and env names',
   test: function () {
      $basedir = __DIR__ . '/fixtures/configs/';

      // @ Clean env
      putenv('BOOTGLY_ENV');
      putenv('DB_HOST');
      putenv('DB_PORT');
      putenv('DB_NAME');
      putenv('DB_USER');
      putenv('DB_PASS');

      $Configs = new Configs($basedir);

      yield assert(
         assertion: $Configs->load('../database') === false,
         description: 'parent-directory scope is rejected'
      );
      yield assert(
         assertion: $Configs->load('database/evil') === false,
         description: 'slash-containing scope is rejected'
      );
      yield assert(
         assertion: $Configs->get('database/evil.Default', 'fallback') === 'fallback',
         description: 'lazy loading unsafe scope returns default'
      );
      yield assert(
         assertion: count($Configs->Scopes->list()) === 0,
         description: 'unsafe scopes are not registered'
      );

      // @ Dot notation is not supported; nested access uses object navigation
      $Configs3 = new Configs($basedir);
      $Configs3->load('database');
      yield assert(
         assertion: $Configs3->get('database.scope', 'fallback') === 'fallback',
         description: 'dot-notation key returns default and is not navigated'
      );
      yield assert(
         assertion: $Configs3->Scopes->check('database.scope') === false,
         description: 'dot-notation key is not registered as a scope'
      );

      // @ Invalid BOOTGLY_ENV should not load .env.<unsafe>
      putenv('BOOTGLY_ENV=../development');
      $Configs2 = new Configs($basedir);
      $Configs2->load('database');

      $Database = $Configs2->Scopes->get('database');
      $host = $Database->Connections->MySQL->Host->get();
      yield assert(
         assertion: $host === 'localhost',
         description: 'unsafe BOOTGLY_ENV is ignored and shared .env is used'
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
