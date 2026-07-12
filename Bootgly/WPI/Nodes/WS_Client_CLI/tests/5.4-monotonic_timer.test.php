<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI;


return new Specification(
   description: 'It should dispatch the nearest monotonic timer without sockets',
   test: new Assertions(Case: function (): Generator {
      // @ Construction installs a fresh Select scheduler for this isolated probe.
      $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
      $fired = false;
      $wallFired = false;

      TCP_Client_CLI::$Event->defer(
         microtime(true) + 1.0,
         static function () use (&$wallFired): void {
            $wallFired = true;
         }
      );
      $started = (int) hrtime(true);
      TCP_Client_CLI::$Event->defer(
         $started + 20_000_000,
         static function () use (&$fired): void {
            $fired = true;
            TCP_Client_CLI::$Event->destroy();
         }
      );
      TCP_Client_CLI::$Event->loop();
      $elapsed = ((int) hrtime(true) - $started) / 1_000_000_000;

      yield new Assertion(description: 'the monotonic callback fires before the later wall timer')
         ->expect($fired && ! $wallFired)
         ->to->be(true)
         ->assert();
      yield new Assertion(description: 'an idle scheduler honors sub-second monotonic precision')
         ->expect($elapsed >= 0.015 && $elapsed < 0.3)
         ->to->be(true)
         ->assert();

      // Keep the client live through the assertions; its event owner is the
      // behavior under test.
      unset($Client);
   })
);
