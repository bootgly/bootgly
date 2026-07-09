<?php

use Generator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environments;


return new Specification(
   description: 'It should map environment names to Environments cases (failing safe to Production)',
   test: new Assertions(Case: function (): Generator {
      // @
      // :
      yield new Assertion(
         description: '`development` should map to Environments::Development',
      )
         ->expect(Environments::fetch('development') === Environments::Development)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: '`staging` should map to Environments::Staging',
      )
         ->expect(Environments::fetch('staging') === Environments::Staging)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: '`test` should map to Environments::Test',
      )
         ->expect(Environments::fetch('test') === Environments::Test)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: '`production` should map to Environments::Production',
      )
         ->expect(Environments::fetch('production') === Environments::Production)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Unrecognized names should fail safe to Environments::Production',
      )
         ->expect(Environments::fetch('garbage') === Environments::Production)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Empty name should fail safe to Environments::Production',
      )
         ->expect(Environments::fetch('') === Environments::Production)
         ->to->be(true)
         ->assert();
   })
);
