<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environment\Configs;


return new Specification(
   description: 'Configs: .env variable names, allowlists and locked keys fail closed',
   test: function () {
      $basedir = __DIR__ . '/fixtures/configs/';

      // @ Clean env
      putenv('BOOTGLY_ENV');
      putenv('POLICY_VALUE');
      putenv('POLICY_SECRET');

      // @ Invalid variable names fail closed
      $Invalid = new Configs($basedir);
      yield assert(
         assertion: $Invalid->load('policy_bad_name') === false,
         description: 'invalid .env variable name fails load'
      );
      yield assert(
         assertion: $Invalid->Scopes->check('policy_bad_name') === false,
         description: 'invalid .env variable name does not register scope'
      );

      // @ Allowlist accepts declared keys
      $Allowed = new Configs($basedir);
      $Allowed->allow('policy_good', ['POLICY_VALUE']);
      yield assert(
         assertion: $Allowed->load('policy_good') === true,
         description: 'allowlist accepts declared .env key'
      );

      $Good = $Allowed->Scopes->get('policy_good');
      yield assert(
         assertion: $Good !== null && $Good->Value->get() === 'allowed',
         description: 'allowed .env key binds into config'
      );

      // @ Allowlist rejects typos or extra cross-scope keys
      $Extra = new Configs($basedir);
      $Extra->allow('policy_extra', ['POLICY_VALUE']);
      yield assert(
         assertion: $Extra->load('policy_extra') === false,
         description: 'allowlist rejects extra .env key'
      );
      yield assert(
         assertion: $Extra->Scopes->check('policy_extra') === false,
         description: 'rejected extra .env key does not register scope'
      );

      // @ Locked keys cannot be supplied by local .env files
      $Locked = new Configs($basedir);
      $Locked->lock('policy_locked', ['POLICY_SECRET']);
      yield assert(
         assertion: $Locked->load('policy_locked') === false,
         description: 'locked key in .env fails load'
      );
      yield assert(
         assertion: $Locked->Scopes->check('policy_locked') === false,
         description: 'locked .env key does not register scope'
      );

      // @ Locked keys may still be supplied by real process environment
      putenv('POLICY_SECRET=runtime-secret');
      $Runtime = new Configs($basedir);
      $Runtime->lock('policy_runtime', ['POLICY_SECRET']);
      yield assert(
         assertion: $Runtime->load('policy_runtime') === true,
         description: 'locked key can be provided by runtime env'
      );

      $RuntimeConfig = $Runtime->Scopes->get('policy_runtime');
      yield assert(
         assertion: $RuntimeConfig !== null && $RuntimeConfig->Secret->get() === 'runtime-secret',
         description: 'runtime env value binds for locked key'
      );
      putenv('POLICY_SECRET');

      // @ Environment-specific .env files obey the same policy
      putenv('BOOTGLY_ENV=development');
      $Development = new Configs($basedir);
      $Development->allow('policy_environment', ['POLICY_VALUE']);
      yield assert(
         assertion: $Development->load('policy_environment') === true,
         description: '.env.<environment> accepts allowed keys'
      );

      $DevelopmentConfig = $Development->Scopes->get('policy_environment');
      yield assert(
         assertion: $DevelopmentConfig !== null && $DevelopmentConfig->Value->get() === 'development',
         description: '.env.<environment> overrides shared .env with allowed key'
      );

      putenv('BOOTGLY_ENV=production');
      $Production = new Configs($basedir);
      $Production->allow('policy_environment', ['POLICY_VALUE']);
      yield assert(
         assertion: $Production->load('policy_environment') === false,
         description: '.env.<environment> rejects disallowed keys'
      );
      yield assert(
         assertion: $Production->Scopes->check('policy_environment') === false,
         description: 'disallowed environment .env key does not register scope'
      );

      // @ Cleanup
      putenv('BOOTGLY_ENV');
      putenv('POLICY_VALUE');
      putenv('POLICY_SECRET');
   }
);
