<?php

namespace Bootgly\API\Security\Tests\AuthorizationRBAC;


use const BOOTGLY_WORKING_DIR;
use function assert;
use function count;
use function is_array;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query as SQLQuery;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;
use Bootgly\ADI\Databases\SQL\Schema;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\API\Security\Authorization\RBAC;
use Bootgly\API\Security\Identity;


class RecordingSQL extends SQL
{
   /**
    * @var array<int,array{sql:string,parameters:array<int|string,mixed>}>
    */
   public array $queries = [];
   /**
    * @var array<int,array<string,mixed>>
    */
   public array $rows;
   public bool $fail;


   /**
    * @param array<int,array<string,mixed>> $rows
    */
   public function __construct (array $rows = [], bool $fail = false)
   {
      parent::__construct(['driver' => 'sqlite', 'pool' => ['min' => 0, 'max' => 0]]);
      // ! Zero-size pool keeps the real SQL constructor connection-free for this double.

      // * Data
      $this->rows = $rows;
      $this->fail = $fail;
   }

   /**
    * @param string|Builder|SQLQuery $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|SQLQuery $query, array $parameters = [], null|object $Scope = null): SQLOperation
   {
      $Normalized = new Normalized($query, $parameters);
      $Operation = new SQLOperation(null, $Normalized->sql, $Normalized->parameters, $this->Config->timeout);

      $this->queries[] = [
         'sql'        => $Operation->sql,
         'parameters' => $Operation->parameters,
      ];

      if ($this->fail) {
         return $Operation->fail('RBAC database failure.');
      }

      $Operation->rows = $this->rows;
      $Operation->resolve(new Result('SELECT', rows: $this->rows));

      return $Operation;
   }

   public function await (SQLOperation $Operation): SQLOperation
   {
      return $Operation;
   }
}


return new Specification(
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

      yield assert(
         assertion: $RBAC->check($Identity, 'demo:read') === false,
         description: 'RBAC resolver fails closed on database errors'
      );

      $migrations = BOOTGLY_WORKING_DIR . 'projects/Demo/HTTP_Server_CLI/database/migrations';
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
               && str_contains($Up[0]->sql, "\"{$table}\""),
            description: "demo RBAC migration compiles {$table} DDL"
         );
      }

      $Seeder = require BOOTGLY_WORKING_DIR . 'projects/Demo/HTTP_Server_CLI/database/seeders/authorization_rbac.php';
      $Queries = $Seeder->run(new RecordingSQL, new Seed);
      $sql = [];

      foreach ($Queries as $Query) {
         $Normalized = new Normalized($Query);
         $sql[] = $Normalized->sql;
      }

      yield assert(
         assertion: count($sql) === 4
            && str_contains($sql[0], 'INSERT INTO "roles"')
            && str_contains($sql[2], 'ON CONFLICT ("role_id", "permission_id")'),
         description: 'demo RBAC seeder compiles rerunnable role-permission inserts'
      );
   }
);