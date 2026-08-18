<?php


use Bootgly\ABI\Argument;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion\Comparators\Identical;
use Bootgly\ACI\Tests\Assertion\Comparators\NotIdentical;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'A comparator configured with `null` compares against null, not the sentinel',
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
      //   snapshotted here and restored before the first yield.
      $print = Vars::$print;
      $debug = Vars::$debug;
      $exit = Vars::$exit;
      $traces = Vars::$traces;
      $labels = Vars::$labels;

      // @@ Collect every verdict BEFORE yielding: a failing assertion aborts
      //    the generator, which would strand the restore below
      $verdicts = [];
      foreach ([
         // # The reachable false-success set: negative comparators told to
         //   reject null accept it, because they see the sentinel instead
         'null.not_identical'
            => static fn (Assertion $A) => $A->expect(null, Op::NotIdentical, null)->assert(),
         'null.not_equal'
            => static fn (Assertion $A) => $A->expect(null, Op::NotEqual, null)->assert(),

         // # The positive comparators fail loudly instead
         'null.identical'
            => static fn (Assertion $A) => $A->expect(null, Op::Identical, null)->assert(),
         'null.equal'
            => static fn (Assertion $A) => $A->expect(null, Op::Equal, null)->assert(),

         // # The ordering comparators: `5 > null` holds, `5 > <sentinel>` does not
         'null.greater_than'
            => static fn (Assertion $A) => $A->expect(5, Op::GreaterThan, null)->assert(),
         'null.greater_than_or_equal'
            => static fn (Assertion $A) => $A->expect(5, Op::GreaterThanOrEqual, null)->assert(),

         // # The Behaviors path builds an `Identical(null)` out of `be(null)`
         'be_null.match'
            => static fn (Assertion $A) => $A->expect(null)->to->be(null)->assert(),
         'be_null.mismatch'
            => static fn (Assertion $A) => $A->expect(5)->to->be(null)->assert(),

         // # Controls — `??` never triggered on these, so they must not move
         'falsy.false'
            => static fn (Assertion $A) => $A->expect(false, Op::Identical, false)->assert(),
         'falsy.zero'
            => static fn (Assertion $A) => $A->expect(0, Op::Identical, 0)->assert(),
         'falsy.empty_string'
            => static fn (Assertion $A) => $A->expect('', Op::Identical, '')->assert(),
         'falsy.empty_array'
            => static fn (Assertion $A) => $A->expect([], Op::Identical, [])->assert(),
         'plain.match'
            => static fn (Assertion $A) => $A->expect(5, Op::Identical, 5)->assert(),
         'plain.mismatch'
            => static fn (Assertion $A) => $A->expect(5, Op::Identical, 6)->assert(),

         // # Control — the unconfigured form, where the caller's fallback IS
         //   the value to compare against
         'unconfigured.match'
            => static fn (Assertion $A) => $A->assert(actual: 5, expected: 5),
         'unconfigured.null'
            => static fn (Assertion $A) => $A->assert(actual: null, expected: null),
      ] as $name => $Chain) {
         $verdicts[$name] = $passing($Chain);
      }

      // !
      Vars::$print = $print;
      Vars::$debug = $debug;
      Vars::$exit = $exit;
      Vars::$traces = $traces;
      Vars::$labels = $labels;

      // ---

      // @@ The false successes — a "must not be null" guard that accepts null
      yield new Assertion(
         description: '`Op::NotIdentical` against null rejects a null actual'
      )
         ->assert(
            actual: $verdicts['null.not_identical'],
            expected: false
         );
      yield new Assertion(
         description: '`Op::NotEqual` against null rejects a null actual'
      )
         ->assert(
            actual: $verdicts['null.not_equal'],
            expected: false
         );

      // @@ The loud failures
      yield new Assertion(
         description: '`Op::Identical` against null accepts a null actual'
      )
         ->assert(
            actual: $verdicts['null.identical'],
            expected: true
         );
      yield new Assertion(
         description: '`Op::Equal` against null accepts a null actual'
      )
         ->assert(
            actual: $verdicts['null.equal'],
            expected: true
         );
      yield new Assertion(
         description: '`Op::GreaterThan` against null holds for 5'
      )
         ->assert(
            actual: $verdicts['null.greater_than'],
            expected: true
         );
      yield new Assertion(
         description: '`Op::GreaterThanOrEqual` against null holds for 5'
      )
         ->assert(
            actual: $verdicts['null.greater_than_or_equal'],
            expected: true
         );

      // @@ The Behaviors path
      yield new Assertion(
         description: '`->to->be(null)` accepts a null actual'
      )
         ->assert(
            actual: $verdicts['be_null.match'],
            expected: true
         );
      yield new Assertion(
         description: '`->to->be(null)` rejects a non-null actual'
      )
         ->assert(
            actual: $verdicts['be_null.mismatch'],
            expected: false
         );

      // @@ Controls
      foreach (['false', 'zero', 'empty_string', 'empty_array'] as $falsy) {
         yield new Assertion(
            description: "A falsy-but-set expected value ({$falsy}) still compares against itself"
         )
            ->assert(
               actual: $verdicts["falsy.{$falsy}"],
               expected: true
            );
      }
      yield new Assertion(
         description: 'An ordinary matching comparison still passes'
      )
         ->assert(
            actual: $verdicts['plain.match'],
            expected: true
         );
      yield new Assertion(
         description: 'An ordinary mismatching comparison still fails'
      )
         ->assert(
            actual: $verdicts['plain.mismatch'],
            expected: false
         );
      yield new Assertion(
         description: 'An unconfigured comparator still uses the caller`s expected value'
      )
         ->assert(
            actual: $verdicts['unconfigured.match'],
            expected: true
         );
      yield new Assertion(
         description: 'An unconfigured comparator handles a null on both sides'
      )
         ->assert(
            actual: $verdicts['unconfigured.null'],
            expected: true
         );

      // ---

      // @@ `fail()` resolves the same way `assert()` does — the verdicts above
      //    exercise only `assert()`, so without these the 8 `fail()` sites
      //    could go back to `??` with the spec still green
      yield new Assertion(
         description: '`Identical(null)->fail()` names null, not the sentinel'
      )
         ->assert(
            actual: (string) new Identical(null)->fail(5, Argument::Undefined, 1),
            expected: 'Failed asserting that 5 is equal to null.'
         );
      yield new Assertion(
         description: '`NotIdentical(null)->fail()` names null, not the sentinel'
      )
         ->assert(
            actual: (string) new NotIdentical(null)->fail(null, Argument::Undefined, 1),
            expected: 'Failed asserting that null is not identical to null.'
         );
      yield new Assertion(
         description: 'An unconfigured `fail()` still renders the caller`s expected value'
      )
         ->assert(
            actual: (string) new Identical()->fail(5, 6, 1),
            expected: 'Failed asserting that 5 is equal to 6.'
         );

      // ---

      // @@ The sentinel is STORED, which is what lets `resolve()` test for it
      //    instead of consulting `isSet()` through `??`. Asserted as a boolean
      //    because `assert()` rejects the sentinel itself as an `actual` value.
      yield new Assertion(
         description: 'An unconfigured comparator stores the Undefined sentinel'
      )
         ->assert(
            actual: new Identical()->expected === Argument::Undefined,
            expected: true
         );
      yield new Assertion(
         description: 'A comparator configured with null stores null'
      )
         ->assert(
            actual: new Identical(null)->expected,
            expected: null
         );
   })
);
