<?php

namespace Bootgly\API\Security\Tests\AuthorizationRBAC;


use function assert;
use function count;
use function is_array;
use function is_dir;
use function str_contains;
use RuntimeException;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query as SQLQuery;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;
use Bootgly\ADI\Databases\SQL\Schema;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\API\Projects;
use Bootgly\API\Security\Authorization\RBAC;
use Bootgly\API\Security\Identity;


class RecordingSQL extends SQL
{
   /**
    * @var array<int,array{sql:string,parameters:array<int|string,mixed>,reading:bool,Scope:null|object}>
    */
   public array $queries = [];
   /**
    * @var array<int,array<string,mixed>>
    */
   public array $rows;
   /**
    * The failure is recorded when the query is created.
    */
   public bool $fail;
   /**
    * The failure is recorded while the query is awaited.
    */
   public bool $late;


   /**
    * @param array<int,array<string,mixed>> $rows
    */
   public function __construct (array $rows = [], bool $fail = false, bool $late = false)
   {
      parent::__construct(['driver' => 'sqlite', 'pool' => ['min' => 0, 'max' => 0]]);
      // ! Zero-size pool keeps the real SQL constructor connection-free for this double.

      // * Data
      $this->rows = $rows;
      $this->fail = $fail;
      $this->late = $late;
   }

   /**
    * @param string|Builder|SQLQuery $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|SQLQuery $query, array $parameters = [], null|object $Scope = null): SQLOperation
   {
      $Normalized = new Normalized($query, $parameters);
      $Operation = new SQLOperation(null, $Normalized->SQL, $Normalized->parameters, $this->Config->timeout);

      $this->queries[] = [
         'sql'        => $Operation->SQL,
         'parameters' => $Operation->parameters,
         'reading'    => $Normalized->reading,
         'Scope'      => $Scope,
      ];

      if ($this->fail) {
         return $Operation->fail('RBAC database failure.');
      }
      // ? Still running: the failure lands while it is awaited
      if ($this->late) {
         return $Operation;
      }

      $Operation->rows = $this->rows;
      $Operation->resolve(new Result('SELECT', rows: $this->rows));

      return $Operation;
   }

   public function await (SQLOperation $Operation): SQLOperation
   {
      if ($this->late && $Operation->finished === false) {
         $Operation->fail('RBAC database failure while awaited.');
      }

      // ? Like Pool::wait(): a failed operation throws
      if ($Operation->error !== null) {
         throw new RuntimeException($Operation->error);
      }

      return $Operation;
   }
}


return new Test(
   description: 'Authorization: resolve persisted RBAC permissions and demo SQL files',
   test: function () {
      $Identity = new Identity(id: 'demo-user', scopes: ['token:scope']);

      $Database = new RecordingSQL(rows: [['allowed' => 1]]);
      $RBAC = new RBAC($Database);

      yield assert(
         assertion: $RBAC->check($Identity, 'demo:read') === true,
         description: 'RBAC resolver allows persisted role permission'
      );

      yield assert(
         assertion: str_contains($Database->queries[0]['sql'], 'COUNT(*) AS "allowed"')
            && str_contains($Database->queries[0]['sql'], '"role_permissions"')
            && $Database->queries[0]['parameters'] === ['demo-user', 'demo:read'],
         description: 'RBAC resolver compiles a joined role-permission lookup'
      );

      // ! Replica lag never decides an authorization: the lookup reaches the
      //   SQL facade write-classified, under a one-use scope
      $Scope = $Database->queries[0]['Scope'];
      $RBAC->check($Identity, 'demo:write');
      yield assert(
         assertion: $Database->queries[0]['reading'] === false
            && $Scope !== null
            && $Database->queries[1]['reading'] === false
            && $Database->queries[1]['Scope'] !== null
            && $Database->queries[1]['Scope'] !== $Scope,
         description: 'RBAC lookups are routed to the primary (write-classified) with a fresh scope each'
      );

      $Database = new RecordingSQL(rows: [['allowed' => 0]]);
      $RBAC = new RBAC($Database);

      yield assert(
         assertion: $RBAC->check($Identity, 'posts:delete') === false,
         description: 'RBAC resolver denies missing persisted permission'
      );

      $Database = new RecordingSQL(rows: [
         ['name' => 'demo:read'],
         ['name' => 'demo:read'],
         ['name' => 'demo:write'],
         ['name' => ''],
      ]);
      $RBAC = new RBAC($Database);
      $Loaded = $RBAC->load($Identity);

      yield assert(
         assertion: $Loaded === $Identity
            && $Identity->scopes === ['token:scope', 'demo:read', 'demo:write'],
         description: 'RBAC resolver loads persisted permissions into identity scopes once'
      );

      $Database = new RecordingSQL(fail: true);
      $RBAC = new RBAC($Database);
      $Untouched = new Identity(id: 'demo-user', scopes: ['token:scope']);

      yield assert(
         assertion: $RBAC->check($Identity, 'demo:read') === false
            && $RBAC->load($Untouched) === $Untouched
            && $Untouched->scopes === ['token:scope'],
         description: 'RBAC resolver fails closed on database errors (check() denies, load() keeps the identity)'
      );

      $Database = new RecordingSQL(late: true);
      $RBAC = new RBAC($Database);

      yield assert(
         assertion: $RBAC->check($Identity, 'demo:read') === false
            && $RBAC->load($Untouched) === $Untouched
            && $Untouched->scopes === ['token:scope']
            && count($Database->queries) === 2,
         description: 'RBAC resolver fails closed on a database error thrown while the query is awaited'
      );

      // ! The fixture ships on the framework's own shelf — the consumer's
      //   projects/ may legitimately lack it (an example the user deleted
      //   stays deleted, and an updated kit never restocks it).
      $migrations = Projects::AUTHOR_DIR . 'Demo/HTTP_Server_CLI/database/migrations';
      if (is_dir($migrations) === false) {
         yield (new Assertion(description: 'demo RBAC fixture is on the author shelf'))->skip();
         return;
      }

      $Schema = new Schema;
      $files = [
         'roles' => '20260520000000_create_roles.php',
         'permissions' => '20260520000100_create_permissions.php',
         'role_permissions' => '20260520000200_create_role_permissions.php',
         'user_roles' => '20260520000300_create_user_roles.php',
      ];
      $Normalize = static function (mixed $queries): array {
         return is_array($queries) ? $queries : [$queries];
      };

      foreach ($files as $table => $file) {
         $Migration = require "{$migrations}/{$file}";
         $Up = $Normalize($Migration->up($Schema));
         $Down = $Normalize($Migration->down($Schema));

         yield assert(
            assertion: $Up !== []
               && $Down !== []
               && str_contains($Up[0]->SQL, "\"{$table}\""),
            description: "demo RBAC migration compiles {$table} DDL"
         );
      }

      $Seeder = require Projects::AUTHOR_DIR . 'Demo/HTTP_Server_CLI/database/seeders/authorization_rbac.php';
      $Queries = $Seeder->run(new RecordingSQL, new Seed);
      $sql = [];

      foreach ($Queries as $Query) {
         $Normalized = new Normalized($Query);
         $sql[] = $Normalized->SQL;
      }

      yield assert(
         assertion: count($sql) === 4
            && str_contains($sql[0], 'INSERT INTO "roles"')
            && str_contains($sql[2], 'ON CONFLICT ("role_id", "permission_id")'),
         description: 'demo RBAC seeder compiles rerunnable role-permission inserts'
      );
   }
);