<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\SQLite;


return new Test(
   description: 'SQLite: a parameter matching no placeholder fails instead of writing NULL',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $insert = 'INSERT INTO accounts (email) VALUES (:email)';
      $open = function (): SQL {
         $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
         $Database->query('CREATE TABLE accounts (id INTEGER PRIMARY KEY, email TEXT)');

         return $Database;
      };
      // ! `coalesce` keeps a NULL row visible — `group_concat` alone skips it,
      //   which would make a blanked write indistinguishable from no write.
      $stored = fn (SQL $Database): mixed => $Database
         ->query("SELECT group_concat(coalesce(email, '<null>')) AS emails FROM accounts")
         ->Result?->cell;

      // # A key the compiled statement has no placeholder for
      $Database = $open();
      $Rejected = $Database->query($insert, ['emial' => 'ann@bootgly.com']);

      yield assert(
         assertion: $stored($Database) === null,
         description: 'A parameter that matches no placeholder writes nothing at all'
      );

      yield assert(
         assertion: $Rejected->finished
            && $Rejected->error !== null
            && str_contains($Rejected->error, '"emial"')
            && str_contains($Rejected->error, 'no matching placeholder'),
         description: 'The operation fails naming the key, distinctly from an unbindable type'
      );

      // # The same defect reached by renaming the placeholder, not the key
      $Renamed = $open();
      $Stale = $Renamed->query(
         'INSERT INTO accounts (email) VALUES (:identifier)',
         ['email' => 'bob@bootgly.com']
      );

      yield assert(
         assertion: $Stale->error !== null && $stored($Renamed) === null,
         description: 'A renamed placeholder fails the write instead of blanking the column'
      );

      // # Control — both accepted named shapes still bind
      $Named = $open();
      $Bare = $Named->query($insert, ['email' => 'ann@bootgly.com']);
      $Prefixed = $Named->query($insert, [':email' => 'bob@bootgly.com']);

      yield assert(
         assertion: $Bare->error === null
            && $Prefixed->error === null
            && $stored($Named) === 'ann@bootgly.com,bob@bootgly.com',
         description: 'Named parameters still bind with and without the `:` prefix'
      );

      // # Control — the positional path fails through the engine, not the guard
      $Positional = $open();
      $Extra = $Positional->query(
         'INSERT INTO accounts (email) VALUES (?1)',
         ['ann@bootgly.com', 'extra']
      );

      yield assert(
         assertion: $Extra->error !== null
            && str_contains($Extra->error, 'Unable to bind parameter number 2')
            && $stored($Positional) === null,
         description: 'Positional over-binding keeps reporting the engine bind error'
      );

      // @ The rejected bind leaves partial bindings on the cached statement —
      //   the re-arm clears them, so the next write must be untouched by it.
      $Reused = $open();
      $Reused->query($insert, ['emial' => 'lost@bootgly.com']);
      $Kept = $Reused->query($insert, ['email' => 'kept@bootgly.com']);
      $Driver = $Kept->Protocol;

      yield assert(
         assertion: $Kept->error === null
            && $stored($Reused) === 'kept@bootgly.com'
            && $Driver instanceof SQLite
            && array_keys($Driver->statements) === [$insert],
         description: 'A rejected bind leaves the cached statement clean for the next write'
      );
   }
);
