<?php

use Generator;
use RuntimeException;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Configs\Options;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should resolve values and build rounds',
   test: new Assertions(Case: function (): Generator
   {
      $fixture = __DIR__ . '/fixtures/valid.options.php';

      // # Defaults — no CLI options
      $Options = Options::load($fixture);
      $Options->parse([]);

      yield new Assertion(
         description: 'Non-null defaults are resolved; null defaults are omitted',
         fallback: 'Defaults not resolved!'
      )
         ->expect($Options->values, Op::Equal, ['label' => 'run'])
         ->assert();

      yield new Assertion(
         description: 'No sweeps yields a single round',
         fallback: 'Single round not built!'
      )
         ->expect($Options->rounds, Op::Equal, [['label' => 'run']])
         ->assert();

      // # Single value — one round, no sweep
      $Options = Options::load($fixture);
      $Options->parse(['server-workers' => '8']);

      yield new Assertion(
         description: 'Single int value resolves statically',
         fallback: 'Single value not resolved!'
      )
         ->expect($Options->values['server-workers'], Op::Identical, 8)
         ->assert();

      yield new Assertion(
         description: 'Single value keeps one round',
         fallback: 'Round count wrong for single value!'
      )
         ->expect(count($Options->rounds), Op::Identical, 1)
         ->assert();

      // # Sweep — N rounds
      $Options = Options::load($fixture);
      $Options->parse(['server-workers' => '1..24:8', 'profile' => true]);

      yield new Assertion(
         description: 'Sweep is registered',
         fallback: 'Sweep not registered!'
      )
         ->expect($Options->sweeps, Op::Equal, ['server-workers' => [1, 9, 17]])
         ->assert();

      yield new Assertion(
         description: 'Each round carries static values + its sweep value',
         fallback: 'Rounds not built from sweep!'
      )
         ->expect(
            $Options->rounds,
            Op::Equal,
            [
               ['label' => 'run', 'profile' => true, 'server-workers' => 1],
               ['label' => 'run', 'profile' => true, 'server-workers' => 9],
               ['label' => 'run', 'profile' => true, 'server-workers' => 17],
            ]
         )
         ->assert();

      // # Errors
      $capture = static function (array $options) use ($fixture): bool {
         try {
            Options::load($fixture)->parse($options);
         }
         catch (RuntimeException) {
            return true;
         }

         return false;
      };

      yield new Assertion(
         description: 'Bare flag on a valued option is rejected',
         fallback: 'Bare valued flag not rejected!'
      )
         ->expect($capture(['server-workers' => true]), Op::Identical, true)
         ->assert();

      yield new Assertion(
         description: 'Value on a bool flag is rejected',
         fallback: 'Valued bool flag not rejected!'
      )
         ->expect($capture(['profile' => '1']), Op::Identical, true)
         ->assert();

      yield new Assertion(
         description: 'Unknown CLI options are ignored (runner/global options)',
         fallback: 'Unknown option not ignored!'
      )
         ->expect($capture(['connections' => '514']), Op::Identical, false)
         ->assert();
   })
);
