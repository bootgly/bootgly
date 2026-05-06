<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environment\Configs;


return new Specification(
   description: 'Configs: scopes load independently and coexist in registry',
   test: function () {
      // @ Cleanup env
      putenv('SERVER_HOST');
      putenv('SERVER_PORT');
      putenv('SERVER_WORKERS');

      $Configs = new Configs(basedir: __DIR__ . '/fixtures/configs/');

      // @ Not loaded yet
      yield assert(
         assertion: $Configs->Scopes->check('database') === false,
         description: 'database scope not loaded before load()'
      );
      yield assert(
         assertion: $Configs->Scopes->check('server') === false,
         description: 'server scope not loaded before load()'
      );

      // ---

      // @ Load database scope
      $Configs->load('database');

      yield assert(
         assertion: $Configs->Scopes->check('database') === true,
         description: 'database scope loaded after load()'
      );
      yield assert(
         assertion: $Configs->Scopes->check('server') === false,
         description: 'server scope still not loaded'
      );

      // @ Load server scope
      $Configs->load('server');

      yield assert(
         assertion: $Configs->Scopes->check('server') === true,
         description: 'server scope loaded after load()'
      );
      yield assert(
         assertion: count($Configs->Scopes->list()) === 2,
         description: 'both scopes in registry'
      );

      // ---

      // @ Same instance on repeated get
      $Scope = $Configs->Scopes->get('database');
      $ScopeAfter = $Configs->Scopes->get('database');
      yield assert(
         assertion: $Scope === $ScopeAfter,
         description: 'same Config instance on repeated Scopes->get()'
      );

      // @ Scopes are independent
      $Database = $Configs->Scopes->get('database');
      $Server = $Configs->Scopes->get('server');
      yield assert(
         assertion: $Database->scope === 'database',
         description: 'database Config has correct scope'
      );
      yield assert(
         assertion: $Server->scope === 'server',
         description: 'server Config has correct scope'
      );
      yield assert(
         assertion: $Database !== $Server,
         description: 'scopes are different instances'
      );

      // @ Values from each scope are correct
      yield assert(
         assertion: $Database->Default->get() === 'mysql',
         description: 'database scope value correct'
      );
      yield assert(
         assertion: $Server->Host->get() === '0.0.0.0',
         description: 'server scope host from .env'
      );
      yield assert(
         assertion: $Server->Port->get() === 9000,
         description: 'server scope port from .env'
      );

      // @ Cleanup
      putenv('SERVER_HOST');
      putenv('SERVER_PORT');
      putenv('SERVER_WORKERS');
   }
);
