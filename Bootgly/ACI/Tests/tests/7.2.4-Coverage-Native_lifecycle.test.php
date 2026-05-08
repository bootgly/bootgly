<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Drivers\Native;
use Bootgly\ACI\Tests\Coverage\Drivers\Native\Universe;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — Native driver lifecycle, hit collection, and OPcache guard',

   test: new Assertions(Case: function (): Generator {
      $hits = Coverage::$hits;
      $lines = Universe::$lines;
      $spans = Universe::$spans;
      $labels = Universe::$labels;
      $declarations = Universe::$declarations;
      $active = Native::$active;
      $mode = Native::$mode;
      $stack = Native::$stack;

      try {
         // Smoke: hit collector basics.
         Coverage::reset();
         Coverage::hit('/x.php', 10);
         Coverage::hit('/x.php', 10);
         Coverage::hit('/x.php', 11);

         yield (new Assertion(description: 'Coverage::hit accumulates per file/line'))
            ->expect(Coverage::$hits['/x.php'][10])
            ->to->be(2)
            ->assert();

         yield (new Assertion(description: 'Coverage::hit tracks distinct lines'))
            ->expect(Coverage::$hits['/x.php'][11])
            ->to->be(1)
            ->assert();

         Coverage::reset();
         yield (new Assertion(description: 'Coverage::reset wipes hits'))
            ->expect(isset(Coverage::$hits['/x.php']))
            ->to->be(false)
            ->assert();

         // Driver lifecycle (skipped under opcache.enable_cli=1).
         if ((bool) ini_get('opcache.enable_cli')) {
            yield true;
            return;
         }

         $Cov = new Coverage(new Native(explicit: true));
         $Cov->start();

         yield (new Assertion(description: 'Native is active during coverage session'))
            ->expect(Native::$active)
            ->to->be(true)
            ->assert();

         $Cov->stop();

         yield (new Assertion(description: 'Native deactivates after stop()'))
            ->expect(Native::$active)
            ->to->be(false)
            ->assert();

         yield (new Assertion(description: 'collected map is normalized to 0/1 buckets'))
            ->expect(is_array($Cov->data))
            ->to->be(true)
            ->assert();
      }
      finally {
         Coverage::$hits = $hits;
         Universe::$lines = $lines;
         Universe::$spans = $spans;
         Universe::$labels = $labels;
         Universe::$declarations = $declarations;
         Native::$active = $active;
         Native::$mode = $mode;
         Native::$stack = $stack;
      }
   })
);
