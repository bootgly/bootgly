<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Config as ADIConfig;
use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\DatabaseConfig;


return new Test(
   description: 'Configs: database adapter rejects a selected driver without a declared connection scope',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $previousDirectory = getcwd();
      if ($previousDirectory === false) {
         throw new RuntimeException('ENV-1 fixture could not resolve the current directory.');
      }

      $previousDriver = getenv('DB_CONNECTION');
      $directory = sys_get_temp_dir() . '/bootgly-env1-' . bin2hex(random_bytes(8));
      $inventedPath = $directory . '/bootgly';
      $declaredPath = $directory . '/declared.sqlite';

      if (mkdir($directory, 0700) === false) {
         throw new RuntimeException('ENV-1 fixture could not create its temporary directory.');
      }

      $rejection = null;
      $fabricated = false;
      $inventedRow = null;
      $control = false;
      $controlRow = null;
      $selectedPreserved = false;
      $WrongConfig = null;
      $WrongDatabase = null;
      $ControlDatabase = null;

      try {
         putenv('DB_CONNECTION');
         if (chdir($directory) === false) {
            throw new RuntimeException('ENV-1 fixture could not enter its temporary directory.');
         }

         // ! ENV-1 source: the selected SQLite driver has no declared subtree.
         //   PostgreSQL values exist specifically to prove that they are not
         //   the endpoint the adapter eventually uses.
         $Missing = new Config(scope: 'database');
         $Missing->Default->bind(key: 'DB_CONNECTION', default: 'sqlite');
         $Missing->Connections->PostgreSQL->Driver->bind(default: 'pgsql');
         $Missing->Connections->PostgreSQL->Host->bind(default: 'declared.invalid');
         $Missing->Connections->PostgreSQL->Database->bind(default: 'declared_database');
         $MissingConnections = $Missing->Connections;
         $selectedBefore = array_keys(iterator_to_array($MissingConnections->walk()));

         try {
            $WrongConfig = (new DatabaseConfig($Missing))->configure();
         }
         catch (InvalidArgumentException $Exception) {
            $rejection = $Exception->getMessage();
         }

         $selectedAfter = array_keys(iterator_to_array($MissingConnections->walk()));
         $selectedPreserved = $Missing->check('Connections')
            && $MissingConnections->check('SQLite') === false
            && $selectedBefore === ['PostgreSQL']
            && $selectedAfter === $selectedBefore;

         if ($rejection === null && $WrongConfig !== null) {
            $fabricated = $WrongConfig->driver === 'sqlite'
               && $WrongConfig->host === ADIConfig::DEFAULT_HOST
               && $WrongConfig->port === ADIConfig::DEFAULT_PORT
               && $WrongConfig->database === ADIConfig::DEFAULT_DATABASE
               && $WrongConfig->username === ADIConfig::DEFAULT_USERNAME
               && $WrongConfig->password === ADIConfig::DEFAULT_PASSWORD
               && $WrongConfig->timeout === ADIConfig::DEFAULT_TIMEOUT
               && $WrongConfig->secure === [
                  'mode' => ADIConfig::DEFAULT_SECURE_MODE,
                  'verify' => true,
                  'name' => true,
                  'peer' => ADIConfig::DEFAULT_HOST,
                  'cafile' => ADIConfig::DEFAULT_SECURE_CAFILE,
                  'key' => ADIConfig::DEFAULT_SECURE_KEY,
               ]
               && $WrongConfig->pool === [
                  'min' => ADIConfig::DEFAULT_POOL_MIN,
                  'max' => ADIConfig::DEFAULT_POOL_MAX,
               ];

            // ! ENV-1 sink: ADI accepts the fabricated config and commits to
            //   the default relative database name in the process CWD.
            $WrongDatabase = new SQL($WrongConfig);
            $WrongDatabase->await($WrongDatabase->query(
               'CREATE TABLE env1_proof (id INTEGER PRIMARY KEY, note TEXT NOT NULL)'
            ));
            $WrongDatabase->await($WrongDatabase->query(
               "INSERT INTO env1_proof (id, note) VALUES (1, 'invented-endpoint')"
            ));

            $Observer = new SQLite3($inventedPath, SQLITE3_OPEN_READONLY);
            $Result = $Observer->query('SELECT id, note FROM env1_proof WHERE id = 1');
            $inventedRow = $Result === false ? null : $Result->fetchArray(SQLITE3_ASSOC);
            if ($Result !== false) {
               $Result->finalize();
            }
            $Observer->close();
         }

         // @ Negative control: declaring the selected subtree must preserve
         //   its explicit database path and remain executable end to end.
         $Declared = new Config(scope: 'database');
         $Declared->Default->bind(default: 'sqlite');
         $Declared->Connections->SQLite->Driver->bind(default: 'sqlite');
         $Declared->Connections->SQLite->Database->bind(default: $declaredPath);
         $Declared->Connections->SQLite->Pool->Min->bind(default: 0);
         $Declared->Connections->SQLite->Pool->Max->bind(default: 1);

         $DeclaredConfig = (new DatabaseConfig($Declared))->configure();
         $ControlDatabase = new SQL($DeclaredConfig);
         $ControlDatabase->await($ControlDatabase->query(
            'CREATE TABLE env1_control (id INTEGER PRIMARY KEY, note TEXT NOT NULL)'
         ));
         $ControlDatabase->await($ControlDatabase->query(
            "INSERT INTO env1_control (id, note) VALUES (1, 'declared-endpoint')"
         ));

         $Observer = new SQLite3($declaredPath, SQLITE3_OPEN_READONLY);
         $Result = $Observer->query('SELECT id, note FROM env1_control WHERE id = 1');
         $controlRow = $Result === false ? null : $Result->fetchArray(SQLITE3_ASSOC);
         if ($Result !== false) {
            $Result->finalize();
         }
         $Observer->close();

         $control = $DeclaredConfig->driver === 'sqlite'
            && $DeclaredConfig->database === $declaredPath
            && $controlRow === [
               'id' => 1,
               'note' => 'declared-endpoint',
            ];
      }
      finally {
         $WrongDatabase = null;
         $ControlDatabase = null;

         chdir($previousDirectory);

         if ($previousDriver === false) {
            putenv('DB_CONNECTION');
         }
         else {
            putenv('DB_CONNECTION=' . $previousDriver);
         }

         @unlink($inventedPath);
         @unlink($inventedPath . '-journal');
         @unlink($inventedPath . '-shm');
         @unlink($inventedPath . '-wal');
         @unlink($declaredPath);
         @unlink($declaredPath . '-journal');
         @unlink($declaredPath . '-shm');
         @unlink($declaredPath . '-wal');

         if (is_dir($directory)) {
            @rmdir($directory);
         }
      }

      $expected = 'Database config is missing the selected connection scope: Connections->SQLite.';
      $aliases = [
         'sqlite3' => ['SQLite', 'sqlite'],
         'mariadb' => ['MySQL', 'mysql'],
         'postgres' => ['PostgreSQL', 'pgsql'],
      ];
      $aliasChecks = [];

      foreach ($aliases as $alias => [$name, $driver]) {
         // @ A missing root must stay missing, and the diagnostic names the
         //   canonical connection child selected by each public alias.
         $AliasMissing = new Config(scope: 'database');
         $AliasMissing->Default->bind(default: $alias);
         $rootBefore = array_keys(iterator_to_array($AliasMissing->walk()));
         $message = null;

         try {
            (new DatabaseConfig($AliasMissing))->configure();
         }
         catch (InvalidArgumentException $Exception) {
            $message = $Exception->getMessage();
         }

         $rootAfter = array_keys(iterator_to_array($AliasMissing->walk()));

         // @ The same aliases must resolve successfully when their canonical
         //   subtrees are explicitly declared.
         $AliasDeclared = new Config(scope: 'database');
         $AliasDeclared->Default->bind(default: $alias);
         $AliasConnection = $AliasDeclared->Connections->{$name};
         $AliasConnection->Driver->bind(default: $driver);

         if ($driver === 'sqlite') {
            $AliasConnection->Database->bind(default: ':memory:');
         }
         else {
            $AliasConnection->Host->bind(default: "{$alias}.declared.invalid");
         }

         $AliasConfig = (new DatabaseConfig($AliasDeclared))->configure();
         $aliasChecks[$alias] = $message
               === "Database config is missing the selected connection scope: Connections->{$name}."
            && $AliasMissing->check('Connections') === false
            && $rootBefore === ['Default']
            && $rootAfter === $rootBefore
            && $AliasConfig->driver === $driver
            && ($driver === 'sqlite'
               ? $AliasConfig->database === ':memory:'
               : $AliasConfig->host === "{$alias}.declared.invalid");
      }

      // @ A selected subtree may intentionally provide only some fields.
      $Partial = new Config(scope: 'database');
      $Partial->Default->bind(default: 'sqlite');
      $Partial->Connections->SQLite->Database->bind(default: '/tmp/bootgly-env1-partial.sqlite');
      $PartialConfig = (new DatabaseConfig($Partial))->configure();

      // @ Omitting Default remains valid when the default PostgreSQL subtree
      //   exists; the adapter must not confuse an absent value with a missing
      //   selected connection subtree.
      $NoDefault = new Config(scope: 'database');
      $NoDefault->Connections->PostgreSQL->Host->bind(default: 'postgres.declared.invalid');
      $NoDefaultConfig = (new DatabaseConfig($NoDefault))->configure();

      yield assert(
         assertion: $control,
         description: 'The declared SQLite scope preserves its configured path and writes through ADI'
      );

      yield assert(
         assertion: $rejection !== null || ($fabricated && $inventedRow === [
            'id' => 1,
            'note' => 'invented-endpoint',
         ]),
         description: 'ENV-1 fixture must either reject the missing scope or prove the fabricated endpoint end to end'
      );

      yield assert(
         assertion: $rejection === $expected,
         description: 'ENV-1 CONFIRMED: missing selected scope did not raise the exact diagnostic, got: '
            . var_export($rejection, true)
      );

      yield assert(
         assertion: $selectedPreserved,
         description: 'Rejecting an absent selected child does not create or reorder connection scopes'
      );

      yield assert(
         assertion: $aliasChecks === [
            'sqlite3' => true,
            'mariadb' => true,
            'postgres' => true,
         ],
         description: 'Driver aliases use canonical scope names without creating a missing Connections root: '
            . json_encode($aliasChecks)
      );

      yield assert(
         assertion: $PartialConfig->driver === 'sqlite'
            && $PartialConfig->database === '/tmp/bootgly-env1-partial.sqlite'
            && $NoDefaultConfig->driver === 'pgsql'
            && $NoDefaultConfig->host === 'postgres.declared.invalid',
         description: 'Partial selected scopes and a declared PostgreSQL scope without Default remain valid'
      );

      yield assert(
         assertion: $rejection === $expected && $selectedPreserved,
         description: 'ENV-1 CONFIRMED: selecting an undeclared SQLite scope must fail closed '
            . 'without fabricating the missing child'
      );
   }
);
