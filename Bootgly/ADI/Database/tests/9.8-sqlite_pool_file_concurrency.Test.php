<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'SQLite: file databases share state across pooled handles',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $directory = sys_get_temp_dir();
      $file = $directory . '/bootgly-sqlite-' . uniqid() . '.db';

      try {
         $Database = new SQL([
            'driver' => 'sqlite',
            'database' => $file,
            'timeout' => 1.0,
            'pool' => ['max' => 2],
         ]);

         // ? A file is one database whichever handle opens it, so the pool keeps
         //   every connection it was given — unlike `:memory:`, which is private
         //   to its handle and is confined to one.
         yield assert(
            assertion: $Database->Config->pool['max'] === 2,
            description: 'A file database keeps the pool it was configured with'
         );

         $Database->query('CREATE TABLE shared (id INTEGER PRIMARY KEY, tag TEXT)');
         $Database->query("INSERT INTO shared (tag) VALUES ('committed')");

         // # A transaction pins the first connection, so the next query takes a second
         $Transaction = $Database->begin();
         $Pinned = $Transaction->query("INSERT INTO shared (tag) VALUES ('inside')");

         $Outside = $Database->query('SELECT count(*) AS total FROM shared');

         yield assert(
            assertion: $Pinned->Connection !== null && $Outside->Connection !== null
               && spl_object_id($Pinned->Connection) !== spl_object_id($Outside->Connection)
               && $Database->Pool->created === 2,
            description: 'A second handle serves the query the transaction cannot, found: '
               . $Database->Pool->created
         );

         // ! The second handle reads the same file, and sees only what is committed —
         //   one row, not the two the transaction is holding.
         yield assert(
            assertion: $Outside->error === null && $Outside->Result?->cell === 1,
            description: 'The second handle reads the shared file at its committed state, found: '
               . json_encode($Outside->error ?? $Outside->Result?->cell)
         );

         $Transaction->commit();

         $Count = $Database->query('SELECT count(*) AS total FROM shared');

         yield assert(
            assertion: $Pinned->error === null && $Count->Result?->cell === 2 && file_exists($file),
            description: 'Every write lands in the one database file'
         );
      }
      finally {
         if (file_exists($file)) {
            unlink($file);
         }
      }
   }
);
