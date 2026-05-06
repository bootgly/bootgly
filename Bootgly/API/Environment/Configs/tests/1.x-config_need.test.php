<?php

use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\Config\Types;


return new Specification(
   description: 'Config: required bindings fail closed',
   test: function () {
      // @ Clean env
      putenv('TEST_SECRET');
      putenv('TEST_FLAG');

      $missing = false;
      try {
         (new Config(scope: 'test'))->Secret->need('TEST_SECRET');
      }
      catch (RuntimeException) {
         $missing = true;
      }
      yield assert(
         assertion: $missing,
         description: 'need throws when required env is missing'
      );

      $default = false;
      try {
         (new Config(scope: 'test'))->Secret->bind(
            key: 'TEST_SECRET',
            default: 'development-secret',
            required: true
         );
      }
      catch (RuntimeException) {
         $default = true;
      }
      yield assert(
         assertion: $default,
         description: 'required bind does not fall back to default'
      );

      putenv('TEST_SECRET=runtime-secret');
      $Runtime = new Config(scope: 'test');
      $Runtime->Secret->need('TEST_SECRET');
      yield assert(
         assertion: $Runtime->Secret->get() === 'runtime-secret',
         description: 'need reads required runtime env'
      );

      putenv('TEST_SECRET=');
      $empty = false;
      try {
         (new Config(scope: 'test'))->Secret->need('TEST_SECRET');
      }
      catch (RuntimeException) {
         $empty = true;
      }
      yield assert(
         assertion: $empty,
         description: 'need rejects empty required env'
      );

      $previous = Config::swap(['TEST_FLAG' => 'false']);
      try {
         $Local = new Config(scope: 'test');
         $Local->Flag->need('TEST_FLAG', Types::Boolean);
      }
      finally {
         Config::swap($previous);
      }
      yield assert(
         assertion: $Local->Flag->get() === false,
         description: 'need reads local .env context and casts strictly'
      );

      // @ Cleanup
      putenv('TEST_SECRET');
      putenv('TEST_FLAG');
   }
);
