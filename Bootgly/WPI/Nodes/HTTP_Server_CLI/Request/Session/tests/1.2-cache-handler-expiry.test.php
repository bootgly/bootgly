<?php

use function bin2hex;
use function random_bytes;
use function serialize;
use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Resources\Cache as CacheResource;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache;


return new Specification(
   description: 'Session Cache handler: native TTL expiry + touch() sliding renewal',
   test: function () {
      // ! Deterministic clock driving the cache backend
      $now = 1000;
      $clock = function () use (&$now): int {
         return $now;
      };

      $dir = sys_get_temp_dir() . '/bootgly-session-test-' . uniqid();
      $Handler = new Cache(new CacheResource([
         'driver' => 'file',
         'path' => $dir,
         'prefix' => 'session:',
         'clock' => $clock,
      ]));

      // ! Pin the session lifetime for the test and restore it after
      $lifetime = Session::$lifetime;
      Session::$lifetime = 100;

      $id = bin2hex(random_bytes(16));
      $payload = serialize(['cart' => [1, 2, 3]]);

      // @ Write at t=1000 → expires at t=1100
      $Handler->write($id, $payload);

      $now = 1050;
      yield assert(
         assertion: $Handler->read($id) === $payload,
         description: 'Session is readable within its lifetime'
      );

      // @ touch() at t=1050 renews the TTL → new expiry at t=1150
      yield assert(
         assertion: $Handler->touch($id) === true,
         description: 'touch() succeeds on a live session'
      );

      $now = 1130;
      yield assert(
         assertion: $Handler->read($id) === $payload,
         description: 'Session renewed by touch() survives past the original expiry'
      );

      // @ Past the renewed expiry (t=1150) the entry vanishes natively
      $now = 1200;
      yield assert(
         assertion: $Handler->read($id) === false,
         description: 'Expired session reads as false (native TTL)'
      );
      yield assert(
         assertion: $Handler->touch($id) === false,
         description: 'touch() of an expired session returns false'
      );

      Session::$lifetime = $lifetime;
   }
);
