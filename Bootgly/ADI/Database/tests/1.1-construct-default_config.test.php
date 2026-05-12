<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Database\Connection;


return new Specification(
   description: 'Database: construct default PostgreSQL async config',
   test: function () {
      $Database = new SQL;

      yield assert(
         assertion: $Database->Config instanceof Config,
         description: 'Database owns ADI-native Config'
      );

      yield assert(
         assertion: $Database->Connection instanceof Connection,
         description: 'Database owns Connection state holder'
      );

      yield assert(
         assertion: $Database->Config->driver === 'pgsql',
         description: 'Default driver targets PostgreSQL'
      );

      yield assert(
         assertion: $Database->Config->port === 5432,
         description: 'Default port targets PostgreSQL'
      );

      yield assert(
         assertion: $Database->Config->secure === [
            'mode' => Config::DEFAULT_SECURE_MODE,
            'verify' => true,
            'name' => true,
            'peer' => Config::DEFAULT_HOST,
            'cafile' => Config::DEFAULT_SECURE_CAFILE,
         ] && $Database->Config->statements === Config::DEFAULT_STATEMENTS,
         description: 'Default TLS and prepared-cache config are hardened'
      );

      yield assert(
         assertion: $Database->Connection->Config === $Database->Config,
         description: 'Connection reuses Database Config instance'
      );
   }
);
