<?php


use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Expectations\Waiters\RunTimeout;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Waiters: the `wait()` budget is microseconds and is actually enforced',
   skip: function_exists('pcntl_fork') === false
      || function_exists('posix_kill') === false,
   test: new Assertions(Case: function (): Generator
   {
      /**
       * Runs one waited chain and reports the verdict together with the
       * wall-clock time it took, in milliseconds.
       *
       * @return array{passes: bool, elapsed: float}
       */
      $waiting = static function (callable $chain): array {
         $initial = microtime(true);

         try {
            $chain(new Assertion(description: 'probe'));
            $passes = true;
         }
         catch (AssertionError) {
            $passes = false;
         }

         return [
            'passes'  => $passes,
            'elapsed' => (microtime(true) - $initial) * 1000,
         ];
      };

      // ! Probes that blow their budget fail on purpose, and
      //   `Assertion::fail()` clobbers the process-wide Vars debug config
      //   (TASSERT-2), so the statics are restored before the first yield.
      $print = Vars::$print;
      $debug = Vars::$debug;
      $exit = Vars::$exit;
      $traces = Vars::$traces;
      $labels = Vars::$labels;

      // @@ 20 ms of budget around a 400 ms callable. Read as microseconds it is
      //    blown twentyfold; read as seconds it is 20,000 s and can never fire,
      //    which is exactly the state this pins against. Collected before any
      //    yield, since a failing assertion aborts the generator.
      $over = $waiting(static fn (Assertion $A) => $A
         ->expect(static fn () => usleep(400000))
         ->to->call()
         ->to->wait(20000)
         ->assert()
      );

      // @@ The same callable duration, two seconds of budget: comfortably inside
      $within = $waiting(static fn (Assertion $A) => $A
         ->expect(static fn () => usleep(10000))
         ->to->call()
         ->to->wait(2000000)
         ->assert()
      );

      // @@ The `wait(<Closure>)` form routes its verdict to the subassertion
      //    instead of a budget, and receives the CONVERTED duration — so it was
      //    never affected, and must stay both working and failable
      $subassertion = $waiting(static fn (Assertion $A) => $A
         ->expect(static fn () => usleep(10000))
         ->to->call()
         ->to->wait(function (float $duration): Assertion {
            /** @var Assertion $this */
            return $this->to->delimit(1000, 2000000);
         })
         ->assert()
      );
      $subassertionOut = $waiting(static fn (Assertion $A) => $A
         ->expect(static fn () => usleep(10000))
         ->to->call()
         ->to->wait(function (float $duration): Assertion {
            /** @var Assertion $this */
            return $this->to->delimit(3000000, 9000000);
         })
         ->assert()
      );

      // !
      Vars::$print = $print;
      Vars::$debug = $debug;
      Vars::$exit = $exit;
      Vars::$traces = $traces;
      Vars::$labels = $labels;

      // ---

      yield new Assertion(
         description: 'A callable that blows its microsecond budget fails'
      )
         ->assert(
            actual: $over['passes'],
            expected: false
         );

      // @@ The verdict alone cannot tell a killed callable from one that ran to
      //    completion and was judged after the fact. The clock can: 400 ms of
      //    sleep cut off inside 250 ms means the parent's guard fired.
      yield new Assertion(
         description: 'The parent kills the over-budget callable instead of waiting it out'
      )
         ->assert(
            actual: $over['elapsed'] < 250,
            expected: true
         );

      yield new Assertion(
         description: 'A callable inside its budget still passes'
      )
         ->assert(
            actual: $within['passes'],
            expected: true
         );

      // @@ The subassertion form
      yield new Assertion(
         description: 'The `wait(<Closure>)` form still passes on a duration in range'
      )
         ->assert(
            actual: $subassertion['passes'],
            expected: true
         );
      yield new Assertion(
         description: 'The `wait(<Closure>)` form still fails on a duration out of range'
      )
         ->assert(
            actual: $subassertionOut['passes'],
            expected: false
         );

      // ---

      // @@ Only the COMPARISON was normalised: the budget the reader is shown
      //    stays the microsecond value they wrote
      yield new Assertion(
         description: '`fail()` still reports the budget in the unit it was given'
      )
         ->assert(
            actual: (string) new RunTimeout(20000, [])->fail(static fn () => null, 0, 1),
            expected: 'Failed asserting that the callable executed within 20000 microseconds.'
         );
   })
);
