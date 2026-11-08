<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environment\Configs;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\Schema\Failure;


return new Test(
   description: 'Configs: a scope declared with a Schema loads or fails with one report',
   test: function () {
      $basedir = __DIR__ . '/fixtures/configs/';

      // @ Clean env (also when the test stops early)
      $names = ['BOOTGLY_ENV', 'SCHEMA_HOST', 'SCHEMA_PORT', 'SCHEMA_PASS', 'SCHEMA_BAD_PORT', 'SCHEMA_BAD_WORKERS', 'SCHEMA_BAD_PASS', 'SCHEMA_UNBUILT_PASS'];
      foreach ($names as $name) {
         putenv($name);
      }
      try {

         // @ A valid scope loads and registers
         $Configs = new Configs($basedir);
         yield assert(
            assertion: $Configs->load('schema_good') === true,
            description: 'a valid schema scope loads'
         );
         $Good = $Configs->Scopes->get('schema_good');
         yield assert(
            assertion: $Good instanceof Config
               && $Good->Host->get() === 'localhost'
               && $Good->Port->get() === 9090
               && $Good->Connections->Primary->Password->get() === 'local-secret-value',
            description: 'the schema reads the scope-local .env, casts and applies defaults'
         );

         // @ The scope .env stays local after the load
         $After = new Config(scope: 'after');
         $After->Port->bind(key: 'SCHEMA_PORT', default: 'none');
         yield assert(
            assertion: $After->Port->get() === 'none' && getenv('SCHEMA_PORT') === false,
            description: 'the scope-local .env does not leak after the load'
         );

         // @ An invalid scope fails its load with the aggregated report
         $Bad = new Configs($basedir);
         $Failure = null;
         try {
            $Bad->load('schema_bad');
         }
         catch (Failure $Caught) {
            $Failure = $Caught;
         }
         yield assert(
            assertion: $Failure !== null && $Failure->scope === 'schema_bad' && $Failure->failures === [
               'Port' => ['Port must be at most 65535.'],
               'Workers' => ['Invalid integer config value.'],
               'Connections.Primary.Password' => ['Required config value is missing: SCHEMA_BAD_PASS'],
            ],
            description: 'load() throws one report with every failure of the scope'
         );
         yield assert(
            assertion: $Failure !== null
               && str_contains($Failure->getMessage(), '70000') === false
               && str_contains($Failure->getMessage(), 'many-local-secret') === false,
            description: 'the load report never echoes the .env values'
         );
         yield assert(
            assertion: $Bad->Scopes->check('schema_bad') === false,
            description: 'a failed scope is not registered'
         );

         // @ Lazy get() fails the same way (nothing half-registered)
         $Lazy = null;
         try {
            $Bad->get('schema_bad');
         }
         catch (Failure $Caught) {
            $Lazy = $Caught;
         }
         yield assert(
            assertion: $Lazy !== null && count($Lazy->failures) === 3 && $Bad->Scopes->check('schema_bad') === false,
            description: 'a lazy get() reports the same failure and registers nothing'
         );

         // @ The real environment repairs the scope
         putenv('SCHEMA_BAD_PORT=8081');
         putenv('SCHEMA_BAD_WORKERS=2');
         putenv('SCHEMA_BAD_PASS=runtime');
         yield assert(
            assertion: $Bad->load('schema_bad') === true
               && $Bad->Scopes->get('schema_bad')?->Port->get() === 8081,
            description: 'once the environment is fixed, the same scope loads'
         );

         // @ A Schema returned without build() fails loudly instead of vanishing
         $Unbuilt = new Configs($basedir);
         $message = null;
         try {
            $Unbuilt->load('schema_unbuilt');
         }
         catch (RuntimeException $Exception) {
            $message = $Exception->getMessage();
         }
         yield assert(
            assertion: $message === 'Config file of scope `schema_unbuilt` returns a Schema: return its build() result.'
               && $Unbuilt->Scopes->check('schema_unbuilt') === false,
            description: 'a scope file returning an unbuilt Schema throws and registers nothing'
         );
         $lazy = null;
         try {
            $Unbuilt->get('schema_unbuilt', 'DEFAULT');
         }
         catch (RuntimeException $Exception) {
            $lazy = $Exception->getMessage();
         }
         yield assert(
            assertion: $lazy === $message,
            description: 'a lazy get() of an unbuilt Schema throws too (never returns the default)'
         );
      }
      finally {
         foreach ($names as $name) {
            putenv($name);
         }
      }
   }
);
