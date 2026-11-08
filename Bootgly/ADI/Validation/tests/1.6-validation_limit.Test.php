<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Validation;
use Bootgly\ADI\Validators\Integer;
use Bootgly\ADI\Validators\Required;


return new Test(
   description: 'It should bound wildcard expansion per rule key',
   test: new Assertions(Case: function (): Generator {
      yield new Assertion(description: 'The default limit is 1000 entries')
         ->expect(new Validation([], [])->limit)
         ->to->be(1000)
         ->assert();

      // @ At the limit: every entry is validated
      $Validation = new Validation(['items' => array_fill(0, 3, [])], ['items.*.id' => [new Required]], limit: 3);

      yield new Assertion(description: 'Exactly `limit` entries run the rule on each')
         ->expect(count($Validation->errors))
         ->to->be(3)
         ->assert();

      // @ Over the limit: one error, the rule is not run
      $Validation = new Validation(
         source: ['items' => array_fill(0, 4, []), 'name' => 'x'],
         rules: ['items.*.id' => [new Required], 'items.*.code' => [new Required], 'name' => [new Integer]],
         limit: 3
      );

      yield new Assertion(description: 'Over the limit: one deduplicated error at the container; other rules still run')
         ->expect($Validation->errors)
         ->to->be([
            'items' => ['items has too many items.'],
            'name' => ['name must be an integer.'],
         ])
         ->assert();

      // @ Nested wildcards count every visited entry, even ones that yield no target
      $Validation = new Validation(
         source: ['a' => array_fill(0, 2, ['b' => array_fill(0, 2, 'x')])],
         rules: ['a.*.b.*' => [new Integer]],
         limit: 5
      );

      yield new Assertion(description: 'Nested wildcards: 2 + 2×2 visits exceed a limit of 5')
         ->expect($Validation->errors)
         ->to->be(['a' => ['a has too many items.']])
         ->assert();

      $Validation = new Validation(
         source: ['a' => array_fill(0, 6, 'scalar')],
         rules: ['a.*.b.*' => [new Integer]],
         limit: 5
      );

      yield new Assertion(description: 'Container errors are dropped when the rule key overflows')
         ->expect($Validation->errors)
         ->to->be(['a' => ['a has too many items.']])
         ->assert();

      // @ A 1 MB body of `[]` entries stays bounded
      $items = json_decode('[' . rtrim(str_repeat('[],', 349525), ',') . ']', true);
      $started = hrtime(true);
      $Validation = new Validation(['items' => $items], ['items.*.id' => [new Required, new Integer]]);
      $elapsed = (hrtime(true) - $started) / 1e9;

      yield new Assertion(description: 'A 1 MB wildcard payload yields one error, not 349525')
         ->expect($Validation->errors)
         ->to->be(['items' => ['items has too many items.']])
         ->assert();

      yield new Assertion(description: 'A 1 MB wildcard payload stops at the limit (well under a second)')
         ->expect($elapsed < 1.0)
         ->to->be(true)
         ->assert();

      // @ Each rule key starts its own count
      $Validation = new Validation(
         ['items' => array_fill(0, 3, ['id' => 1, 'code' => 2])],
         ['items.*.id' => [new Integer], 'items.*.code' => [new Integer]],
         limit: 3
      );

      yield new Assertion(description: 'Two rule keys that each fit the limit both run (visits reset per rule key)')
         ->expect($Validation->errors)
         ->to->be([])
         ->assert();

      // @ Long client keys are copied into every path below them: bytes are bounded too
      $key = str_repeat('k', 200000);
      $Validation = new Validation(
         ['items' => [$key => ['tags' => array_fill(0, 999, 'x')]]],
         ['items.*.tags.*' => [new Required]]
      );

      yield new Assertion(description: 'A long outer key over a nested wildcard fails closed, not out of memory')
         ->expect($Validation->errors)
         ->to->be(['items' => ['items has too many items.']])
         ->assert();

      $Validation = new Validation(
         ['items' => [str_repeat('k', 200) => ['tags' => array_fill(0, 3, '')]]],
         ['items.*.tags.*' => [new Required]],
         limit: 4
      );

      yield new Assertion(description: 'Paths within `limit` × BYTES are validated one by one')
         ->expect(count($Validation->errors))
         ->to->be(3)
         ->assert();

      // @ Failed containers count too
      $Validation = new Validation(
         ['items' => [str_repeat('k', 200000) => ['tags' => array_fill(0, 998, 1)]]],
         ['items.*.tags.*.x' => [new Required]]
      );

      yield new Assertion(description: 'A long outer key over failed containers fails closed, not out of memory')
         ->expect($Validation->errors)
         ->to->be(['items' => ['items has too many items.']])
         ->assert();

      // @ Paths without wildcards are not budgeted: one target, named by the rule key
      $key = str_repeat('b', 300);
      $Validation = new Validation(['a' => [$key => 'x']], ["a.{$key}" => [new Required]], limit: 1);

      yield new Assertion(description: 'A long rule key without wildcards is validated even at limit 1')
         ->expect($Validation->errors)
         ->to->be([])
         ->assert();

      yield new Assertion(description: 'BYTES is 256 per entry of the limit')
         ->expect(Validation::BYTES)
         ->to->be(256)
         ->assert();

      // @ Root wildcard overflow is keyed by the rule key
      $Validation = new Validation(['a' => 1, 'b' => 2], ['*' => [new Integer]], limit: 1);

      yield new Assertion(description: 'A root wildcard overflow is keyed by the rule key')
         ->expect($Validation->errors)
         ->to->be(['*' => ['* has too many items.']])
         ->assert();

      // @ Invalid limit
      $message = '';
      try {
         new Validation([], [], limit: 0);
      }
      catch (InvalidArgumentException $Exception) {
         $message = $Exception->getMessage();
      }

      yield new Assertion(description: 'A limit below 1 is refused')
         ->expect($message)
         ->to->be('Validation limit must be at least 1.')
         ->assert();
   })
);
