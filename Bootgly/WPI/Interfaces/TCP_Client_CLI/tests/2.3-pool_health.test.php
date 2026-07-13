<?php

use function microtime;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Pool;


return new Specification(
   description: 'It should quarantine the pool only after the failure threshold and recover on demand',
   test: new Assertions(Case: function (): Generator {
      // ! Zero jitter keeps the retry deadline deterministic
      $Pool = new Pool();

      yield new Assertion(description: 'a fresh pool is healthy')
         ->expect($Pool->healthy)
         ->to->be(true)
         ->assert();

      // @ One failure — below the default threshold (2)
      $Pool->penalize(jitter: 0.0);

      yield new Assertion(description: 'a single failure below the threshold keeps the pool healthy')
         ->expect($Pool->healthy && $Pool->failures === 1 && $Pool->retry === 0.0)
         ->to->be(true)
         ->assert();

      // @ Second failure — reaches the threshold and quarantines
      $Pool->penalize(seconds: 5.0, jitter: 0.0);

      yield new Assertion(description: 'reaching the failure threshold quarantines the pool')
         ->expect($Pool->healthy)
         ->to->be(false)
         ->assert();

      yield new Assertion(description: 'the quarantine set a retry deadline in the future')
         ->expect($Pool->failures === 2 && $Pool->retry > microtime(true))
         ->to->be(true)
         ->assert();

      // @ recover: clear the quarantine
      $Pool->recover();

      yield new Assertion(description: 'recover() restores health and clears the failure count')
         ->expect($Pool->healthy && $Pool->failures === 0 && $Pool->retry === 0.0)
         ->to->be(true)
         ->assert();
   })
);
