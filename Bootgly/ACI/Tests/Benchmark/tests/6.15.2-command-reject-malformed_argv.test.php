<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\commands\TestCommand;


return new Specification(
   description: 'It should reject malformed process argument vectors',
   test: new Assertions(Case: function (): Generator
   {
      $Reflection = new ReflectionClass(TestCommand::class);
      $Normalize = $Reflection->getMethod('normalize');
      $valid = $Normalize->invoke(null, ['bootgly', 'test', '--format=json']);

      $nonArrayRejected = false;
      try {
         $Normalize->invoke(null, 'bootgly test');
      }
      catch (RuntimeException) {
         $nonArrayRejected = true;
      }

      $nonStringRejected = false;
      try {
         $Normalize->invoke(null, ['bootgly', 7, 'test']);
      }
      catch (RuntimeException) {
         $nonStringRejected = true;
      }

      yield new Assertion(
         description: 'Valid argv is preserved and malformed argv fails closed',
         fallback: 'Process argv was altered or malformed input was accepted!'
      )
         ->expect(
            $valid === ['bootgly', 'test', '--format=json']
               && $nonArrayRejected
               && $nonStringRejected,
            Op::Identical,
            true,
         )
         ->assert();
   })
);
