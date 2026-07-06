<?php

use const BOOTGLY_STORAGE_DIR;
use const BOOTGLY_VERSION;

use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should recompile only when the source mtime reaches the cache mtime',
   test: new Assertions(function () {
      // !
      $file = sys_get_temp_dir() . '/bootgly-' . uniqid() . '.template.php';
      $cache = BOOTGLY_STORAGE_DIR . 'cache/templates/' . sha1(BOOTGLY_VERSION . $file) . '.php';

      // @ Valid
      // First render compiles
      file_put_contents($file, 'version A');

      $Template1 = new Template(new File($file));
      $Template1->render();

      yield new Assertion(
         description: 'First render compiles the template',
         fallback: "Template #1: output does not match: \n`" . $Template1->output . '`'
      )
         ->assert(
            actual: $Template1->output,
            expected: 'version A'
         );

      // Source older than cache -> warm cache is reused (content change ignored)
      file_put_contents($file, 'version B');
      touch($file, time() - 10);

      $Template2 = new Template(new File($file));
      $Template2->render();

      yield new Assertion(
         description: 'Older source keeps the warm cache',
         fallback: "Template #2: output does not match: \n`" . $Template2->output . '`'
      )
         ->assert(
            actual: $Template2->output,
            expected: 'version A'
         );

      // Source newer than cache -> recompiled
      touch($file, time() + 10);

      $Template3 = new Template(new File($file));
      $Template3->render();

      yield new Assertion(
         description: 'Newer source invalidates the cache',
         fallback: "Template #3: output does not match: \n`" . $Template3->output . '`'
      )
         ->assert(
            actual: $Template3->output,
            expected: 'version B'
         );

      // @ Invalid
      // ...

      // ! Cleanup
      @unlink($file);
      @unlink($cache);
   })
);
