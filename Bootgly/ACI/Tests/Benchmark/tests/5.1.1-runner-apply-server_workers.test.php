<?php

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

      // # A case may install one fail-closed validator after the concrete
      //   opponent/load selection becomes available.
      $validated = false;
      $validatedRounds = [];
      $Runner->Validator = static function (
         Runner $Runner,
         Configs $Configs,
         array $rounds,
      ) use (&$validated, &$validatedRounds): void {
         $validated = $Runner->opponents !== [] && $Configs->loadSet === 'proof';
         $validatedRounds = $rounds;
      };
      $rounds = [
         ['server-workers' => 4],
         ['server-workers' => 8],
      ];
      $Runner->validate(Configs::parse([
         'opponents' => 'bootgly',
         'loads' => 'proof:*',
      ]), $rounds);

      yield new Assertion(
         description: 'Runner invokes the optional case validator',
         fallback: 'The case validator did not receive the resolved benchmark selection!'
      )
         ->expect($validated, Op::Identical, true)
         ->assert();

      yield new Assertion(
         description: 'Runner forwards every resolved round to the case validator',
         fallback: 'The case validator did not receive the complete round plan!'
      )
         ->expect($validatedRounds, Op::Identical, $rounds)
         ->assert();
   })
);
