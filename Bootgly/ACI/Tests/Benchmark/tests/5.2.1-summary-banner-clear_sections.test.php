<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Configs\Load;
use Bootgly\ACI\Tests\Benchmark\Configs\Options;
use Bootgly\ACI\Tests\Benchmark\Info;
use Bootgly\ACI\Tests\Benchmark\Opponent;
use Bootgly\ACI\Tests\Benchmark\Runner;
use Bootgly\ACI\Tests\Benchmark\Summary;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render explicit benchmark configuration sections',
   test: new Assertions(Case: function (): \Generator
   {
      $Runner = new class extends Runner {
         public protected(set) string $name = 'tcp_client';

         public function __construct ()
         {
            $this->loads = [
               new Load('1 static route', 'Static routes', '/dev/null'),
               new Load('20 static routes', 'Static routes', '/dev/null'),
            ];
         }

         public function configure (array $options): void
         {
         }

         public function banner (Configs $Configs): array
         {
            return [
               'Client' => [
                  'Engine' => 'Bootgly TCP_Client_CLI',
                  'Client workers' => '12',
                  'Connections' => '514',
               ],
               'Server' => [
                  'Server workers' => '999',
                  'Port' => '8082',
               ],
               'Database' => [
                  'Pool max / worker' => '1',
                  'Parity' => 'Capability contract validated',
               ],
            ];
         }

         public function run (Configs $Configs): array
         {
            return [];
         }
      };
      $Runner->add(new Opponent('Bootgly', '/dev/null', 'v1'));
      $Runner->add(new Opponent('Swoole', '/dev/null', 'v2'));

      $Configs = Configs::parse([
         'opponents' => 'bootgly,swoole',
         'loads' => 'benchmark:1',
         'output' => 'compact',
         'format' => 'text',
         'results' => 'marks',
      ]);
      $Info = new Info;
      $Info->os = 'Test OS';
      $Info->cpuModel = 'Test CPU';
      $Info->cpuCount = '24';
      $Info->ram = '32 GB';
      $Info->storage = '1 TB';
      $Info->network = 'loopback';
      $Info->date = '2026-07-14 20:00:00';

      $fixture = __DIR__ . '/fixtures/valid.options.php';
      $Capture = static function (
         Options $Options,
         null|Runner $BenchmarkRunner = null,
         string $caseName = 'HTTP_Server_CLI',
      ) use ($Info, $Runner, $Configs): string {
         $ActiveRunner = $BenchmarkRunner ?? $Runner;

         ob_start();
         Summary::banner(
            $Info,
            $ActiveRunner,
            $Configs,
            $caseName,
            $Options,
            'compact',
         );
         $output = ob_get_clean();

         if ($output === false) {
            return '';
         }

         return preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? $output;
      };

      // # Fixed case value — client and server workers must never share one label.
      $Options = Options::load($fixture);
      $Options->parse(['server-workers' => '18']);
      $plain = $Capture($Options);

      yield new Assertion(
         description: 'Host is first and global groups remain distinct',
         fallback: 'Top-level benchmark sections are missing or misordered!'
      )
         ->expect(
            preg_match(
               '/\n  Host\n.*\n  Case\n.*\n  Runner\n.*\n  Opponents\n.*\n  Loads\n/s',
               $plain,
            ) === 1,
            Op::Identical,
            true,
         )
         ->assert();

      yield new Assertion(
         description: 'Client and Server are explicit Case subgroups with exact workers',
         fallback: 'Harness groups escaped the Case or carry ambiguous worker values!'
      )
         ->expect(
            preg_match(
               '/\n  Case\n.*\n  ├─ Client\n.*Client workers\s+12.*\n  ├─ Server\n.*Server workers\s+18/s',
               $plain,
            ) === 1
               && preg_match('/\n  (?:Client|Server)\n/', $plain) !== 1
               && !str_contains($plain, 'Server workers 999'),
            Op::Identical,
            true,
         )
         ->assert();

      yield new Assertion(
         description: 'Long subgroup labels retain one separator and aligned values',
         fallback: 'The longest Database label was glued to its value!'
      )
         ->expect(
            str_contains($plain, 'Pool max / worker 1')
               && !str_contains($plain, 'Pool max / worker1')
               && preg_match(
                  '/Pool max \/ worker 1\n.*Parity\s+Capability contract validated/s',
                  $plain,
               ) === 1,
            Op::Identical,
            true,
         )
         ->assert();

      yield new Assertion(
         description: 'Resolved global configuration is visible',
         fallback: 'Case, runner or output configuration was omitted!'
      )
         ->expect(
            str_contains($plain, 'HTTP_Server_CLI')
               && str_contains($plain, 'tcp_client')
               && str_contains($plain, 'Output style      compact')
               && str_contains($plain, 'Format            text')
               && str_contains($plain, 'Artifacts         marks'),
            Op::Identical,
            true,
         )
         ->assert();

      yield new Assertion(
         description: 'Loads displays its set name, exact selection and concrete load',
         fallback: 'Loads selection is incomplete or unfiltered!'
      )
         ->expect(
            str_contains($plain, 'Load set          benchmark')
               && str_contains($plain, 'Selection         2/2 opponents')
               && str_contains($plain, 'Selection         1/2 loads')
               && str_contains($plain, '1 static route')
               && !str_contains($plain, '20 static routes'),
            Op::Identical,
            true,
         )
         ->assert();

      // # Sweep — show the complete shape concisely, never a guessed first value.
      $Options = Options::load($fixture);
      $Options->parse(['server-workers' => '1..24']);
      $plain = $Capture($Options);

      yield new Assertion(
         description: 'Server-worker sweep is labelled and compact',
         fallback: 'Sweep banner is missing, ambiguous or excessively long!'
      )
         ->expect(
            str_contains($plain, 'Rounds            24 (sweep)')
               && preg_match(
                  '/Server workers\s+1, 2, …, 23, 24 \(24 values\)/',
                  $plain,
               ) === 1
               && !str_contains($plain, '3, 4, 5, 6, 7, 8'),
            Op::Identical,
            true,
         )
         ->assert();

      // # Auto — preserve the unresolved user configuration instead of guessing.
      $Options = Options::load($fixture);
      $Options->parse([]);
      $plain = $Capture($Options);

      yield new Assertion(
         description: 'Absent options preserve their effective auto or disabled state',
         fallback: 'Banner guessed a value or misreported an absent boolean flag!'
      )
         ->expect(
            preg_match('/Server workers\s+auto/', $plain) === 1
               && str_contains($plain, 'Profile           disabled'),
            Op::Identical,
            true,
         )
         ->assert();

      // # Case without a client/server harness — no empty or invented subgroup.
      $CodeRunner = new class extends Runner {
         public protected(set) string $name = 'code';

         public function configure (array $options): void
         {
         }

         public function run (Configs $Configs): array
         {
            return [];
         }
      };
      $Options = Options::load(__DIR__ . '/fixtures/no-options.php');
      $Options->parse([]);
      $plain = $Capture($Options, $CodeRunner, 'Code');

      yield new Assertion(
         description: 'Cases without a client/server harness omit both subgroups',
         fallback: 'Summary invented Client or Server groups for a generic case!'
      )
         ->expect(
            preg_match('/\n  (?:├─|└─) (?:Client|Server)\n/', $plain) !== 1
               && str_contains($plain, 'Name              Code')
               && str_contains($plain, 'Name              code'),
            Op::Identical,
            true,
         )
         ->assert();
   })
);
