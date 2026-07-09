<?php

use Generator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environments;
use Bootgly\API\Workables\Server;


return new Specification(
   description: 'It should boot in Development and Staging environments without a production bootstrap file',
   test: new Assertions(Case: function (): Generator {
      // !
      $Previous = Server::$Environment;
      $production = Server::$production;
      Server::$production = '';

      // @
      Server::$Environment = Environments::Development;
      $development = Server::boot();

      Server::$Environment = Environments::Staging;
      $staging = Server::boot();

      Server::$Environment = Environments::Production;
      $productionBoot = Server::boot();

      // ? Restore static state (suite runs in-process)
      Server::$Environment = $Previous;
      Server::$production = $production;

      // :
      yield new Assertion(
         description: 'Development boot should return true (no bootstrap file required)',
      )
         ->expect($development)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Staging boot should return true (no bootstrap file required)',
      )
         ->expect($staging)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Production boot should keep returning true (regression)',
      )
         ->expect($productionBoot)
         ->to->be(true)
         ->assert();
   })
);
