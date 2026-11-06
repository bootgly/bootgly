<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\DatabaseConfig;


return new Test(
   description: 'Configs: database scope maps Parameters nodes into the ADI session parameters, primary and replicas',
   test: function () {
      $refuse = static function (Config $Scope): string {
         try {
            (new DatabaseConfig($Scope))->configure();
         }
         catch (InvalidArgumentException $Exception) {
            return $Exception->getMessage();
         }

         return '';
      };

      // # Primary and replicas — one child node per parameter
      $Scope = new Config(scope: 'database');
      $PostgreSQL = $Scope->Connections->PostgreSQL;
      $PostgreSQL->Host->bind(default: 'db.local');

      $Parameters = $PostgreSQL->Parameters;
      $Parameters->search_path->bind(default: 'app, public');
      $Parameters->statement_timeout->bind(default: 5000);
      $Parameters->TimeZone->bind(default: 'UTC');
      $Parameters->{'myapp.tenant'}->bind(default: 'acme');
      $Parameters->lock_timeout->bind(default: null);

      $Replicas = $PostgreSQL->Replicas;
      $Replicas->Replica1->Host->bind(default: 'replica-a.local');
      $Replicas->Replica1->Parameters->SEARCH_PATH->bind(default: 'reports');
      $Replicas->Replica1->Parameters->default_transaction_read_only->bind(default: 'on');
      $Replicas->Replica2->Host->bind(default: 'replica-b.local');

      $Config = (new DatabaseConfig($Scope))->configure();
      $primary = json_encode($Config->parameters);

      yield assert(
         assertion: $Config->parameters === [
            'search_path' => 'app, public',
            'statement_timeout' => '5000',
            'TimeZone' => 'UTC',
            'myapp.tenant' => 'acme',
         ],
         description: "Each bound child of Parameters is one parameter, in node order and spelling, an unbound child left out — {$primary}"
      );

      $replicas = json_encode([$Config->replicas[0]['parameters'] ?? null, $Config->replicas[1]['parameters'] ?? null]);

      yield assert(
         assertion: ($Config->replicas[0]['parameters'] ?? null) === [
               'statement_timeout' => '5000',
               'TimeZone' => 'UTC',
               'myapp.tenant' => 'acme',
               'SEARCH_PATH' => 'reports',
               'default_transaction_read_only' => 'on',
            ]
            && ($Config->replicas[1]['parameters'] ?? null) === $Config->parameters,
         description: "A replica's Parameters replace the primary's by name and add its own; a replica without them inherits — {$replicas}"
      );

      // # Absent — no Parameters node means no session parameters
      $Plain = new Config(scope: 'database');
      $Plain->Connections->PostgreSQL->Host->bind(default: 'db.local');
      $PlainConfig = (new DatabaseConfig($Plain))->configure();

      yield assert(
         assertion: $PlainConfig->parameters === [],
         description: 'A connection without a Parameters node has no session parameters'
      );

      // # Merge — a base scope's parameters fill in under the project's
      $Base = new Config(scope: 'database');
      $Base->Connections->PostgreSQL->Parameters->search_path->bind(default: 'base');
      $Base->Connections->PostgreSQL->Parameters->statement_timeout->bind(default: 1000);
      $Project = new Config(scope: 'database');
      $Project->Connections->PostgreSQL->Parameters->search_path->bind(default: 'project');
      $Merged = (new DatabaseConfig($Project))->merge($Base)->configure();
      $merged = json_encode($Merged->parameters);

      yield assert(
         assertion: $Merged->parameters === ['search_path' => 'project', 'statement_timeout' => '1000'],
         description: "Merged Parameters keep the project's values and add the base's — {$merged}"
      );

      // # Refusals — the ADI config validates the map; a map-shaped value is refused
      $Pinned = new Config(scope: 'database');
      $Pinned->Connections->PostgreSQL->Parameters->DateStyle->bind(default: 'SQL, DMY');
      $message = $refuse($Pinned);

      yield assert(
         assertion: $message === 'Database parameter `DateStyle` cannot be set: the driver fixes DateStyle to ISO, the date and time text its decoder reads.',
         description: "A Parameters node is validated by the ADI config — {$message}"
      );

      $Valued = new Config(scope: 'database');
      $Valued->Connections->PostgreSQL->Parameters->bind(default: ['search_path' => 'app']);
      $message = $refuse($Valued);

      yield assert(
         assertion: $message === 'Database `Parameters` config takes one child node per session parameter (Parameters->search_path), not a value of its own.',
         description: "A value bound to Parameters itself is refused, not dropped — {$message}"
      );

      $Nested = new Config(scope: 'database');
      $Nested->Connections->PostgreSQL->Parameters->auto_explain->log_min_duration->bind(default: '250ms');
      $message = $refuse($Nested);

      yield assert(
         assertion: $message === "Database `Parameters->auto_explain` holds child nodes: name a dotted session parameter as one node, Parameters->{'auto_explain.log_min_duration'}.",
         description: "A parameter node with child nodes is refused, not dropped — {$message}"
      );

      $NestedReplica = new Config(scope: 'database');
      $NestedReplica->Connections->PostgreSQL->Replicas->Replica1->Host->bind(default: 'replica-n.local');
      $NestedReplica->Connections->PostgreSQL->Replicas->Replica1->Parameters->myapp->tenant->bind(default: 'acme');
      $message = $refuse($NestedReplica);

      yield assert(
         assertion: $message === "Database `Parameters->myapp` holds child nodes: name a dotted session parameter as one node, Parameters->{'myapp.tenant'}.",
         description: "A replica's parameter node with child nodes is refused too — {$message}"
      );

      $MySQL = new Config(scope: 'database');
      $MySQL->Default->bind(default: 'mysql');
      $MySQL->Connections->MySQL->Parameters->sql_mode->bind(default: 'ANSI');
      $message = $refuse($MySQL);

      yield assert(
         assertion: $message === 'Database `parameters` are PostgreSQL only: the mysql driver has no startup session parameters, so set the session after connecting instead.',
         description: "Parameters on a MySQL connection are refused at configure() — {$message}"
      );

      $Replica = new Config(scope: 'database');
      $Replica->Connections->PostgreSQL->Replicas->Replica1->Host->bind(default: 'replica-x.local');
      $Replica->Connections->PostgreSQL->Replicas->Replica1->Parameters->replication->bind(default: 'true');
      $message = $refuse($Replica);

      yield assert(
         assertion: $message === 'Database parameter `replication` is reserved: the startup message sends it from the connection config or refuses it (replica replica-x.local).',
         description: "A replica's Parameters node is validated naming the replica — {$message}"
      );
   }
);
