<?php


use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'The `not` modifier negates its own expectation and no other',
   test: new Assertions(Case: function (): Generator
   {
      /**
       * Runs one chain through the real ExA API and reports whether it FAILED —
       * never whether it should have.
       */
      $failing = static function (callable $chain): bool {
         try {
            $chain(new Assertion(description: 'probe'));

            return false;
         }
         catch (AssertionError) {
            return true;
         }
      };

      // ! Every probe below is a real chain, and half of them fail on purpose.
      //   `Assertion::fail()` clobbers the process-wide Vars debug config
      //   (TASSERT-2), so without this snapshot the spec would silence the
      //   failure diagnostics of every suite that runs after it.
      $print = Vars::$print;
      $debug = Vars::$debug;
      $exit = Vars::$exit;
      $traces = Vars::$traces;
      $labels = Vars::$labels;

      // @@ Collect every verdict BEFORE yielding: a failing assertion aborts
      //    the generator, which would strand the restore below
      $verdicts = [];
      foreach ([
         // # The leak itself — an expectation AFTER a `not`
         //   `not be 6` holds and `be 999` does not, so the chain must fail
         'and.after_not.false'
            => static fn (Assertion $A) => $A->expect(5)->not->to->be(6)->and->to->be(999)->assert(),
         //   both hold, so the chain must pass
         'and.after_not.true'
            => static fn (Assertion $A) => $A->expect(5)->not->to->be(6)->and->to->be(5)->assert(),
         //   `or`: neither branch holds (5 IS 5, and 5 is not 999) — must fail
         'or.after_not.both_false'
            => static fn (Assertion $A) => $A->expect(5)->not->to->be(5)->or->to->be(999)->assert(),

         // # The negation itself must keep working
         'not.alone.holds'
            => static fn (Assertion $A) => $A->expect(5)->not->to->be(6)->assert(),
         'not.alone.broken'
            => static fn (Assertion $A) => $A->expect(5)->not->to->be(5)->assert(),

         // # `not` LAST: nothing precedes it to corrupt, so it never regressed
         'not.last'
            => static fn (Assertion $A) => $A->expect(5)->to->be(5)->and->not->to->be(9)->assert(),

         // # Chains with no `not` at all must be untouched
         'and.no_not.broken'
            => static fn (Assertion $A) => $A->expect(5)->to->be(5)->and->to->be(999)->assert(),
         'or.no_not.holds'
            => static fn (Assertion $A) => $A->expect(5)->to->be(999)->or->to->be(5)->assert(),

         // # Two `not`s: each one negates its own expectation
         'not.twice.hold'
            => static fn (Assertion $A) => $A->expect(5)->not->to->be(6)->and->not->to->be(7)->assert(),
         'not.twice.second_broken'
            => static fn (Assertion $A) => $A->expect(5)->not->to->be(6)->and->not->to->be(5)->assert(),
      ] as $name => $Chain) {
         $verdicts[$name] = $failing($Chain);
      }

      // !
      Vars::$print = $print;
      Vars::$debug = $debug;
      Vars::$exit = $exit;
      Vars::$traces = $traces;
      Vars::$labels = $labels;

      // ---

      // @@ The leak
      yield new Assertion(
         description: '`->not->to->be(6)->and->to->be(999)` fails: 5 is not 999'
      )
         ->assert(
            actual: $verdicts['and.after_not.false'],
            expected: true
         );
      yield new Assertion(
         description: '`->not->to->be(6)->and->to->be(5)` passes: both hold'
      )
         ->assert(
            actual: $verdicts['and.after_not.true'],
            expected: false
         );
      yield new Assertion(
         description: '`->not->to->be(5)->or->to->be(999)` fails: neither branch holds'
      )
         ->assert(
            actual: $verdicts['or.after_not.both_false'],
            expected: true
         );

      // @@ The negation itself
      yield new Assertion(
         description: 'A lone `not` still passes when the expectation is false'
      )
         ->assert(
            actual: $verdicts['not.alone.holds'],
            expected: false
         );
      yield new Assertion(
         description: 'A lone `not` still fails when the expectation is true'
      )
         ->assert(
            actual: $verdicts['not.alone.broken'],
            expected: true
         );
      yield new Assertion(
         description: 'A `not` in last position keeps behaving'
      )
         ->assert(
            actual: $verdicts['not.last'],
            expected: false
         );

      // @@ Chains without `not`
      yield new Assertion(
         description: '`and` without `not` still fails on a false expectation'
      )
         ->assert(
            actual: $verdicts['and.no_not.broken'],
            expected: true
         );
      yield new Assertion(
         description: '`or` without `not` still passes on one true branch'
      )
         ->assert(
            actual: $verdicts['or.no_not.holds'],
            expected: false
         );

      // @@ Two negations in one chain
      yield new Assertion(
         description: 'Two `not`s pass when both expectations are false'
      )
         ->assert(
            actual: $verdicts['not.twice.hold'],
            expected: false
         );
      yield new Assertion(
         description: 'The second `not` still fails on a true expectation'
      )
         ->assert(
            actual: $verdicts['not.twice.second_broken'],
            expected: true
         );
   })
);
