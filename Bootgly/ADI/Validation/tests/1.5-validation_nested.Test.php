<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Validation;
use Bootgly\ADI\Validators\Confirmed;
use Bootgly\ADI\Validators\In;
use Bootgly\ADI\Validators\Integer;
use Bootgly\ADI\Validators\Required;


return new Test(
   description: 'It should validate nested paths and wildcards with concrete error keys',
   test: new Assertions(Case: function (): Generator {
      // @ Named paths
      $Validation = new Validation(
         source: ['address' => ['city' => 'Recife', 'zip' => 'x']],
         rules: ['address.city' => [new Required], 'address.zip' => [new Integer], 'address.state' => [new Required]]
      );

      yield new Assertion(description: 'Named paths validate nested values and key errors by path')
         ->expect($Validation->errors)
         ->to->be([
            'address.zip' => ['address.zip must be an integer.'],
            'address.state' => ['address.state is required.'],
         ])
         ->assert();

      // @ Missing, null and empty containers make the path missing (as flat keys)
      $results = [];
      foreach ([[], ['address' => null], ['address' => ''], ['address' => []]] as $source) {
         $results[] = new Validation($source, ['address.city' => [new Required], 'address.zip' => [new Integer]])->errors;
      }

      yield new Assertion(description: 'A missing/null/empty container: Required fails, optional rules skip')
         ->expect($results)
         ->to->be(array_fill(0, 4, ['address.city' => ['address.city is required.']]))
         ->assert();

      // @ Present non-array container fails closed, once
      $Validation = new Validation(
         source: ['address' => 5],
         rules: ['address.city' => [new Required], 'address.zip' => [new Integer]]
      );

      yield new Assertion(description: 'A scalar container is one error at the container, rules not run')
         ->expect($Validation->errors)
         ->to->be(['address' => ['address must be an array.']])
         ->assert();

      // @ Wildcards
      $Validation = new Validation(
         source: ['items' => [['id' => 1], ['id' => 'x'], [], 'scalar', ['id' => 2, 'tags' => ['a', 'z']]]],
         rules: ['items.*.id' => [new Required, new Integer], 'items.*.tags.*' => [new In(['a', 'b'])]]
      );

      yield new Assertion(description: 'Wildcard errors are keyed by the concrete index')
         ->expect($Validation->errors)
         ->to->be([
            'items.3' => ['items.3 must be an array.'],
            'items.1.id' => ['items.1.id must be an integer.'],
            'items.2.id' => ['items.2.id is required.'],
            'items.4.tags.1' => ['items.4.tags.1 must be one of the allowed values.'],
         ])
         ->assert();

      // @ Wildcard over missing / null / empty / scalar
      $results = [];
      foreach ([[], ['items' => null], ['items' => ''], ['items' => []], ['items' => 'x']] as $source) {
         $results[] = new Validation($source, ['items.*.id' => [new Required]])->errors;
      }

      yield new Assertion(description: 'A wildcard expands nothing when missing/blank and fails on a scalar')
         ->expect($results)
         ->to->be([[], [], [], [], ['items' => ['items must be an array.']]])
         ->assert();

      // @ Keyed arrays (form fields) and keys that need escaping
      $Validation = new Validation(
         source: ['rows' => ['first' => ['n' => 'x'], 'a.b' => ['n' => 'y'], '*' => ['n' => 1]]],
         rules: ['rows.*.n' => [new Integer]]
      );

      yield new Assertion(description: 'Wildcards walk keyed arrays and escape keys in error paths')
         ->expect($Validation->errors)
         ->to->be([
            'rows.first.n' => ['rows.first.n must be an integer.'],
            'rows.a\\.b.n' => ['rows.a\\.b.n must be an integer.'],
         ])
         ->assert();

      // @ Root wildcard and literal-dot keys
      $Validation = new Validation(
         source: ['a' => '1', 'b' => 'x', 'c.d' => 'y'],
         rules: ['*' => [new Integer], 'c\\.d' => [new Required, new Integer]]
      );

      yield new Assertion(description: 'A root wildcard validates every top-level value; `\\.` names a literal-dot key')
         ->expect($Validation->errors)
         ->to->be([
            'b' => ['b must be an integer.'],
            'c\\.d' => ['c\\.d must be an integer.', 'c\\.d must be an integer.'],
         ])
         ->assert();

      yield new Assertion(description: 'A dotted rule key is a path, not the literal key')
         ->expect(new Validation(['c.d' => 'y'], ['c.d' => [new Required]])->errors)
         ->to->be(['c.d' => ['c.d is required.']])
         ->assert();

      // @ Conditions receive the concrete path and the whole source
      $Validation = new Validation(
         source: ['users' => [['password' => 'p', 'password_confirmation' => 'p'], ['password' => 'p', 'password_confirmation' => 'q']]],
         rules: ['users.*.password' => [new Required, new Confirmed]]
      );

      yield new Assertion(description: 'Confirmed resolves the nested sibling confirmation')
         ->expect($Validation->errors)
         ->to->be(['users.1.password' => ['users.1.password confirmation does not match.']])
         ->assert();

      $Validation = new Validation(
         source: ['user' => ['password' => 'p'], 'check' => ['password' => 'p']],
         rules: ['user.password' => [new Confirmed('check.password')]]
      );

      yield new Assertion(description: 'Confirmed reads an explicit confirming path from the root')
         ->expect($Validation->valid)
         ->to->be(true)
         ->assert();
   })
);
