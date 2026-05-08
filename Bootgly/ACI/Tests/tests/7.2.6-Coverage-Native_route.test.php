<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Drivers\Native;
use Bootgly\ACI\Tests\Coverage\Drivers\Native\Universe;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — Native route guards and instruments eligible files',

   test: new Assertions(Case: function (): Generator {
      $file = dirname(__DIR__) . '/NativeRouteFixture.php';
      $written = file_put_contents($file, "<?php\n\$value = 1;\n\$value++;\n");
      if ($written === false) {
         throw new RuntimeException('Could not write Native route fixture.');
      }

      $file = str_replace('\\', '/', realpath($file) ?: $file);
      $active = Native::$active;
      $mode = Native::$mode;
      $stack = Native::$stack;
      $isolated = $active === false;

      try {
         if ($isolated === false) {
            yield true;
            return;
         }

         if ($isolated) {
            Coverage::reset();
            Universe::reset();
         }

         $previouslyHit = isset(Coverage::$hits[$file]);
         Native::$active = false;
         Native::$stack = [];

         yield (new Assertion(description: 'route() refuses files while Native is inactive'))
            ->expect(Native::route($file))
            ->to->be(false)
            ->assert();

         yield (new Assertion(description: 'inactive route() does not include the target file'))
            ->expect(isset(Coverage::$hits[$file]))
            ->to->be($previouslyHit)
            ->assert();

         if ((bool) ini_get('opcache.enable_cli')) {
            yield true;
            return;
         }

         $Coverage = new Coverage(new Native(explicit: true));
         $Coverage->start();
         try {
            $routed = Native::route($file);
         }
         finally {
            $Coverage->stop();
         }

         yield (new Assertion(description: 'route() loads eligible files while Native is active'))
            ->expect($routed)
            ->to->be(true)
            ->assert();

         yield (new Assertion(description: 'route() leaves the Native include stack balanced'))
            ->expect(Native::$stack)
            ->to->be([])
            ->assert();

         yield (new Assertion(description: 'routed file appears in collected coverage data'))
            ->expect(isset($Coverage->data[$file]))
            ->to->be(true)
            ->assert();
      }
      finally {
         Native::$active = $active;
         Native::$mode = $mode;
         Native::$stack = $stack;

         if ($isolated) {
            Coverage::reset();
            Universe::reset();
         }

         if (is_file($file)) {
            unlink($file);
         }
      }
   })
);
