<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\KVResourceProvide;


use function assert;
use function str_contains;
use Closure;
use RuntimeException;
use stdClass;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\KV;


return new Specification(
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
   }
);
