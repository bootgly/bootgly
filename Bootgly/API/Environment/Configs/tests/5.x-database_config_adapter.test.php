<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Config as ADIConfig;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\DatabaseConfig;


return new Specification(
   description: 'Configs: database scope builds ADI Database Config',
   test: function () {
      $Scope = new Config(scope: 'database');
      $Scope->Default->bind(default: 'pgsql');

      $PostgreSQL = $Scope->Connections->PostgreSQL;
      $PostgreSQL->Driver->bind(default: 'pgsql');
      $PostgreSQL->Host->bind(default: 'db.local');
      $PostgreSQL->Port->bind(default: 55432);
      $PostgreSQL->Database->bind(default: 'bootgly_test');
      $PostgreSQL->Username->bind(default: 'bootgly');
      $PostgreSQL->Password->bind(default: 'secret');
      $PostgreSQL->Timeout->bind(default: 2.5);
      $PostgreSQL->Statements->bind(default: 32);

      $Secure = $PostgreSQL->Secure;
      $Secure->Mode->bind(default: 'require');
      $Secure->Verify->bind(default: true);
      $Secure->Peer->bind(default: 'postgres.internal');
      $Secure->CAFile->bind(default: '/tmp/ca.pem');

      $Pool = $PostgreSQL->Pool;
      $Pool->Min->bind(default: 1);
      $Pool->Max->bind(default: 4);

      $PostgreSQL->Routing->Sticky->bind(default: 0.25);

      $Replica = $PostgreSQL->Replicas->Replica1;
      $Replica->Host->bind(default: 'replica.local');
      $Replica->Port->bind(default: 55433);
      $Replica->Database->bind(default: 'bootgly_read');
      $Replica->Username->bind(default: 'reader');
      $Replica->Password->bind(default: 'read-secret');
      $Replica->Timeout->bind(default: 1.5);
      $Replica->Statements->bind(default: 16);
      $Replica->Secure->Mode->bind(default: 'require');
      $Replica->Secure->Verify->bind(default: true);
      $Replica->Secure->Peer->bind(default: 'replica.internal');
      $Replica->Secure->CAFile->bind(default: '/tmp/replica-ca.pem');
      $Replica->Pool->Min->bind(default: 0);
      $Replica->Pool->Max->bind(default: 2);
      $PostgreSQL->Replicas->Replica2->Host->bind(default: null);

      $DatabaseConfig = new DatabaseConfig($Scope);
      $Config = $DatabaseConfig->configure();

      yield assert(
         assertion: $Config instanceof ADIConfig,
         description: 'Adapter returns an ADI-native Config value'
      );

      yield assert(
         assertion: $Config->driver === 'pgsql'
            && $Config->host === 'db.local'
            && $Config->port === 55432
            && $Config->database === 'bootgly_test'
            && $Config->username === 'bootgly'
            && $Config->password === 'secret'
            && $Config->timeout === 2.5
            && $Config->statements === 32,
         description: 'Adapter maps connection fields'
      );

      yield assert(
         assertion: $Config->secure === [
            'mode' => 'require',
            'verify' => true,
            'name' => true,
            'peer' => 'postgres.internal',
            'cafile' => '/tmp/ca.pem',
         ],
         description: 'Adapter maps TLS fields'
      );

      yield assert(
         assertion: $Config->pool === [
            'min' => 1,
            'max' => 4,
         ],
         description: 'Adapter maps pool fields'
      );

      yield assert(
         assertion: $Config->routing === [
            'sticky' => 0.25,
         ] && count($Config->replicas) === 1
            && $Config->replicas[0]['host'] === 'replica.local'
            && $Config->replicas[0]['port'] === 55433
            && $Config->replicas[0]['database'] === 'bootgly_read'
            && $Config->replicas[0]['username'] === 'reader'
            && $Config->replicas[0]['password'] === 'read-secret'
            && $Config->replicas[0]['timeout'] === 1.5
            && $Config->replicas[0]['statements'] === 16
            && $Config->replicas[0]['secure'] === [
               'mode' => 'require',
               'verify' => true,
               'name' => true,
               'peer' => 'replica.internal',
               'cafile' => '/tmp/replica-ca.pem',
            ] && $Config->replicas[0]['pool'] === [
               'min' => 0,
               'max' => 2,
            ],
         description: 'Adapter maps read replicas and skips disabled replica nodes'
      );

      $Fallback = new Config(scope: 'database');
      $Fallback->Connections->PostgreSQL->Host->bind(default: 'fallback.local');
      $FallbackConfig = (new DatabaseConfig($Fallback))->configure();

      yield assert(
         assertion: $FallbackConfig->driver === ADIConfig::DEFAULT_DRIVER
            && $FallbackConfig->host === 'fallback.local'
            && $FallbackConfig->port === ADIConfig::DEFAULT_PORT
            && $FallbackConfig->database === ADIConfig::DEFAULT_DATABASE
            && $FallbackConfig->username === ADIConfig::DEFAULT_USERNAME
            && $FallbackConfig->password === ADIConfig::DEFAULT_PASSWORD
            && $FallbackConfig->timeout === ADIConfig::DEFAULT_TIMEOUT
            && $FallbackConfig->statements === ADIConfig::DEFAULT_STATEMENTS,
         description: 'Adapter delegates unbound connection defaults to ADI Config'
      );

      yield assert(
         assertion: $FallbackConfig->secure === [
            'mode' => ADIConfig::DEFAULT_SECURE_MODE,
            'verify' => true,
            'name' => true,
            'peer' => 'fallback.local',
            'cafile' => ADIConfig::DEFAULT_SECURE_CAFILE,
         ] && $FallbackConfig->pool === [
            'min' => ADIConfig::DEFAULT_POOL_MIN,
            'max' => ADIConfig::DEFAULT_POOL_MAX,
         ],
         description: 'Adapter delegates unbound secure and pool defaults to ADI Config'
      );

      $Base = new Config(scope: 'database');
      $Base->Connections->PostgreSQL->Port->bind(default: 6543);
      $Base->Connections->PostgreSQL->Database->bind(default: 'base_db');
      $Project = new Config(scope: 'database');
      $Project->Connections->PostgreSQL->Host->bind(default: 'project.local');
      $MergedConfig = (new DatabaseConfig($Project))->merge($Base)->configure();

      yield assert(
         assertion: $MergedConfig->host === 'project.local'
            && $MergedConfig->port === 6543
            && $MergedConfig->database === 'base_db',
         description: 'Adapter can merge a base database scope under project overrides'
      );

      $VerifyCA = new Config(scope: 'database');
      $VerifyCA->Connections->PostgreSQL->Host->bind(default: 'db.internal');
      $VerifyCA->Connections->PostgreSQL->Secure->Mode->bind(default: ADIConfig::SECURE_VERIFY_CA);
      $VerifyConfig = (new DatabaseConfig($VerifyCA))->configure();

      yield assert(
         assertion: $VerifyConfig->secure['mode'] === ADIConfig::SECURE_VERIFY_CA
            && $VerifyConfig->secure['verify'] === true
            && $VerifyConfig->secure['name'] === false,
         description: 'Adapter accepts verify-ca TLS mode'
      );

      $InvalidMode = new Config(scope: 'database');
      $InvalidMode->Connections->PostgreSQL->Secure->Mode->bind(default: 'foobar');
      $invalidModeRejected = false;

      try {
         (new DatabaseConfig($InvalidMode))->configure();
      }
      catch (InvalidArgumentException) {
         $invalidModeRejected = true;
      }

      yield assert(
         assertion: $invalidModeRejected,
         description: 'Adapter rejects unsupported TLS modes'
      );

      $wrongScopeRejected = false;

      try {
         new DatabaseConfig(new Config(scope: 'session'));
      }
      catch (InvalidArgumentException) {
         $wrongScopeRejected = true;
      }

      yield assert(
         assertion: $wrongScopeRejected,
         description: 'Adapter rejects non-database scopes'
      );
   }
);
