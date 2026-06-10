<?php

use function bin2hex;
use function random_bytes;
use function serialize;
use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Resources\Cache as CacheResource;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache;


return new Specification(
   description: 'Session Cache handler: write/read/destroy round-trip + session ID hygiene',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-session-test-' . uniqid();
      $Handler = new Cache(new CacheResource([
         'driver' => 'file',
         'path' => $dir,
         'prefix' => 'session:',
      ]));

      $id = bin2hex(random_bytes(16));
      $payload = serialize(['user' => 7, 'role' => 'admin']);

      // @ Write + read round-trip
      yield assert(
         assertion: $Handler->write($id, $payload) === true,
         description: 'write() persists session data'
      );
      yield assert(
         assertion: $Handler->read($id) === $payload,
         description: 'read() returns the exact written payload'
      );

      // @ Missing session
      yield assert(
         assertion: $Handler->read(bin2hex(random_bytes(16))) === false,
         description: 'read() of an unknown ID returns false'
      );

      // @ Destroy
      yield assert(
         assertion: $Handler->destroy($id) === true && $Handler->read($id) === false,
         description: 'destroy() removes the session'
      );

      // @ Session ID hygiene (mirror of the File handler path-traversal guard)
      yield assert(
         assertion: $Handler->write('../../etc/passwd', $payload) === false,
         description: 'write() rejects a non-hex session ID'
      );
      yield assert(
         assertion: $Handler->read('UPPERCASE-and-short') === false,
         description: 'read() rejects a non-hex session ID'
      );
      yield assert(
         assertion: $Handler->destroy('bogus') === true,
         description: 'destroy() of an invalid ID is a no-op success'
      );
      yield assert(
         assertion: $Handler->touch('bogus') === false,
         description: 'touch() rejects an invalid ID'
      );
   }
);
