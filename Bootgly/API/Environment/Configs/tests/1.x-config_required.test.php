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
         (new Config(scope: 'test'))->Secret->bind(
            key: 'TEST_SECRET',
            required: true
         );
      }
      catch (RuntimeException) {
         $missing = true;
      }
      yield assert(
         assertion: $missing,
         description: 'required bind throws when env is missing'
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
      $Runtime->Secret->bind(
         key: 'TEST_SECRET',
         required: true
      );
      yield assert(
         assertion: $Runtime->Secret->get() === 'runtime-secret',
         description: 'required bind reads runtime env'
      );

      putenv('TEST_SECRET=');
      $empty = false;
      try {
         (new Config(scope: 'test'))->Secret->bind(
            key: 'TEST_SECRET',
            required: true
         );
      }
      catch (RuntimeException) {
         $empty = true;
      }
      yield assert(
         assertion: $empty,
         description: 'required bind rejects empty env'
      );

      $previous = Config::swap(['TEST_FLAG' => 'false']);
      try {
         $Local = new Config(scope: 'test');
         $Local->Flag->bind(
            key: 'TEST_FLAG',
            cast: Types::Boolean,
            required: true
         );
      }
      finally {
         Config::swap($previous);
      }
      yield assert(
         assertion: $Local->Flag->get() === false,
         description: 'required bind reads local .env context and casts strictly'
      );

      // @ Cleanup
      putenv('TEST_SECRET');
      putenv('TEST_FLAG');
   }
);