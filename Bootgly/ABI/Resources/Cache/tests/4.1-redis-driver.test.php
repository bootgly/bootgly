<?php


use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test\Specification;


// ! Probe a reachable Redis server (native socket — no ext-redis required)
$host = getenv('REDIS_HOST') !== false ? (string) getenv('REDIS_HOST') : '127.0.0.1';
$port = getenv('REDIS_PORT') !== false ? (int) getenv('REDIS_PORT') : 6379;
$Probe = @fsockopen($host, $port, $errno, $error, 0.2);
$reachable = is_resource($Probe);
if ($reachable === true) {
   fclose($Probe);
}


return new Specification(
   description: 'Cache(Redis): blocking driver contract over RESP (requires a reachable Redis)',
   skip: $reachable === false,
   test: function () use ($host, $port) {
      $Cache = new Cache([
         'driver' => 'redis',
         'host' => $host,
         'port' => $port,
         'prefix' => 'bootgly-test-' . uniqid() . ':',
      ]);
      $Cache->clear();

      // # Store / fetch with type fidelity
      $Cache->store('s', 'string');
      $Cache->store('i', 42);
      $Cache->store('arr', ['a' => 1, 'b' => [2, 3]]);
      yield assert(
         assertion: $Cache->fetch('s') === 'string'
            && $Cache->fetch('i') === 42
            && $Cache->fetch('arr') === ['a' => 1, 'b' => [2, 3]],
         description: 'Scalars and arrays round-trip with type fidelity'
      );
      yield assert(
         assertion: $Cache->fetch('missing') === null && $Cache->check('s') === true,
         description: 'Miss returns null; check() reflects presence'
      );

      yield assert(
         assertion: $Cache->swap('conditional', 'missing', 'unexpected') === false
            && $Cache->evict('conditional', 'missing') === false
            && $Cache->renew('conditional', 30) === false
            && $Cache->fetch('conditional') === null,
         description: 'Atomic mutations refuse a missing key'
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
         assertion: $Cache->swap(
            'conditional',
            'stale',
            'clobbered',
            tags: ['rejected-swap'],
         ) === false
            && $Cache->invalidate('rejected-swap') === true
            && $Cache->fetch('conditional') === 'created'
            && $Cache->swap('conditional', 'created', 'replaced') === true
            && $Cache->fetch('conditional') === 'replaced'
            && $Cache->evict('conditional', 'created') === false
            && $Cache->evict('conditional', 'replaced') === true
            && $Cache->swap('conditional', 'replaced', 'resurrected') === false,
         description: 'Lua swap()/evict() require exact packed bytes and cannot resurrect a key'
      );

      // # Counters + native TTL
      yield assert(
         assertion: $Cache->increment('n') === 1 && $Cache->increment('n', 6) === 7,
         description: 'increment() creates and advances'
      );
      $Cache->store('ttltest', 'v', 100);
      $renewed = $Cache->renew('ttltest', 200);
      $TTL = $Cache->remain('ttltest');
      yield assert(
         assertion: $renewed === true
            && $Cache->fetch('ttltest') === 'v'
            && $TTL > 100
            && $TTL <= 200,
         description: 'renew() extends native TTL without rewriting the value'
      );
      yield assert(
         assertion: $Cache->remain('missing') === -2,
         description: 'remain() returns -2 for a missing key'
      );

      // # Tags
      $Cache->store('a', 'A', 0, ['group']);
      $Cache->store('b', 'B', 0, ['other']);
      $Cache->invalidate('group');
      yield assert(
         assertion: $Cache->fetch('a') === null && $Cache->fetch('b') === 'B',
         description: 'Tag invalidation drops only tagged keys'
      );

      // # Tags at scale (pipelined SET+SADD store; chunked variadic UNLINK)
      $stored = true;
      for ($i = 0; $i < 600; $i++) {
         $stored = $Cache->store("m:$i", $i, 0, ['bulk', 'extra']) && $stored;
      }
      yield assert(
         assertion: $stored === true && $Cache->fetch('m:0') === 0 && $Cache->fetch('m:599') === 599,
         description: 'Tagged store (pipelined) persists values and reports success'
      );
      $Cache->invalidate('bulk');
      yield assert(
         assertion: $Cache->fetch('m:0') === null && $Cache->fetch('m:599') === null,
         description: 'Invalidating 600+ members (chunked UNLINK) drops them all'
      );

      // # Persistent connection (opt-in) still speaks the protocol correctly
      $Persistent = new Cache([
         'driver' => 'redis',
         'host' => $Cache->Config->host,
         'port' => $Cache->Config->port,
         'prefix' => $Cache->Config->prefix,
         'persistent' => true,
      ]);
      $Persistent->store('p', 'persistent');
      yield assert(
         assertion: $Persistent->fetch('p') === 'persistent',
         description: 'Persistent connection round-trips values'
      );

      // # Clear (prefix-scoped via SCAN + variadic UNLINK)
      $Cache->clear();
      yield assert(
         assertion: $Cache->fetch('b') === null && $Cache->fetch('n') === null && $Cache->fetch('p') === null,
         description: 'clear() empties the prefixed namespace'
      );
   }
);
