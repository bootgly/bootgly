<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use function assert;
use function str_contains;
use function str_repeat;
use ReflectionClass;
use ReflectionProperty;

use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Configs\Options;
use Bootgly\ACI\Tests\Benchmark\Info;
use Bootgly\ACI\Tests\Benchmark\Runner;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'test benchmark: the report reproduction command keeps settings and redacts credentials',
   test: function () {
      // ! The report builder, fed the options a user typed
      $Extract = new ReflectionClass(TestCommand::class)->getMethod('extract');
      $Runner = new class extends Runner {
         public function configure (array $options): void {}
         public function run (Configs $Configs): array
         {
            return [];
         }
      };
      $Options = new ReflectionClass(Options::class)->newInstanceWithoutConstructor();
      // ! The options the case declares — a `target` too, to prove a URL is redacted even there
      $Field = static fn (string $type): array => ['type' => $type, 'default' => null, 'vary' => false, 'description' => ''];
      new ReflectionProperty(Options::class, 'schema')->setValue($Options, [
         'duration' => $Field('string'),
         'connections' => $Field('int'),
         'server-workers' => $Field('int'),
         'target' => $Field('string'),
      ]);
      $Build = static function (array $options) use ($Extract, $Runner, $Options): string {
         $run = $Extract->invoke(null, 'Case', Info::collect(), $Runner, Configs::parse([]), $Options, [], $options, []);

         return $run['command'];
      };

      // @ Benchmark settings and bare flags survive whole
      $command = $Build([
         'results' => 'report',
         'loads' => 'techempower:1..3',
         'duration' => '10s',
         'connections' => 514,
         'server-workers' => '1..24:4',
         'opponents' => 'bootgly,swoole-base',
         'quiet' => true,
      ]);
      yield assert(
         assertion: $command === 'bootgly test benchmark Case --results=report --loads=techempower:1..3 --duration=10s'
            . ' --connections=514 --server-workers=1..24:4 --opponents=bootgly,swoole-base --quiet',
         description: "Settings and flags are reproduced as typed ({$command})"
      );

      // @ Credentials never reach the report — a URL, a path, a token under a credential name
      $command = $Build([
         'duration' => '10s',
         'dsn' => 'redis://user:SYNTHPW41@127.0.0.1/0',
         'target' => 'https://SYNTHTOK42@host.invalid/',
         'upstream' => 'http://127.0.0.1:8080/path',
         'api-key' => 'SYNTHKEY43',
         'auth-token' => 'SYNTHTOK44',
         'password' => 'SYNTHPW45',
         'cert' => '/home/user/SYNTHPATH46.pem',
         'long' => 'SYNTHLONG47' . str_repeat('a', 64),
      ]);
      yield assert(
         assertion: str_contains($command, 'SYNTH') === false
            && str_contains($command, '127.0.0.1') === false
            && str_contains($command, ' --duration=10s') === true
            && str_contains($command, ' --dsn=<redacted>') === true
            && str_contains($command, ' --target=<redacted>') === true
            && str_contains($command, ' --upstream=<redacted>') === true
            && str_contains($command, ' --api-key=<redacted>') === true
            && str_contains($command, ' --auth-token=<redacted>') === true
            && str_contains($command, ' --password=<redacted>') === true
            && str_contains($command, ' --cert=<redacted>') === true
            && str_contains($command, ' --long=<redacted>') === true,
         description: "URLs, paths, long values and values under a credential name are redacted ({$command})"
      );

      // @ An option neither the runner nor the case declares keeps its name only — its value may
      //   be a `user:password` pair or a token under any name
      $command = $Build([
         'duration' => '10s',
         'pg' => 'postgres:SYNTHPW51',
         'redis' => 'default:SYNTHPW52',
         'bearer' => 'ghp_SYNTHTOKEN53abcdefABCDEF0123',
         'jwt' => 'eyJhbGciOi.SYNTHJWT54',
         'pwd' => 'SYNTHPW55',
         'api' => 'SYNTHKEY56',
      ]);
      yield assert(
         assertion: str_contains($command, 'SYNTH') === false
            && $command === 'bootgly test benchmark Case --duration=10s --pg=<redacted> --redis=<redacted>'
               . ' --bearer=<redacted> --jwt=<redacted> --pwd=<redacted> --api=<redacted>',
         description: "An undeclared option's value is redacted whatever its name or shape ({$command})"
      );
   }
);
