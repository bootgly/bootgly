<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Opponent;
use Bootgly\ACI\Tests\Benchmark\Runner;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should apply round values to the Runner',
   test: new Assertions(Case: function (): Generator
   {
      // ! Minimal concrete Runner
      $Runner = new class extends Runner {
         public function configure (array $options): void
         {
         }

         public function run (Configs $Configs): array
         {
            return [];
         }
      };

      $Runner->add(new Opponent(name: 'Bootgly', script: '/dev/null'));
      $Runner->add(new Opponent(name: 'Swoole', script: '/dev/null'));

      // @ Apply a sweep round
      $Runner->apply(['server-workers' => 8]);

      yield new Assertion(
         description: 'server-workers is applied to every Opponent',
         fallback: 'Opponent workers not applied!'
      )
         ->expect(
            [$Runner->opponents[0]->workers, $Runner->opponents[1]->workers],
            Op::Equal,
            [8, 8]
         )
         ->assert();

      // @ Unknown keys are ignored
      $Runner->apply(['profile' => true]);

      yield new Assertion(
         description: 'Unknown keys leave Opponents untouched',
         fallback: 'Unknown key mutated Opponents!'
      )
         ->expect($Runner->opponents[0]->workers, Op::Identical, 8)
         ->assert();
   })
);
