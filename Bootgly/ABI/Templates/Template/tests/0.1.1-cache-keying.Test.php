<?php


use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should key compiled caches by path (file) and by content (inline)',
   test: new Assertions(function () {
      // !
      $storage = BOOTGLY_STORAGE_DIR . 'cache/templates/';
      // The key formula is deliberately NOT rebuilt here: it also carries the compiler
      // identity (see BUGS TPL-15), and a spec that duplicates it pins the bytes of the
      // salt instead of the invariant the description states. What is observable — and
      // what actually matters — is which renders share a cache entry and which do not.
      $snapshot = static function () use ($storage): array {
         return array_flip(glob($storage . '*.php') ?: []);
      };
      $created = static function (array $before) use ($snapshot): array {
         return array_keys(array_diff_key($snapshot(), $before));
      };

      $first = sys_get_temp_dir() . '/bootgly-' . uniqid() . '.template.php';
      $second = sys_get_temp_dir() . '/bootgly-' . uniqid() . '.template.php';
      $caches = [];

      // @ Valid
      // File template -> one cache entry
      file_put_contents($first, '@> $a;');

      $before = $snapshot();
      $Template1 = new Template(new File($first));
      $Template1->render(['a' => 'first']);
      $caches = $created($before);

      yield new Assertion(
         description: 'File template compiles to exactly one cache entry',
         fallback: 'Cache entries created: ' . json_encode($caches)
      )
         ->assert(
            actual: count($caches),
            expected: 1
         );

      // A different path with IDENTICAL content -> its own entry (keyed by path)
      file_put_contents($second, '@> $a;');

      $before = $snapshot();
      $Template2 = new Template(new File($second));
      $Template2->render(['a' => 'second']);
      $entries = $created($before);
      $caches = [...$caches, ...$entries];

      yield new Assertion(
         description: 'Same content at another path gets its own cache entry',
         fallback: 'Cache entries created: ' . json_encode($entries)
      )
         ->assert(
            actual: count($entries),
            expected: 1
         );

      // The same path again -> no new entry
      $before = $snapshot();
      $Template3 = new Template(new File($first));
      $Template3->render(['a' => 'again']);

      yield new Assertion(
         description: 'Re-rendering the same path reuses its cache entry',
         fallback: 'Cache entries created: ' . json_encode($created($before))
      )
         ->assert(
            actual: $created($before),
            expected: []
         );

      // File template edited -> same cache path is overwritten (no orphans)
      file_put_contents($first, '@> $a; edited');
      touch($first, time() + 2);

      $before = $snapshot();
      $Template4 = new Template(new File($first));
      $Template4->render(['a' => 'fourth']);

      yield new Assertion(
         description: 'Edited file template overwrites the same cache path',
         fallback: "Template #4: output does not match: \n`" . $Template4->output . '`'
      )
         ->assert(
            actual: $Template4->output,
            expected: 'fourth edited'
         );
      yield new Assertion(
         description: 'Editing a file template leaves no orphan cache entry',
         fallback: 'Cache entries created: ' . json_encode($created($before))
      )
         ->assert(
            actual: $created($before),
            expected: []
         );

      // Inline template -> keyed by content
      $inline = '@> $b;' . uniqid();

      $before = $snapshot();
      $Template5 = new Template($inline);
      $Template5->render(['b' => 'inline']);
      $entries = $created($before);
      $caches = [...$caches, ...$entries];

      yield new Assertion(
         description: 'Inline template compiles to exactly one cache entry',
         fallback: 'Cache entries created: ' . json_encode($entries)
      )
         ->assert(
            actual: count($entries),
            expected: 1
         );

      // The same content again -> no new entry; different content -> its own
      $before = $snapshot();
      $Template6 = new Template($inline);
      $Template6->render(['b' => 'again']);

      yield new Assertion(
         description: 'Re-rendering the same inline content reuses its cache entry',
         fallback: 'Cache entries created: ' . json_encode($created($before))
      )
         ->assert(
            actual: $created($before),
            expected: []
         );

      $before = $snapshot();
      $Template7 = new Template($inline . ' more');
      $Template7->render(['b' => 'other']);
      $entries = $created($before);
      $caches = [...$caches, ...$entries];

      yield new Assertion(
         description: 'Different inline content gets its own cache entry',
         fallback: 'Cache entries created: ' . json_encode($entries)
      )
         ->assert(
            actual: count($entries),
            expected: 1
         );

      // @ Invalid
      // ...

      // ! Cleanup
      @unlink($first);
      @unlink($second);
      foreach ($caches as $cache) {
         @unlink($cache);
      }
   })
);
