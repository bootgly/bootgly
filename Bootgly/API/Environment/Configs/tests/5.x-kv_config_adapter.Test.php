<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Config as ADIConfig;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\KVConfig;


return new Test(
   description: 'Configs: kv scope builds ADI Database Config',
   test: function () {
      $Scope = new Config(scope: 'kv');
      $Scope->Driver->bind(default: 'redis');
      $Scope->Host->bind(default: 'redis.local');
      $Scope->Port->bind(default: 6380);
      $Scope->Database->bind(default: '2');
      $Scope->Password->bind(default: 'redis-secret');
      $Scope->Timeout->bind(default: 1.5);
      $Scope->Secure->Mode->bind(default: 'verify-full');
      $Scope->Secure->Verify->bind(default: true);
      $Scope->Secure->Peer->bind(default: 'redis.internal');
      $Scope->Secure->CAFile->bind(default: '/run/secrets/redis-ca.pem');
      $Scope->Pool->Min->bind(default: 0);
      $Scope->Pool->Max->bind(default: 4);

      $Config = new KVConfig($Scope)->configure();

      yield assert(
         assertion: $Config instanceof ADIConfig,
         description: 'Adapter returns an ADI-native Config value'
      );

      yield assert(
         assertion: $Config->driver === 'redis'
            && $Config->host === 'redis.local'
            && $Config->port === 6380
            && $Config->database === '2'
            && $Config->password === 'redis-secret'
            && $Config->timeout === 1.5,
         description: 'Adapter maps connection fields'
      );

      yield assert(
         assertion: $Config->secure === [
            'mode' => ADIConfig::SECURE_VERIFY_FULL,
            'verify' => true,
            'name' => true,
            'peer' => 'redis.internal',
            'cafile' => '/run/secrets/redis-ca.pem',
            'key' => '',
         ],
         description: 'Adapter maps strict Redis TLS fields'
      );

      yield assert(
         assertion: $Config->pool === [
            'min' => 0,
            'max' => 4,
         ],
         description: 'Adapter maps pool fields'
      );

      // @ Unbound connection fields delegate to ADI Config defaults
      $Fallback = new Config(scope: 'kv');
      $Fallback->Driver->bind(default: 'redis');
      $FallbackConfig = new KVConfig($Fallback)->configure();

      yield assert(
         assertion: $FallbackConfig->host === ADIConfig::DEFAULT_HOST
            && $FallbackConfig->port === ADIConfig::DEFAULT_PORT
            && $FallbackConfig->pool === [
               'min' => ADIConfig::DEFAULT_POOL_MIN,
               'max' => ADIConfig::DEFAULT_POOL_MAX,
            ],
         description: 'Adapter delegates unbound fields to ADI Config defaults'
      );

      // @ Unsupported driver is rejected
      $InvalidDriver = new Config(scope: 'kv');
      $InvalidDriver->Driver->bind(default: 'memcached');
      $invalidDriverRejected = false;

      try {
         new KVConfig($InvalidDriver)->configure();
      }
      catch (InvalidArgumentException) {
         $invalidDriverRejected = true;
      }

      yield assert(
         assertion: $invalidDriverRejected,
         description: 'Adapter rejects unsupported KV drivers'
      );

      // @ Invalid TLS modes fail through the ADI Config authority.
      $InvalidTLS = new Config(scope: 'kv');
      $InvalidTLS->Driver->bind(default: 'redis');
      $InvalidTLS->Secure->Mode->bind(default: 'opportunistic');
      $invalidTLSRejected = false;

      try {
         new KVConfig($InvalidTLS)->configure();
      }
      catch (InvalidArgumentException) {
         $invalidTLSRejected = true;
      }

      yield assert(
         assertion: $invalidTLSRejected,
         description: 'Adapter rejects unsupported Redis TLS modes'
      );

      // @ Non-kv scopes are rejected
      $wrongScopeRejected = false;

      try {
         new KVConfig(new Config(scope: 'database'));
      }
      catch (InvalidArgumentException) {
         $wrongScopeRejected = true;
      }

      yield assert(
         assertion: $wrongScopeRejected,
         description: 'Adapter rejects non-kv scopes'
      );
   }
);
