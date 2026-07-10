<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return new Specification(
   description: 'It should enforce the final-status contract across every code() range',
   test: new Assertions(Case: function (): Generator {
      $throws = static function (Closure $call): bool {
         try {
            $call();
         }
         catch (InvalidArgumentException) {
            return true;
         }

         return false;
      };

      // @ Below-100 garbage, the whole informational class (mapped or
      //   not) and unmapped final values — all fail loud with the same
      //   stable exception, never a silent fallback
      foreach ([-1, 0, 99, 100, 101, 103, 199, 226, 999] as $code) {
         yield new Assertion(
            description: "code({$code}) throws",
         )
            ->expect($throws(static fn () => (new Response)->code($code)))
            ->to->be(true)
            ->assert();
      }

      // @ Constructor and __invoke funnel through code() — same contract
      yield new Assertion(
         description: 'new Response(103) throws',
      )
         ->expect($throws(static fn () => new Response(103)))
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: '$Response(code: 101) throws',
      )
         ->expect($throws(static fn () => (new Response)(code: 101)))
         ->to->be(true)
         ->assert();

      // @ One-way policy: code() is the SINGLE write path — there is no
      //   magic setter, so ANY external property write (valid final
      //   statuses included) dies on PHP's own protected-property Error:
      //   native fail-loud, zero magic-method opcode cost
      $denied = static function (int $code): bool {
         try {
            $Response = new Response;
            /** @phpstan-ignore assign.readOnlyProperty (the runtime guard under test) */
            $Response->code = $code;
         }
         catch (Error) {
            return true;
         }

         return false;
      };

      foreach ([0, 103, 200, 404, 600] as $code) {
         yield new Assertion(
            description: "\$Response->code = {$code} is denied (protected property)",
         )
            ->expect($denied($code))
            ->to->be(true)
            ->assert();
      }

      // @ Mapped final statuses stay accepted and install the full status
      $accepted = true;
      foreach ([200, 204, 304, 308, 404, 500, 511] as $code) {
         $Response = (new Response)->code($code);
         $accepted = $accepted && $Response->code === $code;
      }

      yield new Assertion(
         description: 'Every mapped final status is installed unchanged',
      )
         ->expect($accepted)
         ->to->be(true)
         ->assert();
   })
);
