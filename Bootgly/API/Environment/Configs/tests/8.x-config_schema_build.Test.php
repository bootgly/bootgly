<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Validators\Maximum;
use Bootgly\ADI\Validators\Minimum;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\Config\Types;
use Bootgly\API\Environment\Configs\Schema;
use Bootgly\API\Environment\Configs\Schema\Field;


return new Test(
   description: 'Config schema: a complete scope builds like the equivalent bind() chain',
   test: function () {
      // @ Clean env (also when the test stops early)
      $names = ['SCHEMA_T_HOST', 'SCHEMA_T_PORT', 'SCHEMA_T_DEBUG', 'SCHEMA_T_PASS', 'SCHEMA_T_RATIO'];
      foreach ($names as $name) {
         putenv($name);
      }
      try {

         $Schema = new Schema(scope: 'server', Fields: [
            'Host' => new Field(key: 'SCHEMA_T_HOST', default: 'localhost'),
            'Port' => new Field(key: 'SCHEMA_T_PORT', default: 8080, cast: Types::Integer, Rules: [
               new Minimum(1),
               new Maximum(65535),
            ]),
            'Debug' => new Field(key: 'SCHEMA_T_DEBUG', default: false, cast: Types::Boolean),
            'Charset' => new Field(default: 'utf8mb4'),
            'Connections.Primary.Password' => new Field(key: 'SCHEMA_T_PASS', required: true),
            'Connections.Primary.Ratio' => new Field(key: 'SCHEMA_T_RATIO', default: 0.5, cast: Types::Float),
         ]);

         // @ Config surface
         yield assert(
            assertion: $Schema->scope === 'server'
               && array_keys($Schema->Fields) === [
                  'Host', 'Port', 'Debug', 'Charset',
                  'Connections.Primary.Password', 'Connections.Primary.Ratio',
               ],
            description: 'scope and fields are kept in declaration order'
         );

         // @ Resolution order: process env > scope-local .env > default
         putenv('SCHEMA_T_PORT=9090');
         putenv('SCHEMA_T_PASS=runtime-secret');
         $previous = Config::swap([
            'SCHEMA_T_PORT' => '7070',
            'SCHEMA_T_DEBUG' => 'yes',
            'SCHEMA_T_RATIO' => '0.25',
         ]);
         try {
            $Root = $Schema->build();
         }
         finally {
            Config::swap($previous);
         }

         yield assert(
            assertion: $Root instanceof Config && $Root->scope === 'server' && $Root->parent === null,
            description: 'build() returns a new root Config of the scope'
         );
         yield assert(
            assertion: $Root->Port->get() === 9090,
            description: 'process env wins over the local .env and is cast strictly'
         );
         yield assert(
            assertion: $Root->Debug->get() === true,
            description: 'local .env wins over the default and is cast strictly'
         );
         yield assert(
            assertion: $Root->Host->get() === 'localhost' && $Root->Charset->get() === 'utf8mb4',
            description: 'defaults apply to absent keys and default-only fields'
         );
         yield assert(
            assertion: $Root->Connections->Primary->Password->get() === 'runtime-secret'
               && $Root->Connections->Primary->Ratio->get() === 0.25,
            description: 'nested node paths resolve into navigable child nodes'
         );

         // @ Parity with the equivalent bind() chain, same environment
         $previous = Config::swap([
            'SCHEMA_T_PORT' => '7070',
            'SCHEMA_T_DEBUG' => 'yes',
            'SCHEMA_T_RATIO' => '0.25',
         ]);
         try {
            $Bound = new Config(scope: 'server')
               ->Host->bind(key: 'SCHEMA_T_HOST', default: 'localhost')
               ->Port->bind(key: 'SCHEMA_T_PORT', default: 8080, cast: Types::Integer)
               ->Debug->bind(key: 'SCHEMA_T_DEBUG', default: false, cast: Types::Boolean)
               ->Charset->bind(default: 'utf8mb4')
               ->Connections
                  ->Primary
                     ->Password->bind(key: 'SCHEMA_T_PASS', required: true)
                     ->Ratio->bind(key: 'SCHEMA_T_RATIO', default: 0.5, cast: Types::Float);
            $Built = $Schema->build();
         }
         finally {
            Config::swap($previous);
         }
         while ($Bound->parent !== null) {
            $Bound = $Bound->parent;
         }

         $flatten = function (Config $Node, string $path = '') use (&$flatten): array {
            $values = [];
            foreach ($Node->walk() as $name => $Child) {
               $key = $path === '' ? $name : "{$path}.{$name}";
               $values[$key] = $Child->get();
               $values += $flatten($Child, $key);
            }
            return $values;
         };
         yield assert(
            assertion: $flatten($Built) === $flatten($Bound),
            description: 'the built tree equals the bind() chain tree (values and types)'
         );

         // @ Each build resolves the environment again into a fresh tree
         putenv('SCHEMA_T_PORT=1111');
         $First = $Schema->build();
         putenv('SCHEMA_T_PORT=2222');
         $Second = $Schema->build();
         yield assert(
            assertion: $First->Port->get() === 1111 && $Second->Port->get() === 2222,
            description: 'every build() resolves again and never mutates a previous tree'
         );

         // @ An empty schema builds an empty root
         $Empty = new Schema(scope: 'empty', Fields: []);
         yield assert(
            assertion: iterator_to_array($Empty->build()->walk()) === [],
            description: 'an empty schema builds an empty root'
         );

      }
      finally {
         foreach ($names as $name) {
            putenv($name);
         }
      }
   }
);
