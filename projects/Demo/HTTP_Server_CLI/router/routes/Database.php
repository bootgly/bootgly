<?php

namespace Demo\HTTP_Server_CLI\router;


use const DATE_ATOM;
use const GET;
use function count;
use Throwable;

use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Orders;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Builder\Query as BuilderQuery;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Repository\Selection;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database as DatabaseResource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Demo\HTTP_Server_CLI\Models\DemoLazyUser;
use Demo\HTTP_Server_CLI\Models\DemoPost;
use Demo\HTTP_Server_CLI\Models\DemoUser;


require_once __DIR__ . '/../../Models/DemoUser.php';
require_once __DIR__ . '/../../Models/DemoLazyUser.php';
require_once __DIR__ . '/../../Models/DemoPost.php';



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

   $SerializePosts = static function (iterable $Entities): array {
      $posts = [];

      foreach ($Entities as $Entity) {
         if ($Entity instanceof DemoPost === false) {
            continue;
         }

         $posts[] = [
            'id' => $Entity->id,
            'user_id' => $Entity->user,
            'title' => $Entity->title,
         ];
      }

      return $posts;
   };

   $SerializeUsers = static function (array $Entities) use ($SerializePosts): array {
      $users = [];

      foreach ($Entities as $Entity) {
         if ($Entity instanceof DemoUser === false) {
            continue;
         }

         $users[] = [
            'id' => $Entity->id,
            'name' => $Entity->name,
            'email' => $Entity->email,
            'active' => $Entity->active,
            'score' => $Entity->score,
            'created_at' => $Entity->CreatedAt?->format(DATE_ATOM),
            'posts' => $SerializePosts($Entity->posts),
         ];
      }

      return $users;
   };

   $SerializeLazyUsers = static function (array $Entities) use ($SerializePosts): array {
      $users = [];

      foreach ($Entities as $Entity) {
         if ($Entity instanceof DemoLazyUser === false) {
            continue;
         }

         $users[] = [
            'id' => $Entity->id,
            'name' => $Entity->name,
            'email' => $Entity->email,
            'active' => $Entity->active,
            'score' => $Entity->score,
            'created_at' => $Entity->CreatedAt?->format(DATE_ATOM),
            'posts_count' => count($Entity->posts),
            'posts' => $SerializePosts($Entity->posts),
         ];
      }

      return $users;
   };

   // @ Database demo index
   yield $Router->route('/', function (Request $Request, Response $Response) {
      return $Response->code(200)->JSON->send([
         'status' => 'ok',
         'routes' => [
            '/deferred/database/setup',
            '/deferred/database/users',
            '/deferred/database/user',
            '/deferred/database/orm/users',
            '/deferred/database/orm/user',
            '/deferred/database/orm/relations',
            '/deferred/database/orm/lazy-relations',
            '/deferred/database/orm/save',
            '/deferred/database/config',
            '/deferred/database/ping',
            '/deferred/database/parameters',
            '/deferred/database/types',
            '/deferred/database/error',
            '/deferred/database/pool',
            '/deferred/database/sleep',
            '/deferred/kv',
            '/deferred/kv/sequential',
            '/deferred/kv/pipeline',
         ],
      ]);
   }, GET);

   // @ Async KV (Redis) — single command
   yield $Router->route('/deferred/kv', function (Request $Request, Response $Response) {
      return $Response->defer(function (Response $Response): void {
         try {
            /** @var \Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\KV $KV */
            $KV = $Response->KV;

            $KV->fetch('SET', ['bootgly:demo', 'async-kv']);

            $Response->code(200)->JSON->send([
               'status' => 'ok',
               'value' => $KV->fetch('GET', ['bootgly:demo']),
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

   // @ Async KV — N sequential round-trips (one await per command)
   yield $Router->route('/deferred/kv/sequential', function (Request $Request, Response $Response) {
      return $Response->defer(function (Response $Response): void {
         try {
            /** @var \Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\KV $KV */
            $KV = $Response->KV;
            $values = [];

            for ($i = 0; $i < 8; $i++) {
               $values[] = $KV->fetch('GET', ['bootgly:demo']);
            }

            $Response->code(200)->JSON->send([
               'status' => 'ok',
               'values' => $values,
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

   // @ Async KV — N commands pipelined on one connection, drained together
   yield $Router->route('/deferred/kv/pipeline', function (Request $Request, Response $Response) {
      return $Response->defer(function (Response $Response): void {
         try {
            /** @var \Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\KV **/
            $KV = $Response->KV;
            $Operations = [];

            for ($i = 0; $i < 8; $i++) {
               $Operations[] = $KV->command('GET', ['bootgly:demo']);
            }

            $values = [];
            foreach ($KV->drain($Operations) as $Operation) {
               $values[] = $Operation->error ?? $Operation->response;
            }

            $Response->code(200)->JSON->send([
               'status' => 'ok',
               'values' => $values,
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
               'CREATE TABLE IF NOT EXISTS bootgly_orm_users (id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, name text NOT NULL, email text NOT NULL UNIQUE, active boolean NOT NULL DEFAULT true, score numeric(10,2) NOT NULL DEFAULT 0, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP)',
               'CREATE TABLE IF NOT EXISTS bootgly_orm_posts (id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, user_id integer NOT NULL REFERENCES bootgly_orm_users(id) ON DELETE CASCADE, title text NOT NULL)',
               "INSERT INTO bootgly_orm_users (id, name, email, active, score) VALUES (1, 'Ada Lovelace', 'ada.orm@example.test', true, 98.50), (2, 'Grace Hopper', 'grace.orm@example.test', true, 96.75), (3, 'Alan Turing', 'alan.orm@example.test', false, 99.99) ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, email = EXCLUDED.email, active = EXCLUDED.active, score = EXCLUDED.score",
               "SELECT setval(pg_get_serial_sequence('bootgly_orm_users', 'id'), (SELECT MAX(id) FROM bootgly_orm_users))",
               "INSERT INTO bootgly_orm_posts (id, user_id, title) VALUES (1, 1, 'Analytical engine notes'), (2, 1, 'Symbolic computation'), (3, 2, 'Compiler operations') ON CONFLICT (id) DO UPDATE SET user_id = EXCLUDED.user_id, title = EXCLUDED.title",
               "SELECT setval(pg_get_serial_sequence('bootgly_orm_posts', 'id'), (SELECT MAX(id) FROM bootgly_orm_posts))",
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
               'message' => 'Demo tables bootgly_demo_users, bootgly_orm_users and bootgly_orm_posts are ready.',
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

   // @ PostgreSQL ORM users list
   yield $Router->route('/deferred/database/orm/users', function (Request $Request, Response $Response) use ($DatabaseResource, $SerializeUsers) {
      return $Response->defer(function (Response $Response) use ($DatabaseResource, $SerializeUsers): void {
         try {
            $Database = $DatabaseResource($Response);
            $UserRepository = $Database->map(DemoUser::class);
            $UserRepository->scope('active', function (Selection $Selection): void {
               $Selection->filter(new Identifier('active'), Operators::Equal, true);
            });

            $Operation = $Database->await(
               $UserRepository->fetch(
                  $UserRepository
                     ->select()
                     ->scope('active')
                     ->order(Orders::Asc, new Identifier('id'))
               )
            );
            $Mapped = $UserRepository->hydrate($Operation);

            $Response->code(200)->JSON->send([
               'status' => 'ok',
               'entities' => $SerializeUsers($Mapped->entities),
               'count' => $Mapped->count,
            ]);
         }
         catch (Throwable $Throwable) {
            $Response->code(500)->JSON->send([
               'status' => 'exception',
               'message' => $Throwable->getMessage(),
               'hint' => 'Call /deferred/database/setup first.',
            ]);
         }
      });
   }, GET);

   // @ PostgreSQL ORM user by primary key
   yield $Router->route('/deferred/database/orm/user', function (Request $Request, Response $Response) use ($DatabaseResource, $SerializeUsers) {
      return $Response->defer(function (Response $Response) use ($DatabaseResource, $SerializeUsers): void {
         try {
            $Database = $DatabaseResource($Response);
            $UserRepository = $Database->map(DemoUser::class);
            $Operation = $Database->await($UserRepository->find(1));
            $Mapped = $UserRepository->hydrate($Operation);

            $Response->code($Mapped->empty ? 404 : 200)->JSON->send([
               'status' => $Mapped->empty ? 'missing' : 'ok',
               'entity' => $SerializeUsers($Mapped->entities)[0] ?? null,
            ]);
         }
         catch (Throwable $Throwable) {
            $Response->code(500)->JSON->send([
               'status' => 'exception',
               'message' => $Throwable->getMessage(),
               'hint' => 'Call /deferred/database/setup first.',
            ]);
         }
      });
   }, GET);

   // @ PostgreSQL ORM eager relationship loading
   yield $Router->route('/deferred/database/orm/relations', function (Request $Request, Response $Response) use ($DatabaseResource, $SerializeUsers) {
      return $Response->defer(function (Response $Response) use ($DatabaseResource, $SerializeUsers): void {
         try {
            $Database = $DatabaseResource($Response);
            $UserRepository = $Database->map(DemoUser::class);
            $Operation = $Database->await(
               $UserRepository->fetch(
                  $UserRepository
                     ->select()
                     ->load('posts')
                     ->order(Orders::Asc, new Identifier('id'))
               )
            );
            $MappedUsers = $UserRepository->hydrate($Operation);

            $Response->code(200)->JSON->send([
               'status' => 'ok',
               'entities' => $SerializeUsers($MappedUsers->entities),
            ]);
         }
         catch (Throwable $Throwable) {
            $Response->code(500)->JSON->send([
               'status' => 'exception',
               'message' => $Throwable->getMessage(),
               'hint' => 'Call /deferred/database/setup first.',
            ]);
         }
      });
   }, GET);

   // @ PostgreSQL ORM lazy relationship loading
   yield $Router->route('/deferred/database/orm/lazy-relations', function (Request $Request, Response $Response) use ($DatabaseResource, $SerializeLazyUsers) {
      return $Response->defer(function (Response $Response) use ($DatabaseResource, $SerializeLazyUsers): void {
         try {
            $Database = $DatabaseResource($Response);
            $UserRepository = $Database->map(DemoLazyUser::class);
            $Operation = $Database->await(
               $UserRepository->fetch(
                  $UserRepository
                     ->select()
                     ->order(Orders::Asc, new Identifier('id'))
               )
            );
            $MappedUsers = $UserRepository->hydrate($Operation);

            $Response->code(200)->JSON->send([
               'status' => 'ok',
               'entities' => $SerializeLazyUsers($MappedUsers->entities),
            ]);
         }
         catch (Throwable $Throwable) {
            $Response->code(500)->JSON->send([
               'status' => 'exception',
               'message' => $Throwable->getMessage(),
               'hint' => 'Call /deferred/database/setup first.',
            ]);
         }
      });
   }, GET);

   // @ PostgreSQL ORM insert with generated key and RETURNING hydration
   yield $Router->route('/deferred/database/orm/save', function (Request $Request, Response $Response) use ($DatabaseResource, $SerializeUsers) {
      return $Response->defer(function (Response $Response) use ($DatabaseResource, $SerializeUsers): void {
         try {
            $Database = $DatabaseResource($Response);
            $UserRepository = $Database->map(DemoUser::class);
            $Database->query('DELETE FROM bootgly_orm_users WHERE email = $1', ['katherine.orm@example.test']);

            $Entity = new DemoUser;
            $Entity->name = 'Katherine Johnson';
            $Entity->email = 'katherine.orm@example.test';
            $Entity->active = true;
            $Entity->score = 97.25;

            $Operation = $Database->await($UserRepository->save($Entity));
            $Mapped = $UserRepository->hydrate($Operation);

            $Response->code(200)->JSON->send([
               'status' => 'ok',
               'entity' => $SerializeUsers($Mapped->entities)[0] ?? null,
            ]);
         }
         catch (Throwable $Throwable) {
            $Response->code(500)->JSON->send([
               'status' => 'exception',
               'message' => $Throwable->getMessage(),
               'hint' => 'Call /deferred/database/setup first.',
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
