<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
   description: 'Projects::write() emits every registry value as an escaped PHP literal',
   test: function () {
      // ! Scratch registry seeded with a LEGACY quoted key — hand-registered
      //   before the naming alphabet existed, it must survive a re-emission
      //   untouched: vet() gates only the path being registered, never the
      //   entries loaded back from the file.
      $file = sys_get_temp_dir() . '/bootgly-test-emit-' . getmypid() . '.php';
      @unlink($file);
      @unlink("{$file}.tmp");
      file_put_contents(
         $file,
         "<?php\nreturn [\n   'Legacy\\'s' => ['interfaces' => ['CLI']],\n];\n"
      );

      // @ Re-emit through an ordinary registration
      yield assert(
         assertion: Projects::register('App/API', ['interfaces' => ['CLI', 'WPI']], $file) === true,
         description: 'registering beside a legacy quoted key succeeds'
      );

      $registry = include $file;
      yield assert(
         assertion: is_array($registry)
            && array_key_exists("Legacy's", $registry)
            && ($registry["Legacy's"]['interfaces'] ?? null) === ['CLI'],
         description: 'the legacy quoted key survives the re-emission as the same string'
      );
      yield assert(
         assertion: ($registry['App/API']['interfaces'] ?? null) === ['CLI', 'WPI'],
         description: 'the new entry round-trips with its interfaces list'
      );

      // @ The emission itself is escaped, not merely parseable
      $content = (string) file_get_contents($file);
      yield assert(
         assertion: str_contains($content, "\\'") === true,
         description: 'the quoted key is emitted with an escaped literal'
      );

      // @ Column alignment survives escaping — the width comes from the
      //   emitted literal, so every entry keeps its `=>` at one column
      $columns = [];
      foreach ((array) file($file) as $line) {
         // ? Entry lines only — the canonical header also carries a `=>`
         if (preg_match("#^   '#", (string) $line) !== 1) {
            continue;
         }
         $position = strpos((string) $line, ' => ');
         if ($position !== false) {
            $columns[] = $position;
         }
      }
      yield assert(
         assertion: $columns !== [] && count(array_unique($columns)) === 1,
         description: 'every emitted entry aligns its arrow at the same column, found: '
            . json_encode($columns)
      );

      // @ Tmp hygiene — the atomic write never strands its temp file
      yield assert(
         assertion: is_file("{$file}.tmp") === false,
         description: 'no .tmp file is left beside the registry'
      );

      // @ Interfaces are literals too — an apostrophe there is the same hole
      Projects::register('App/Quote', ['interfaces' => ["C'LI"]], $file);
      $registry = include $file;
      yield assert(
         assertion: ($registry['App/Quote']['interfaces'] ?? null) === ["C'LI"],
         description: 'a quoted interface name round-trips through the emission'
      );

      // @ A hand-edited registry with a malformed interfaces list is degraded,
      //   never allowed to abort the only way the CLI has of rewriting it
      file_put_contents(
         $file,
         "<?php\nreturn [\n   'Bare'  => ['interfaces' => 'CLI'],\n"
            . "   'Loose' => ['interfaces' => ['CLI', null, ['WPI']]],\n];\n"
      );
      $registered = Projects::register('App/API', ['interfaces' => ['WPI']], $file);
      $registry = include $file;
      yield assert(
         assertion: $registered === true
            && ($registry['Bare']['interfaces'] ?? null) === ['CLI']
            && ($registry['Loose']['interfaces'] ?? null) === ['CLI'],
         description: 'malformed interfaces lists are degraded to their string members'
      );

      // @ A refused install never strands the temp file — the registry path
      //   made a non-empty directory forces rename() to fail, and under the
      //   framework's handler that failure is an exception, not a false
      $blocked = sys_get_temp_dir() . '/bootgly-test-emit-blocked-' . getmypid() . '.php';
      @mkdir($blocked);
      file_put_contents("{$blocked}/occupant", 'x');
      $returned = null;
      $threw = false;
      try {
         $returned = Projects::register('App/API', ['interfaces' => ['CLI']], $blocked);
      }
      catch (Throwable) {
         $threw = true;
      }
      yield assert(
         assertion: ($returned === false || $threw === true) && is_file("{$blocked}.tmp") === false,
         description: 'a failed install is reported and leaves no .tmp behind'
      );
      @unlink("{$blocked}/occupant");
      @rmdir($blocked);
      @unlink("{$blocked}.tmp");

      // @ The shipped registry is the emitter's own output, byte for byte —
      //   so a machine rewrite of the framework checkout's tracked file is a
      //   no-op, and the emitted header is the standard license block
      $Write = new ReflectionMethod(Projects::class, 'write');
      $shipped = BOOTGLY_ROOT_DIR . 'projects/Bootgly.projects.php';
      $canonical = sys_get_temp_dir() . '/bootgly-test-emit-canonical-' . getmypid() . '.php';
      @unlink($canonical);
      $Write->invoke(null, $canonical, (array) include $shipped);
      yield assert(
         assertion: file_get_contents($canonical) === file_get_contents($shipped),
         description: 'the shipped registry is byte-identical to what write() emits for it'
      );
      @unlink($canonical);

      @unlink($file);
      @unlink("{$file}.tmp");
   }
);
