<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;
use Bootgly\API\Security\Authorization\RBAC;
use Bootgly\API\Security\JWT;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\JWT as JWTGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing\Gate;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


$JWT = new JWT('bootgly-test-secret-32-bytes-long');
$token = $JWT->sign([
   'sub' => 'demo-user',
   'exp' => time() + 60,
]);

$RBACGate = new class extends Gate {
   private null|SQL $Database = null;
   private bool $seeded = false;

   /**
    * Authorize by persisted RBAC permission grants.
    */
   public function authorize (object $Request): bool
   {
      if (getenv('BOOTGLY_RBAC_E2E') !== '1') {
         return true;
      }

      $Identity = $this->resolve($Request);
      if ($Identity === null) {
         return false;
      }

      $this->seed();
      $Database = $this->Database;
      if ($Database === null) {
         return false;
      }

      $RBAC = new RBAC(
         Database: $Database,
         roles: 'rbac_e2e_roles',
         permissions: 'rbac_e2e_permissions',
         rolePermissions: 'rbac_e2e_role_permissions',
         userRoles: 'rbac_e2e_user_roles'
      );

      return $RBAC->check($Identity, 'demo:read');
   }

   /**
    * Create isolated RBAC tables and data for this optional E2E test.
    */
   private function seed (): void
   {
      if ($this->seeded) {
         return;
      }

      $this->Database ??= new SQL([
         'driver' => 'pgsql',
         'host' => getenv('DB_HOST') ?: '127.0.0.1',
         'port' => getenv('DB_PORT') ?: 5432,
         'database' => getenv('DB_NAME') ?: 'bootgly',
         'username' => getenv('DB_USER') ?: 'postgres',
         'password' => getenv('DB_PASS') ?: '',
         'secure' => ['mode' => 'disable'],
         'pool' => ['min' => 0, 'max' => 1],
         'timeout' => 5.0,
      ]);

      $statements = [];
      if (getenv('BOOTGLY_RBAC_E2E_RESET') === '1') {
         $statements = [
            'DROP TABLE IF EXISTS rbac_e2e_user_roles',
            'DROP TABLE IF EXISTS rbac_e2e_role_permissions',
            'DROP TABLE IF EXISTS rbac_e2e_permissions',
            'DROP TABLE IF EXISTS rbac_e2e_roles',
         ];
      }

      $statements[] = 'CREATE TABLE IF NOT EXISTS rbac_e2e_roles (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE)';
      $statements[] = 'CREATE TABLE IF NOT EXISTS rbac_e2e_permissions (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE)';
      $statements[] = 'CREATE TABLE IF NOT EXISTS rbac_e2e_role_permissions (role_id INTEGER NOT NULL REFERENCES rbac_e2e_roles(id), permission_id INTEGER NOT NULL REFERENCES rbac_e2e_permissions(id), UNIQUE (role_id, permission_id))';
      $statements[] = 'CREATE TABLE IF NOT EXISTS rbac_e2e_user_roles (user_id TEXT NOT NULL, role_id INTEGER NOT NULL REFERENCES rbac_e2e_roles(id), UNIQUE (user_id, role_id))';
      $statements[] = "INSERT INTO rbac_e2e_roles (id, name) VALUES (1, 'editor') ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name";
      $statements[] = "INSERT INTO rbac_e2e_permissions (id, name) VALUES (1, 'demo:read') ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name";
      $statements[] = 'INSERT INTO rbac_e2e_role_permissions (role_id, permission_id) VALUES (1, 1) ON CONFLICT (role_id, permission_id) DO NOTHING';
      $statements[] = "INSERT INTO rbac_e2e_user_roles (user_id, role_id) VALUES ('demo-user', 1) ON CONFLICT (user_id, role_id) DO NOTHING";

      foreach ($statements as $sql) {
         $this->execute($this->Database->query($sql));
      }

      $this->seeded = true;
   }

   /**
    * Await one setup operation and fail loudly on SQL errors.
    */
   private function execute (SQLOperation $Operation): void
   {
      $Database = $this->Database;
      if ($Database === null) {
         throw new RuntimeException('RBAC E2E database is not configured.');
      }

      $Database->await($Operation);

      if ($Operation->error !== null) {
         throw new RuntimeException($Operation->error);
      }
   }
};

return new Specification(
   description: 'It should authorize a real HTTP request with persisted RBAC grants',

   request: function () use ($token) {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer {$token}\r\n\r\n";
   },
   middlewares: [
      new Authentication(new Authenticating(new JWTGuard($JWT))),
      new Authorization(new Authorizing($RBACGate))
   ],
   response: function (Request $Request, Response $Response): Response {
      if (getenv('BOOTGLY_RBAC_E2E') !== '1') {
         return $Response(body: 'rbac:e2e-disabled');
      }

      return $Response(body: 'rbac:' . $Request->identity->id);
   },

   test: function ($response) {
      if (getenv('BOOTGLY_RBAC_E2E') !== '1') {
         return (new Assertion('RBAC E2E requires BOOTGLY_RBAC_E2E=1'))->skip();
      }

      return str_contains($response, 'HTTP/1.1 200 OK')
         && str_contains($response, 'rbac:demo-user')
            ?: 'Authorization RBAC E2E did not pass the persisted permission grant';
   }
);