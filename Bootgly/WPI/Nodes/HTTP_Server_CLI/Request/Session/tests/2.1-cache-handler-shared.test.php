<?php

use function bin2hex;
use function function_exists;
use function random_bytes;
use function serialize;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache;


// ! The default backend (shared) requires the System V IPC extensions
$available = function_exists('shm_attach') && function_exists('sem_get');


return new Specification(
   description: 'Session Cache handler: default shared-memory backend (requires ext-sysvshm/sysvsem)',
   skip: $available === false,
   test: function () {
      // @ Default construction: driver 'shared', prefix 'session:'
      $Handler = new Cache();

      $id = bin2hex(random_bytes(16));
      $payload = serialize(['worker' => 'shared']);

      yield assert(
         assertion: $Handler->write($id, $payload) === true,
         description: 'write() persists to the shared-memory backend'
      );
      yield assert(
         assertion: $Handler->read($id) === $payload,
         description: 'read() returns the payload from shared memory'
      );

      // @ A second handler instance (≈ another worker attaching the same
      //   segment) sees the same session — the cross-worker property
      $Other = new Cache();
      yield assert(
         assertion: $Other->read($id) === $payload,
         description: 'Another handler instance reads the same session (cross-worker visibility)'
      );

      $Handler->destroy($id);
      yield assert(
         assertion: $Other->read($id) === false,
         description: 'destroy() is visible across instances'
      );
   }
);
