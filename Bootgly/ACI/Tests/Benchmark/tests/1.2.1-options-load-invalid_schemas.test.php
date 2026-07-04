<?php

use Generator;
use RuntimeException;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Configs\Options;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should reject invalid schemas',
   test: new Assertions(Case: function (): Generator
   {
      // ! Helper — capture the RuntimeException message of a failing load
      $capture = static function (string $fixture): string {
         try {
            Options::load(__DIR__ . "/fixtures/{$fixture}");
         }
         catch (RuntimeException $Exception) {
            return $Exception->getMessage();
         }

         return '';
      };

      yield new Assertion(
         description: 'Legacy help-text map is rejected with a migration hint',
         fallback: 'Legacy schema format not rejected!'
      )
         ->expect(str_contains($capture('legacy.options.php'), 'migrate to a schema entry'), Op::Identical, true)
         ->assert();

      yield new Assertion(
         description: 'Unknown schema key is rejected',
         fallback: 'Unknown schema key not rejected!'
      )
         ->expect(str_contains($capture('unknown-key.options.php'), "Unknown schema key 'sweep'"), Op::Identical, true)
         ->assert();

      yield new Assertion(
         description: 'Invalid type is rejected',
         fallback: 'Invalid type not rejected!'
      )
         ->expect(str_contains($capture('bad-type.options.php'), "requires a valid 'type'"), Op::Identical, true)
         ->assert();

      yield new Assertion(
         description: 'vary on a non-int type is rejected',
         fallback: 'vary on non-int not rejected!'
      )
         ->expect(str_contains($capture('vary-string.options.php'), "'vary' requires type 'int'"), Op::Identical, true)
         ->assert();
   })
);
