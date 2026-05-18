<?php

namespace projects\Bootgly\WPI;


use function count;
use Throwable;

use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Orders;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Builder\Query as BuilderQuery;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database as DatabaseResource;


return static function
(Request $Request, Response $Response, Router $Router)
{
   $DatabaseResource = static fn (Response $Response): DatabaseResource => $Response->Database;

   $Rows = static function (Operation $Operation): array {
      $Result = $Operation->Result;

      return $Result === null ? [] : $Result->rows;
   };

   $Query = static function (Response $Response, string|Builder|BuilderQuery $query, array $parameters, callable $Render) use ($DatabaseResource): mixed {
      return $Response->defer(function (Response $Response) use ($query, $parameters, $Render, $DatabaseResource): void {
         try {
            $Database = $DatabaseResource($Response);
            $Operation = $Database->query($query, $parameters);
            $Render($Response, $Operation);
         }
         catch (Throwable $Throwable) {
            $Response->code(500)->JSON->send([
               'status' => 'exception',
               'message' => $Throwable->getMessage(),
            ]);
         }
      });
   };

   $Respond = static function (Response $Response, Operation $Operation, array $rows, null|string $hint = null): void {
      $Response->code($Operation->error === null ? 200 : 500)->JSON->send([
         'status' => $Operation->error === null ? 'ok' : 'error',
         'message' => $Operation->error,
         'hint' => $Operation->error === null ? null : $hint,
         'rows' => $rows,
      ]);
   };

   // @ Database demo index
   yield $Router->route('/', function (Request $Request, Response $Response) {
      return $Response->code(200)->JSON->send([
         'status' => 'ok',
         'routes' => [
            '/deferred/database/setup',
            '/deferred/database/users',
            '/deferred/database/user',
            '/deferred/database/config',
            '/deferred/database/ping',
            '/deferred/database/parameters',
            '/deferred/database/types',
            '/deferred/database/error',
            '/deferred/database/pool',
            '/deferred/database/sleep',
         ],
      ]);
   }, GET);

   // @ PostgreSQL effective config
   yield $Router->route('/deferred/database/config', function (Request $Request, Response $Response) use ($DatabaseResource) {
      return $Response->defer(function (Response $Response) use ($DatabaseResource): void {
         try {
            $Database = $DatabaseResource($Response);
            $Config = $Database->Database->SQLConfig;

            $Response->code(200)->JSON->send([
               'status' => 'ok',
               'driver' => $Config->driver,
               'host' => $Config->host,
               'port' => $Config->port,
               'database' => $Config->database,
               'username' => $Config->username,
               'password' => $Config->password === '' ? 'empty' : 'set',
               'timeout' => $Config->timeout,
               'statements' => $Config->statements,
               'secure' => $Config->secure,
               'pool' => $Config->pool,
            ]);
         }
         catch (Throwable $Throwable) {
            $Response->code(500)->JSON->send([
               'status' => 'exception',
               'message' => $Throwable->getMessage(),
            ]);
         }
      });
   }, GET);

   // @ Base PostgreSQL async query
   yield $Router->route('/deferred/database', function (Request $Request, Response $Response) use ($DatabaseResource) {
      return $Response->defer(function (Response $Response) use ($DatabaseResource): void {
         try {
            $Database = $DatabaseResource($Response);
            $Operation = $Database->query('SELECT $1::int AS value', [42]);

            if ($Operation->error !== null) {
               $Response->code(500)->JSON->send([
                  'status' => 'error',
                  'message' => $Operation->error,
               ]);

               return;
            }

            $Result = $Operation->Result;
            $Response->code(200)->JSON->send([
               'status' => $Result === null ? 'empty' : $Result->status,
               'rows' => $Result === null ? [] : $Result->rows,
               'affected' => $Result === null ? 0 : $Result->affected,
            ]);
         }
         catch (Throwable $Throwable) {
            $Response->code(500)->JSON->send([
               'status' => 'exception',
               'message' => $Throwable->getMessage(),
            ]);
         }
      });
   }, GET);

   // @ PostgreSQL connection ping
   yield $Router->route('/deferred/database/ping', function (Request $Request, Response $Response) use ($Query, $Respond, $Rows) {
      return $Query($Response, 'SELECT 1 AS ok', [], function (Response $Response, Operation $Operation) use ($Respond, $Rows): void {
         $Respond($Response, $Operation, $Rows($Operation), null);
      });
   }, GET);

   // @ PostgreSQL parameterized query
   yield $Router->route('/deferred/database/parameters', function (Request $Request, Response $Response) use ($Query, $Respond, $Rows) {
      return $Query($Response, 'SELECT $1::int AS value, $2::text AS label', [42, 'bootgly'], function (Response $Response, Operation $Operation) use ($Respond, $Rows): void {
         $Respond($Response, $Operation, $Rows($Operation), null);
      });
   }, GET);

   // @ PostgreSQL scalar type conversion demo
   yield $Router->route('/deferred/database/types', function (Request $Request, Response $Response) use ($Query, $Respond, $Rows) {
      return $Query($Response, 'SELECT $1::bool AS enabled, $2::int AS count, $3::float8 AS ratio, $4::numeric AS amount', [true, 7, 1.5, '123.45'], function (Response $Response, Operation $Operation) use ($Respond, $Rows): void {
         $Respond($Response, $Operation, $Rows($Operation), null);
      });
   }, GET);

   // @ PostgreSQL demo schema and seed data
   yield $Router->route('/deferred/database/setup', function (Request $Request, Response $Response) use ($DatabaseResource) {
      return $Response->defer(function (Response $Response) use ($DatabaseResource): void {
         try {
            $Database = $DatabaseResource($Response);

            $Statements = [
               'CREATE TABLE IF NOT EXISTS bootgly_demo_users (id integer PRIMARY KEY, name text NOT NULL, email text NOT NULL UNIQUE, active boolean NOT NULL DEFAULT true, score numeric(10,2) NOT NULL DEFAULT 0, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP)',
               "INSERT INTO bootgly_demo_users (id, name, email, active, score) VALUES (1, 'Ada Lovelace', 'ada@example.test', true, 98.50), (2, 'Grace Hopper', 'grace@example.test', true, 96.75), (3, 'Alan Turing', 'alan@example.test', false, 99.99) ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, email = EXCLUDED.email, active = EXCLUDED.active, score = EXCLUDED.score",
            ];
            $results = [];

            foreach ($Statements as $sql) {
               $Operation = $Database->query($sql);

               if ($Operation->error !== null) {
                  $Response->code(500)->JSON->send([
                     'status' => 'error',
                     'message' => $Operation->error,
                  ]);

                  return;
               }

               $results[] = $Operation->Result === null ? 'ok' : $Operation->Result->status;
            }

            $Response->code(200)->JSON->send([
               'status' => 'ok',
               'message' => 'Demo table bootgly_demo_users is ready.',
               'commands' => $results,
            ]);
         }
         catch (Throwable $Throwable) {
            $Response->code(500)->JSON->send([
               'status' => 'exception',
               'message' => $Throwable->getMessage(),
            ]);
         }
      });
   }, GET);

   // @ PostgreSQL demo users list
   yield $Router->route('/deferred/database/users', function (Request $Request, Response $Response) use ($DatabaseResource, $Respond, $Rows) {
      return $Response->defer(function (Response $Response) use ($DatabaseResource, $Respond, $Rows): void {
         try {
            $Database = $DatabaseResource($Response);

            $Query = $Database
               ->table(new Identifier('bootgly_demo_users'))
               ->select(
                  new Identifier('id'),
                  new Identifier('name'),
                  new Identifier('email'),
                  new Identifier('active'),
                  new Identifier('score'),
                  new Identifier('created_at')
               )
               ->order(Orders::Asc, new Identifier('id'));

            $Operation = $Database->query(
               $Query
            );
            $Respond($Response, $Operation, $Rows($Operation), 'Call /deferred/database/setup first.');
         }
         catch (Throwable $Throwable) {
            $Response->code(500)->JSON->send([
               'status' => 'exception',
               'message' => $Throwable->getMessage(),
            ]);
         }
      });
   }, GET);

   // @ PostgreSQL demo user by parameter
   yield $Router->route('/deferred/database/user', function (Request $Request, Response $Response) use ($DatabaseResource, $Respond, $Rows) {
      return $Response->defer(function (Response $Response) use ($DatabaseResource, $Respond, $Rows): void {
         try {
            $Database = $DatabaseResource($Response);

            $Operation = $Database->query(
               $Database
                  ->table(new Identifier('bootgly_demo_users'))
                  ->select(
                     new Identifier('id'),
                     new Identifier('name'),
                     new Identifier('email'),
                     new Identifier('active'),
                     new Identifier('score'),
                     new Identifier('created_at')
                  )
                  ->filter(new Identifier('id'), Operators::Equal, 1)
            );
            $Respond($Response, $Operation, $Rows($Operation), 'Call /deferred/database/setup first.');
         }
         catch (Throwable $Throwable) {
            $Response->code(500)->JSON->send([
               'status' => 'exception',
               'message' => $Throwable->getMessage(),
            ]);
         }
      });
   }, GET);

   // @ PostgreSQL recoverable error demo
   yield $Router->route('/deferred/database/error', function (Request $Request, Response $Response) use ($Query) {
      return $Query($Response, 'SELECT missing_column FROM missing_table', [], function (Response $Response, Operation $Operation): void {
         $Response->code($Operation->error === null ? 200 : 500)->JSON->send([
            'status' => $Operation->error === null ? 'unexpected-ok' : 'expected-error',
            'message' => $Operation->error,
         ]);
      });
   }, GET);

   // @ PostgreSQL resource demo with multiple operations
   yield $Router->route('/deferred/database/pool', function (Request $Request, Response $Response) use ($DatabaseResource) {
      return $Response->defer(function (Response $Response) use ($DatabaseResource): void {
         try {
            $Database = $DatabaseResource($Response);

            $Operations = [
               $Database->query('SELECT $1::int AS value', [1]),
               $Database->query('SELECT $1::int AS value', [2]),
               $Database->query('SELECT $1::int AS value', [3]),
            ];

            $rows = [];
            $errors = [];

            foreach ($Operations as $Operation) {
               if ($Operation->error !== null) {
                  $errors[] = $Operation->error;
               }

               if ($Operation->Result !== null) {
                  $rows[] = $Operation->Result->row;
               }
            }

            $Response->code($errors === [] ? 200 : 500)->JSON->send([
               'status' => $errors === [] ? 'ok' : 'error',
               'rows' => $rows,
               'errors' => $errors,
               'pool' => [
                  'idle' => count($Database->Database->Pool->idle),
                  'busy' => count($Database->Database->Pool->busy),
                  'pending' => count($Database->Database->Pool->pending),
                  'created' => $Database->Database->Pool->created,
               ],
            ]);
         }
         catch (Throwable $Throwable) {
            $Response->code(500)->JSON->send([
               'status' => 'exception',
               'message' => $Throwable->getMessage(),
            ]);
         }
      });
   }, GET);

   // @ PostgreSQL slow query demo for non-blocking checks
   yield $Router->route('/deferred/database/sleep', function (Request $Request, Response $Response) use ($Query, $Respond, $Rows) {
      return $Query($Response, 'SELECT pg_sleep(2), $1::int AS value', [42], function (Response $Response, Operation $Operation) use ($Respond, $Rows): void {
         $Respond($Response, $Operation, $Rows($Operation), null);
      });
   }, GET);

   // @ Catch-all 404
   yield $Router->route('/*', function (Request $Request, Response $Response) {
      return $Response(code: 404, body: 'Not Found');
   });
};
