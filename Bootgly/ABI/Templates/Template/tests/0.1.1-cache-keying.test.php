<?php

use const BOOTGLY_STORAGE_DIR;
use const BOOTGLY_VERSION;

use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should key compiled caches by path (file) and by content (inline)',
   test: new Assertions(function () {
      // !
      $storage = BOOTGLY_STORAGE_DIR . 'cache/templates/';
      $file = sys_get_temp_dir() . '/bootgly-' . uniqid() . '.template.php';

      // @ Valid
      // File template -> cache keyed by source path
      file_put_contents($file, '@> $a;');

      $Template1 = new Template(new File($file));
      $Template1->render(['a' => 'first']);

      // Cache keys are salted with the framework version (upgrade invalidation)
      $cache = $storage . sha1(BOOTGLY_VERSION . $file) . '.php';

      yield new Assertion(
         description: 'File template cache keyed by path',
         fallback: "Cache file not found at `{$cache}`"
      )
         ->assert(
            actual: is_file($cache),
            expected: true
         );

      // File template edited -> same cache path is overwritten (no orphans)
      file_put_contents($file, '@> $a; edited');
      touch($file, time() + 2);

      $Template2 = new Template(new File($file));
      $Template2->render(['a' => 'second']);

      yield new Assertion(
         description: 'Edited file template overwrites the same cache path',
         fallback: "Template #2: output does not match: \n`" . $Template2->output . '`'
      )
         ->assert(
            actual: $Template2->output,
            expected: 'second edited'
         );

      // Inline template -> cache keyed by content hash
      $inline = '@> $b;' . uniqid();

      $Template3 = new Template($inline);
      $Template3->render(['b' => 'inline']);

      $keyed = $storage . sha1(BOOTGLY_VERSION . $inline) . '.php';

      yield new Assertion(
         description: 'Inline template cache keyed by content',
         fallback: "Cache file not found at `{$keyed}`"
      )
         ->assert(
            actual: is_file($keyed),
            expected: true
         );

      // @ Invalid
      // ...

      // ! Cleanup
      @unlink($file);
      @unlink($cache);
      @unlink($keyed);
   })
);
