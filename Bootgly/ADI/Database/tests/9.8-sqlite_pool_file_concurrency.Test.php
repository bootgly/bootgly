<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'SQLite: a file database is confined to one pooled handle, and one is enough',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $directory = sys_get_temp_dir();
      $file = $directory . '/bootgly-sqlite-' . uniqid() . '.db';

      try {
         // ! Two connections are asked for on purpose: two handles on one file do not
         //   share a transaction, they contend for its lock.
         $Database = new SQL([
            'driver' => 'sqlite',
            'database' => $file,
            'pool' => ['max' => 2],
         ]);

         yield assert(
            assertion: $Database->Config->pool['max'] === 1,
            description: 'A file database pool is confined to one connection, whatever it was asked for'
         );

         $Database->query('CREATE TABLE shared (id INTEGER PRIMARY KEY, tag TEXT)');

         // # The transaction takes the one connection and gives it back on commit
         $Transaction = $Database->begin();
         $Pinned = $Transaction->query("INSERT INTO shared (tag) VALUES ('inside')");
         $Transaction->commit();

         $Outside = $Database->query("INSERT INTO shared (tag) VALUES ('outside')");

         yield assert(
            assertion: $Pinned->error === null && $Outside->error === null,
            description: 'Transaction and pool queries both write to the file database'
         );

         yield assert(
            assertion: $Pinned->Connection !== null && $Outside->Connection !== null
               && spl_object_id($Pinned->Connection) === spl_object_id($Outside->Connection),
            description: 'Released transaction connections are reused by later queries'
         );

         yield assert(
            assertion: $Database->Pool->created === 1,
            description: 'The pool never opens a second handle on the file, found: '
               . $Database->Pool->created
         );

         $Count = $Database->query('SELECT count(*) AS total FROM shared');

         yield assert(
            assertion: $Count->Result?->cell === 2 && file_exists($file),
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
