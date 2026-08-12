<?php


use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should parse and validate global load selectors',
   test: new Assertions(Case: function (): Generator
   {
      $Configs = Configs::parse([
         'loads' => 'TechEmpower:3..7',
      ]);

      yield new Assertion(
         description: 'Inclusive range',
         fallback: 'Load range was not expanded inclusively!'
      )
         ->expect(
            [$Configs->loadSet, $Configs->loads],
            Op::Identical,
            ['techempower', [3, 4, 5, 6, 7]]
         )
         ->assert();

      $Configs = Configs::parse([
         'loads' => 'techempower:3..7:2',
      ]);

      yield new Assertion(
         description: 'Stepped range',
         fallback: 'Stepped load range was not expanded!'
      )
         ->expect($Configs->loads, Op::Identical, [3, 5, 7])
         ->assert();

      $Configs = Configs::parse([
         'loads' => 'techempower:7,3,5,3',
      ]);

      yield new Assertion(
         description: 'Comma list remains supported and deduplicates in order',
         fallback: 'Load list was not parsed!'
      )
         ->expect($Configs->loads, Op::Identical, [7, 3, 5])
         ->assert();

      $Configs = Configs::parse([
         'loads' => 'techempower:*',
      ]);

      yield new Assertion(
         description: 'Wildcard selects the whole set',
         fallback: 'Load wildcard no longer selects the whole set!'
      )
         ->expect($Configs->loads === null, Op::Identical, true)
         ->assert();

      $Configs = Configs::parse([
         'loads' => 'techempower:3..3',
      ]);

      yield new Assertion(
         description: 'Single-point range',
         fallback: 'Single-point load range was not preserved!'
      )
         ->expect($Configs->loads, Op::Identical, [3])
         ->assert();

      $Parse = static function (string $selector): bool {
         try {
            Configs::parse([
               'loads' => "techempower:{$selector}",
            ]);
         }
         catch (RuntimeException) {
            return true;
         }

         return false;
      };

      foreach (['7..3', '3..', '..7', '3...7', '1,3..5,7', '2abc'] as $selector) {
         yield new Assertion(
            description: "Rejects malformed selector '{$selector}'",
            fallback: "Malformed load selector '{$selector}' was accepted!"
         )
            ->expect($Parse($selector), Op::Identical, true)
            ->assert();
      }
   })
);
