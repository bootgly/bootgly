<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Validation;
use Bootgly\ADI\Validators\Boolean;
use Bootgly\ADI\Validators\Confirmed;
use Bootgly\ADI\Validators\Date;
use Bootgly\ADI\Validators\In;
use Bootgly\ADI\Validators\URL;


return new Specification(
   description: 'It should validate data with the In, URL, Boolean, Date and Confirmed rules',
   test: new Assertions(Case: function (): Generator {
      // @ Valid source.
      $Validation = new Validation(
         source: [
            'status' => 'active',
            'website' => 'https://bootgly.com',
            'enabled' => 'true',
            'published_at' => '2026-07-09',
            'birthday' => 'next friday',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'PIN' => '1234',
            'PIN_check' => '1234',
         ],
         rules: [
            'status' => [new In(['active', 'archived'])],
            'website' => [new URL],
            'enabled' => [new Boolean],
            'published_at' => [new Date('Y-m-d')],
            'birthday' => [new Date],
            'password' => [new Confirmed],
            'PIN' => [new Confirmed(field: 'PIN_check')],
         ]
      );

      yield new Assertion(description: 'Valid source should pass')
         ->expect($Validation->valid)
         ->to->be(true)
         ->assert();

      // @ Boolean accepted set.
      $Validation = new Validation(
         source: ['a' => true, 'b' => 0, 'c' => '1', 'd' => 'false'],
         rules: [
            'a' => [new Boolean],
            'b' => [new Boolean],
            'c' => [new Boolean],
            'd' => [new Boolean],
         ]
      );

      yield new Assertion(description: 'Boolean accepts bool, 0/1 ints and "0"/"1"/"true"/"false" strings')
         ->expect($Validation->valid)
         ->to->be(true)
         ->assert();

      // @ Invalid source.
      $Validation = new Validation(
         source: [
            'status' => 'deleted',
            'level' => '3',
            'website' => 'not a url',
            'enabled' => 'yes',
            'published_at' => '2026-02-30',
            'birthday' => 'not a date',
            'password' => 'secret123',
            'password_confirmation' => 'different',
            'token' => 'abc',
         ],
         rules: [
            'status' => [new In(['active', 'archived'])],
            'level' => [new In([1, 2, 3])],
            'website' => [new URL],
            'enabled' => [new Boolean],
            'published_at' => [new Date('Y-m-d')],
            'birthday' => [new Date],
            'password' => [new Confirmed],
            'token' => [new Confirmed],
         ]
      );

      yield new Assertion(description: 'Invalid source should fail')
         ->expect($Validation->valid)
         ->to->be(false)
         ->assert();

      yield new Assertion(description: 'In rejects values outside the allowlist')
         ->expect($Validation->errors['status'][0] ?? null)
         ->to->be('status must be one of the allowed values.')
         ->assert();

      yield new Assertion(description: 'In is strict by default ("3" is not int 3)')
         ->expect($Validation->errors['level'][0] ?? null)
         ->to->be('level must be one of the allowed values.')
         ->assert();

      yield new Assertion(description: 'URL rejects malformed URLs')
         ->expect($Validation->errors['website'][0] ?? null)
         ->to->be('website must be a valid URL.')
         ->assert();

      yield new Assertion(description: 'Boolean rejects strings outside the pinned set')
         ->expect($Validation->errors['enabled'][0] ?? null)
         ->to->be('enabled must be a boolean.')
         ->assert();

      yield new Assertion(description: 'Date with a format rejects calendar overflows (Feb 30)')
         ->expect($Validation->errors['published_at'][0] ?? null)
         ->to->be('published_at must be a valid date in the format Y-m-d.')
         ->assert();

      yield new Assertion(description: 'Date without a format rejects unparseable strings')
         ->expect($Validation->errors['birthday'][0] ?? null)
         ->to->be('birthday must be a valid date.')
         ->assert();

      yield new Assertion(description: 'Confirmed rejects a mismatched confirmation')
         ->expect($Validation->errors['password'][0] ?? null)
         ->to->be('password confirmation does not match.')
         ->assert();

      yield new Assertion(description: 'Confirmed rejects a missing confirmation field')
         ->expect($Validation->errors['token'][0] ?? null)
         ->to->be('token confirmation does not match.')
         ->assert();

      // @ Non-strict In.
      $Validation = new Validation(
         source: ['level' => '3'],
         rules: ['level' => [new In([1, 2, 3], strict: false)]]
      );

      yield new Assertion(description: 'In with strict: false compares loosely')
         ->expect($Validation->valid)
         ->to->be(true)
         ->assert();

      // @ Custom messages.
      $Validation = new Validation(
         source: ['website' => 'nope'],
         rules: ['website' => [new URL(message: 'Give me a real URL.')]]
      );

      yield new Assertion(description: 'Custom message overrides the default')
         ->expect($Validation->errors['website'][0] ?? null)
         ->to->be('Give me a real URL.')
         ->assert();
   })
);
