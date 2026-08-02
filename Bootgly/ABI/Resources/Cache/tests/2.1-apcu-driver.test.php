<?php

use function extension_loaded;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cache(APCu): store/fetch, check, increment and tag invalidation (requires ext-apcu)',
   skip: extension_loaded('apcu') === false || apcu_enabled() === false,
   test: function () {
      $prefix = 'apcu-test-' . uniqid() . ':';
      $Cache = new Cache(['driver' => 'apcu', 'prefix' => $prefix]);

      $Cache->store('s', 'string');
      $Cache->store('i', 42);

      yield assert(
         assertion: $Cache->fetch('s') === 'string',
         description: 'String value round-trips'
      );
      yield assert(
         assertion: $Cache->fetch('i') === 42,
         description: 'Integer value round-trips'
      );
      yield assert(
         assertion: $Cache->fetch('missing') === null,
         description: 'Missing key returns null'
      );
      yield assert(
         assertion: $Cache->check('s') === true && $Cache->check('missing') === false,
         description: 'check() reflects presence'
      );

      yield assert(
         assertion: $Cache->swap('conditional', 'missing', 'unexpected') === false
            && $Cache->evict('conditional', 'missing') === false
            && $Cache->renew('conditional', 30) === false
            && $Cache->fetch('conditional') === null,
         description: 'Unsupported APCu atomic mutations fail closed on a missing key'
      );
      yield assert(
         assertion: $Cache->create('conditional', 'created') === true
            && $Cache->create(
               'conditional',
               'clobbered',
               tags: ['rejected-create'],
            ) === false
            && $Cache->invalidate('rejected-create') === true
            && $Cache->fetch('conditional') === 'created',
         description: 'create() stores only the first live value and tags only successful writes'
      );
      yield assert(
         assertion: $Cache->swap('conditional', 'created', 'replaced') === false
            && $Cache->evict('conditional', 'created') === false
            && $Cache->renew('conditional', 30) === false
            && $Cache->fetch('conditional') === 'created',
         description: 'APCu swap()/evict()/renew() fail closed without a true atomic primitive'
      );
      $Cache->delete('conditional');

      yield assert(
         assertion: $Cache->increment('hits') === 1 && $Cache->increment('hits', 4) === 5,
         description: 'increment() creates and advances a counter'
      );
      yield assert(
         assertion: $Cache->decrement('hits', 2) === 3,
         description: 'decrement() subtracts'
      );

      $Cache->store('a', 'A', 0, ['group']);
      $Cache->store('b', 'B', 0, ['group']);
      $Cache->store('c', 'C', 0, ['other']);
      $Cache->invalidate('group');

      yield assert(
         assertion: $Cache->fetch('a') === null && $Cache->fetch('b') === null,
         description: 'Tagged keys invalidated'
      );
      yield assert(
         assertion: $Cache->fetch('c') === 'C',
         description: 'Differently-tagged key survives'
      );

      $Cache->clear();

      yield assert(
         assertion: $Cache->fetch('s') === null && $Cache->fetch('c') === null,
         description: 'clear() empties the prefixed namespace'
      );
   }
);
