<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Drivers\Native;
use Bootgly\ACI\Tests\Coverage\Drivers\Native\Universe;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — autoboot routes autoloaded classes through Native coverage',

   test: new Assertions(Case: function (): Generator {
      if ((bool) ini_get('opcache.enable_cli')) {
         yield true;
         return;
      }

      $name = 'NativeAutobootFixture' . str_replace('.', '', uniqid('', true));
   $class = "Bootgly\\ACI\\Tests\\tests\\{$name}";
   $file = __DIR__ . "/{$name}.php";
      $source = <<<PHP
<?php

namespace Bootgly\\ACI\\Tests\\tests;

final class {$name}
{
   public static int \$value = 0;
}

{$name}::\$value = 2;
PHP;

      $written = file_put_contents($file, $source);
      if ($written === false) {
         throw new RuntimeException('Could not write Native autoboot fixture.');
      }

      $file = str_replace('\\', '/', realpath($file) ?: $file);
      $active = Native::$active;
      $mode = Native::$mode;
      $stack = Native::$stack;
      $isolated = $active === false;

      try {
         $covered = false;
         if ($isolated) {
            Coverage::reset();
            Universe::reset();

            $Coverage = new Coverage(new Native(explicit: true));
            $Coverage->start();
            try {
               $loaded = class_exists($class);
            }
            finally {
               $Coverage->stop();
            }

            $covered = isset(Universe::$lines[$file]);
         }
         else {
            $loaded = class_exists($class);
            $covered = isset(Universe::$lines[$file]);
         }

         yield (new Assertion(description: 'autoload finds the dynamic fixture class'))
            ->expect($loaded)
            ->to->be(true)
            ->assert();

         yield (new Assertion(description: 'autoboot Native hook leaves the include stack balanced'))
            ->expect(Native::$stack)
            ->to->be([])
            ->assert();

         yield (new Assertion(description: 'autoloaded class file appears in Native coverage data'))
            ->expect($covered)
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
