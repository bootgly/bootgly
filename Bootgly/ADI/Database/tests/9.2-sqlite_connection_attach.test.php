<?php

use function extension_loaded;
use function is_resource;
use function spl_object_id;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Connection\ConnectionStates;
use Bootgly\ADI\Databases\SQL;


return new Specification(
   description: 'SQLite: placeholder stream satisfies the pool connection contract',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:', 'pool' => ['max' => 1]]);

      $First = $Database->query('CREATE TABLE pool_probe (id INTEGER PRIMARY KEY, name TEXT)');

      yield assert(
         assertion: $First->finished && $First->error === null,
         description: 'Synchronous operation resolves inside the pool assignment'
      );

      $Connection = $First->Connection;

      yield assert(
         assertion: $Connection !== null
            && is_resource($Connection->socket)
            && $Connection->state === ConnectionStates::Ready,
         description: 'Connection carries a live placeholder stream in Ready state'
      );

      yield assert(
         assertion: $First->Readiness === null,
         description: 'Synchronous operations never expose Readiness'
      );

      $Second = $Database->query("INSERT INTO pool_probe (name) VALUES ('bootgly')");

      yield assert(
         assertion: $Second->Connection !== null
            && $Connection !== null
            && spl_object_id($Second->Connection) === spl_object_id($Connection),
         description: 'Pool reuses the same connection for the next operation'
      );

      yield assert(
         assertion: $Second->error === null && $Second->Result?->affected === 1,
         description: 'The `:memory:` database persists across pooled operations'
      );

      yield assert(
         assertion: $Database->Pool->created === 1 && $Database->Pool->busy === [],
         description: 'Pool bookkeeping tracks one idle reusable connection'
      );
   }
);
