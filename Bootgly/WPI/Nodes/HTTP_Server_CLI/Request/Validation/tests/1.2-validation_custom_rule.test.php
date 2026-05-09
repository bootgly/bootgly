<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation\Condition;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Required;


return new Specification(
   description: 'It should support custom validation rules',
   test: new Assertions(Case: function (): Generator {
      // ! Custom rule object.
      $Custom = new class extends Condition {
         /**
          * @param array<string,mixed> $data
          */
         public function validate (string $field, mixed $value, array $data): bool
         {
            return $value === 'safe';
         }

         public function format (string $field): string
         {
            return "{$field} must be safe.";
         }
      };

      // @ Invalid custom rule path.
      $Validation = new Validation(
         source: ['token' => 'unsafe'],
         rules: ['token' => [new Required, $Custom]]
      );

      yield new Assertion(description: 'Custom rule should fail invalid values')
         ->expect($Validation->valid)
         ->to->be(false)
         ->assert();

      yield new Assertion(description: 'Custom rule should expose its message')
         ->expect($Validation->errors['token'][0])
         ->to->be('token must be safe.')
         ->assert();

      // @ Valid custom rule path.
      $Validation = new Validation(
         source: ['token' => 'safe'],
         rules: ['token' => [new Required, $Custom]]
      );

      yield new Assertion(description: 'Custom rule should pass valid values')
         ->expect($Validation->valid)
         ->to->be(true)
         ->assert();

   })
);
