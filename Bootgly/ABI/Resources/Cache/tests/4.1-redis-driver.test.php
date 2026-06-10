<?php

use function fclose;
use function fsockopen;
use function getenv;
use function is_resource;
use function uniqid;

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

      // # Counters + native TTL
      yield assert(
         assertion: $Cache->increment('n') === 1 && $Cache->increment('n', 6) === 7,
         description: 'increment() creates and advances'
      );
      $Cache->store('ttltest', 'v', 100);
      $TTL = $Cache->remain('ttltest');
      yield assert(
         assertion: $TTL > 0 && $TTL <= 100,
         description: 'remain() returns remaining seconds for an expiring key'
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
