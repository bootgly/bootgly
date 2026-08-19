<?php


use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\In;
use Bootgly\ACI\Tests\Assertion\Expectations\Finders\Contains;
use Bootgly\ACI\Tests\Assertion\Expectations\Finders\InArrayKeys;
use Bootgly\ACI\Tests\Assertion\Expectations\Finders\InObjectMethods;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Finders: `->to->find(In::…)` searches for the needle it was given',
   test: new Assertions(Case: function (): Generator
   {
      /**
       * Runs one chain through the real ExA API and reports whether it PASSED.
       */
      $passing = static function (callable $chain): bool {
         try {
            $chain(new Assertion(description: 'probe'));

            return true;
         }
         catch (AssertionError) {
            return false;
         }
      };

      // ! Half the probes fail on purpose and `Assertion::fail()` clobbers the
      //   process-wide Vars debug config (TASSERT-2), so the statics are
      //   restored before the first yield.
      $print = Vars::$print;
      $debug = Vars::$debug;
      $exit = Vars::$exit;
      $traces = Vars::$traces;
      $labels = Vars::$labels;

      $Object = new stdClass();
      $Object->property = 'value';
      $Methoded = new class {
         public function run (): void {}
      };

      // ! Every haystack paired with a needle it HAS and one it has not. The
      //   expected verdict is the PHP function each finder wraps, so nothing
      //   here rests on a reading of the API.
      $cases = [
         'array keys'          => [In::ArrayKeys, ['alpha' => 'beta'], 'alpha', 'zzz'],
         'array values'        => [In::ArrayValues, ['alpha', 'beta'], 'beta', 'zzz'],
         'object properties'   => [In::ObjectProperties, $Object, 'property', 'zzz'],
         'object methods'      => [In::ObjectMethods, $Methoded, 'run', 'zzz'],
         'declared classes'    => [In::ClassesDeclared, '', Assertion::class, 'No\\Such\\Class'],
         'declared interfaces' => [In::InterfacesDeclared, '', 'Bootgly\\ACI\\Tests\\Asserting', 'No\\Such\\Interface'],
         'declared traits'     => [In::TraitsDeclared, '', 'Bootgly\\ACI\\Tests\\Assertion\\Expectation', 'No\\Such\\Trait'],
      ];

      // @@ Collect every verdict BEFORE yielding: a failing assertion aborts
      //    the generator, which would strand the restore below
      $verdicts = [];
      foreach ($cases as $label => [$Haystack, $actual, $present, $absent]) {
         $verdicts["{$label}.present"] = $passing(
            static fn (Assertion $A) => $A->expect($actual)->to->find($Haystack, $present)->assert()
         );
         $verdicts["{$label}.absent"] = $passing(
            static fn (Assertion $A) => $A->expect($actual)->to->find($Haystack, $absent)->assert()
         );
      }

      // @@ The negated form was unconditionally green, so a suite asserting
      //    absence never checked anything
      $verdicts['negated.present'] = $passing(
         static fn (Assertion $A) => $A
            ->expect(['alpha' => 'beta'])
            ->not->to->find(In::ArrayKeys, 'alpha')
            ->assert()
      );
      $verdicts['negated.absent'] = $passing(
         static fn (Assertion $A) => $A
            ->expect(['alpha' => 'beta'])
            ->not->to->find(In::ArrayKeys, 'zzz')
            ->assert()
      );

      // @@ Controls — the three needle-aware finders, reached through
      //    `expected:` instead of `find()`, must not move
      $verdicts['control.present'] = $passing(
         static fn (Assertion $A) => $A->assert(actual: 'Hello, World!', expected: new Contains('World'))
      );
      $verdicts['control.absent'] = $passing(
         static fn (Assertion $A) => $A->assert(actual: 'Hello, World!', expected: new Contains('zzz'))
      );

      // @@ A NULL needle is a real needle. Those three read it through
      //    `?? $expected`, and `??` cannot tell an unset property from one
      //    holding null — so a search for null fell back to whatever the caller
      //    passed, which on this path is the finder object itself.
      $verdicts['null_needle.present'] = $passing(
         static fn (Assertion $A) => $A->assert(actual: [null, 'b'], expected: new Contains(null))
      );
      $verdicts['null_needle.absent'] = $passing(
         static fn (Assertion $A) => $A->assert(actual: ['a', 'b'], expected: new Contains(null))
      );

      // !
      Vars::$print = $print;
      Vars::$debug = $debug;
      Vars::$exit = $exit;
      Vars::$traces = $traces;
      Vars::$labels = $labels;

      // ---

      // @@ Both directions, per haystack
      foreach ($cases as $label => $case) {
         yield new Assertion(
            description: "`find(In::…)` on {$label} passes when the needle is there"
         )
            ->assert(
               actual: $verdicts["{$label}.present"],
               expected: true
            );
         yield new Assertion(
            description: "`find(In::…)` on {$label} fails when the needle is not"
         )
            ->assert(
               actual: $verdicts["{$label}.absent"],
               expected: false
            );
      }

      // @@ The negated form
      yield new Assertion(
         description: '`not->find(In::…)` fails when the needle IS present'
      )
         ->assert(
            actual: $verdicts['negated.present'],
            expected: false
         );
      yield new Assertion(
         description: '`not->find(In::…)` passes when the needle is absent'
      )
         ->assert(
            actual: $verdicts['negated.absent'],
            expected: true
         );

      // @@ Controls
      yield new Assertion(
         description: 'A finder reached through `expected:` still finds its needle'
      )
         ->assert(
            actual: $verdicts['control.present'],
            expected: true
         );
      yield new Assertion(
         description: 'A finder reached through `expected:` still fails on a missing needle'
      )
         ->assert(
            actual: $verdicts['control.absent'],
            expected: false
         );

      // @@ A null needle
      yield new Assertion(
         description: 'A finder searching for null finds it'
      )
         ->assert(
            actual: $verdicts['null_needle.present'],
            expected: true
         );
      yield new Assertion(
         description: 'A finder searching for null reports its absence'
      )
         ->assert(
            actual: $verdicts['null_needle.absent'],
            expected: false
         );

      // ---

      // @@ `fail()` reads the needle the same way `assert()` does — the
      //    verdicts above exercise only `assert()`, so without these the seven
      //    `fail()` bodies could go back to naming `$expected`
      yield new Assertion(
         description: '`InArrayKeys::fail()` names the needle, not the sentinel'
      )
         ->assert(
            actual: (string) new InArrayKeys('zzz')->fail(['alpha' => 'beta'], null, 1),
            expected: 'Failed asserting that the array has the key "zzz".'
         );
      yield new Assertion(
         description: '`InObjectMethods::fail()` names the needle, not the sentinel'
      )
         ->assert(
            actual: (string) new InObjectMethods('zzz')->fail($Methoded, null, 1),
            expected: 'Failed asserting that the object has the method "zzz".'
         );

      // ---

      // @@ The needle is always the constructor's, which is what makes reading
      //    `$this->needle` unconditionally correct
      yield new Assertion(
         description: 'A finder stores the needle it was constructed with'
      )
         ->assert(
            actual: new InArrayKeys('alpha')->needle,
            expected: 'alpha'
         );
   })
);
