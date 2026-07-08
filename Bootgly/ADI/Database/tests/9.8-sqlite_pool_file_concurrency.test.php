<?php

use function extension_loaded;
use function file_exists;
use function spl_object_id;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;


return new Specification(
   description: 'SQLite: file databases share state across pooled handles',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $directory = sys_get_temp_dir();
      $file = $directory . '/bootgly-sqlite-' . uniqid() . '.db';

      try {
         $Database = new SQL([
            'driver' => 'sqlite',
            'database' => $file,
            'pool' => ['max' => 2],
         ]);

         $Database->query('CREATE TABLE shared (id INTEGER PRIMARY KEY, tag TEXT)');

         // # Second connection — a locked transaction pins the first one
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

         $Count = $Database->query('SELECT count(*) AS total FROM shared');

         yield assert(
            assertion: $Count->Result?->cell === 2 && file_exists($file),
            description: 'Writes from every handle land in the same database file'
         );
      }
      finally {
         if (file_exists($file)) {
            unlink($file);
         }
      }
   }
);
