<?php

namespace Bootgly\commands;


use function array_diff;
use function assert;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function json_encode;
use function rmdir;
use function scandir;
use function unlink;
use ReflectionMethod;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
   description: 'It should refuse an option the subcommand does not implement, before anything is written',
   test: function () {
      $Admit = new ReflectionMethod(ProjectCommand::class, 'admit');
      $Command = new ProjectCommand;

      $create = ['platform', 'from', 'interfaces', 'description', 'version', 'author', 'port', 'default', 'yes'];
      $import = ['platform', 'interfaces', 'default', 'yes'];

      // # A flag this command declares for ANOTHER subcommand is refused
      //   `--dry-run` is the seeder's. The parser accepts any `--flag` and the
      //   option table only renders help, so it used to be taken and dropped —
      //   `project create --dry-run` wrote the project while the caller read the
      //   run as a preview, and the create that followed was refused for a name
      //   the preview had consumed.
      yield assert(
         assertion: $Admit->invoke($Command, $create, ['dry-run' => true]) === false,
         description: 'create() refuses --dry-run, the seeder flag'
      );

      // # …and every flag it does implement is admitted
      //   The control that keeps the guard from being a blanket refusal: if any
      //   of these were rejected, a documented invocation would start failing.
      $admitted = [];

      foreach ($create as $option) {
         if ($Admit->invoke($Command, $create, [$option => 'value']) === false) {
            $admitted[] = $option;
         }
      }

      yield assert(
         assertion: $admitted === [],
         description: 'create() admits every option it reads, refused: ' . json_encode($admitted)
      );

      // # The global flags are always admitted
      //   They are merged into every command by Command::__construct, and the
      //   parser stores short ones as counters (`-v` becomes `v => 1`).
      yield assert(
         assertion: $Admit->invoke($Command, $create, ['help' => true, 'h' => 1, 'v' => 2]) === true,
         description: 'the global flags are admitted whatever the subcommand'
      );

      // # A narrower subcommand refuses what a wider one accepts
      //   `--from` is a creation source; import takes its source from the URL.
      yield assert(
         assertion: $Admit->invoke($Command, $import, ['from' => 'scratch']) === false
            && $Admit->invoke($Command, $create, ['from' => 'scratch']) === true,
         description: 'import() refuses --from while create() accepts it'
      );

      // # The guard runs before anything is written
      //   Asserting the refusal alone would leave the call site uncovered: a
      //   create() that never consults the guard passes every check above. This
      //   drives the real create() and asserts the project never appears — and
      //   restores the registry either way, because a regression here writes.
      $registry = Projects::CONSUMER_DIR . 'Bootgly.projects.php';
      $snapshot = is_file($registry) ? file_get_contents($registry) : null;
      $directory = Projects::CONSUMER_DIR . 'OptionGuardProbe';

      try {
         $returned = $Command->create(
            ['OptionGuardProbe'],
            ['yes' => true, 'platform' => 'none', 'interfaces' => 'CLI', 'dry-run' => true]
         );
         $created = is_dir($directory);
      }
      finally {
         // ! A regression writes; leave the repository as it was found.
         if (is_dir($directory)) {
            foreach (array_diff((array) scandir($directory), ['.', '..']) as $entry) {
               @unlink($directory . '/' . $entry);
            }
            @rmdir($directory);
         }
         if ($snapshot !== null && $snapshot !== false) {
            file_put_contents($registry, $snapshot);
         }
      }

      yield assert(
         assertion: $returned === false && $created === false,
         description: 'create() consults the guard before it writes, returned: '
            . json_encode([$returned, $created])
      );
   }
);
