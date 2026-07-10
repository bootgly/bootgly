<?php

use Bootgly\ABI\Data\Language;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Validation;
use Bootgly\ADI\Validators\Minimum;
use Bootgly\ADI\Validators\Required;
use Bootgly\ADI\Validators\URL;


return new Specification(
   description: 'It should localize validation messages through the Language catalogs',
   test: new Assertions(Case: function (): Generator {
      Language::reset();
      Language::load(__DIR__ . '/catalogs');
      Language::$locale = 'pt-BR';

      $Validation = new Validation(
         source: [
            'age' => '17',
         ],
         rules: [
            'email' => [new Required],
            'age' => [new Minimum(18)],
            'site' => [new Required(message: 'Give me a real URL.')],
            'name' => [new Required(message: '{field} placeholder works.')],
         ]
      );

      // @ Catalog-backed defaults
      yield new Assertion(description: 'Default message should localize from the validation catalog')
         ->expect($Validation->errors['email'][0])
         ->to->be('email é obrigatório.')
         ->assert();

      yield new Assertion(description: 'Parameterized message should localize with substitutions')
         ->expect($Validation->errors['age'][0])
         ->to->be('age deve ser no mínimo 18.')
         ->assert();

      // @ User overrides
      yield new Assertion(description: 'Custom message without a catalog entry should stay verbatim')
         ->expect($Validation->errors['site'][0])
         ->to->be('Give me a real URL.')
         ->assert();

      yield new Assertion(description: 'Custom message should gain {field} placeholder support')
         ->expect($Validation->errors['name'][0])
         ->to->be('name placeholder works.')
         ->assert();

      // @ Reset — English defaults are byte-identical again
      Language::reset();

      $Validation = new Validation(
         source: [
            'site' => 'not-a-url',
         ],
         rules: [
            'email' => [new Required],
            'site' => [new URL(message: 'Give me a real URL.')],
         ]
      );

      yield new Assertion(description: 'Reset should restore the byte-exact English default')
         ->expect($Validation->errors['email'][0])
         ->to->be('email is required.')
         ->assert();

      yield new Assertion(description: 'Reset should keep custom messages verbatim')
         ->expect($Validation->errors['site'][0])
         ->to->be('Give me a real URL.')
         ->assert();
   })
);
