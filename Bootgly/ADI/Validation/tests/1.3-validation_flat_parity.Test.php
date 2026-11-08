<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Validation;
use Bootgly\ADI\Validators\Confirmed;
use Bootgly\ADI\Validators\Email;
use Bootgly\ADI\Validators\Integer;
use Bootgly\ADI\Validators\Minimum;
use Bootgly\ADI\Validators\Required;


return new Test(
   description: 'It should keep flat rule keys exactly as before nested paths',
   test: new Assertions(Case: function (): Generator {
      // @ Missing, null, empty string, whitespace and empty array under Required + optional rules
      $Validation = new Validation(
         source: ['b' => null, 'c' => '', 'd' => '   ', 'e' => [], 'f' => 0, 'g' => 'x'],
         rules: [
            'a' => [new Required],
            'b' => [new Required],
            'c' => [new Email],
            'd' => [new Required],
            'e' => [new Required],
            'f' => [new Required, new Integer, new Minimum(1)],
            'g' => [new Integer],
            'h' => [new Email],
         ]
      );

      yield new Assertion(description: 'Flat errors are keyed by the rule key, in rule order')
         ->expect($Validation->errors)
         ->to->be([
            'a' => ['a is required.'],
            'b' => ['b is required.'],
            'd' => ['d is required.'],
            'e' => ['e is required.'],
            'f' => ['f must be at least 1.'],
            'g' => ['g must be an integer.'],
         ])
         ->assert();

      // @ A single Condition (not a list) still works
      $Validation = new Validation(source: [], rules: ['token' => new Required]);

      yield new Assertion(description: 'A bare Condition is one rule')
         ->expect($Validation->errors)
         ->to->be(['token' => ['token is required.']])
         ->assert();

      // @ Confirmed reads the flat sibling `<field>_confirmation`
      $Validation = new Validation(
         source: ['password' => 'p', 'password_confirmation' => 'p'],
         rules: ['password' => [new Required, new Confirmed]]
      );

      yield new Assertion(description: 'Confirmed passes a matching flat confirmation')
         ->expect($Validation->valid)
         ->to->be(true)
         ->assert();

      // @ Malformed rules keep their exceptions and messages
      $messages = [];
      foreach ([['a' => 'string'], ['a' => [new Required, 'x']]] as $rules) {
         try {
            new Validation([], $rules); // @phpstan-ignore argument.type
         }
         catch (InvalidArgumentException $Exception) {
            $messages[] = $Exception->getMessage();
         }
      }

      yield new Assertion(description: 'Malformed rule shapes still throw the same messages')
         ->expect($messages)
         ->to->be([
            'Validation rules for a must be Condition objects.',
            'Validation rule for a must be a Condition object.',
         ])
         ->assert();
   })
);
