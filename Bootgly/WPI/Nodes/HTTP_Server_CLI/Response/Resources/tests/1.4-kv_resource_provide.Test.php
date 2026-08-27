<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\KVResourceProvide;


use function assert;
use function str_contains;
use Closure;
use RuntimeException;
use stdClass;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\KV;


return new Test(
   description: 'Response KV resource: provide() builds a lazy factory from a kv scope',
   test: function () {
      $configs = __DIR__ . '/fixtures/disabled/';

      // @ provide() returns a lazy factory closure
      $Factory = KV::provide($configs);
      yield assert(
         assertion: $Factory instanceof Closure,
         description: 'provide() returns a Closure factory'
      );

      // @ Non-Response context is rejected before any config is read
      $caught = null;
      try {
         $Factory(new stdClass);
      }
      catch (RuntimeException $Throwable) {
         $caught = $Throwable;
      }
      yield assert(
         assertion: $caught !== null && str_contains($caught->getMessage(), 'expects a Response context'),
         description: 'Factory rejects a non-Response context'
      );

      // @ A disabled kv scope fails loudly (before opening a connection)
      $disabled = null;
      try {
         $Factory(new Response);
      }
      catch (RuntimeException $Throwable) {
         $disabled = $Throwable;
      }
      yield assert(
         assertion: $disabled !== null && str_contains($disabled->getMessage(), 'KV_ENABLED'),
         description: 'Factory throws when the kv scope is disabled'
      );

      // @ Every Redis security field survives the Configs allowlist and the
      //   KVConfig adapter before a connection is ever opened.
      $SecureFactory = KV::provide(__DIR__ . '/fixtures/secure/');
      $Resource = $SecureFactory(new Response);
      $Config = $Resource->KV->Pool->Config;

      yield assert(
         assertion: $Config->host === 'redis.internal'
            && $Config->port === 6380
            && $Config->database === '2'
            && $Config->password === 'H2-resource-canary'
            && $Config->timeout === 1.5
            && $Config->pool === ['min' => 0, 'max' => 1],
         description: 'Factory allowlist preserves Redis connection and credential fields'
      );

      yield assert(
         assertion: $Config->secure === [
            'mode' => 'verify-full',
            'verify' => true,
            'name' => true,
            'peer' => 'redis.internal',
            'cafile' => '/run/secrets/redis-ca.pem',
            'key' => '',
         ],
         description: 'Factory allowlist preserves strict Redis TLS fields'
      );
   }
);
