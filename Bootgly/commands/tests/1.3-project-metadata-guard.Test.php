<?php

namespace Bootgly\commands;


use const TOKEN_PARSE;
use function array_diff;
use function array_key_exists;
use function assert;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function json_encode;
use function mkdir;
use function rename;
use function rmdir;
use function scandir;
use function token_get_all;
use function unlink;
use ParseError;
use ReflectionProperty;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
   description: 'It should refuse invalid metadata and store quoted metadata safely, end to end',
   test: function () {
      $Command = new ProjectsCommand;

      // ! The real registry is mutated by the success section — snapshot it,
      //   restore it either way, and drop the in-process memo so later suites
      //   re-read the restored file rather than the mutated map.
      $registry = Projects::CONSUMER_DIR . 'Bootgly.projects.php';
      $snapshot = is_file($registry) ? file_get_contents($registry) : null;
      $Memo = new ReflectionProperty(Projects::class, 'registry');

      $erase = function (string $target) use (&$erase): void {
         if (is_file($target) === true) {
            unlink($target);
            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
            $erase("{$target}/{$entry}");
         }
         rmdir($target);
      };

      try {
         // # A non-numeric --port is refused before anything is written
         //   `(int) 'not-a-port'` casts to 0, so the accepted flag used to
         //   produce a server silently bound on port 0.
         $returned = $Command->create(
            ['PortProbe'],
            ['yes' => true, 'platform' => 'none', 'interfaces' => 'WPI', 'port' => 'not-a-port']
         );
         yield assert(
            assertion: $returned === false && is_dir(Projects::CONSUMER_DIR . 'PortProbe') === false,
            description: 'create() refuses a non-numeric --port before writing'
         );

         // ? …and the rule means what its message says: 1–65535, no leading zeros
         $leaked = [];
         foreach (['0', '65536', '080'] as $port) {
            $returned = $Command->create(
               ['PortProbe'],
               ['yes' => true, 'platform' => 'none', 'interfaces' => 'WPI', 'port' => $port]
            );
            if ($returned !== false || is_dir(Projects::CONSUMER_DIR . 'PortProbe') === true) {
               $leaked[] = $port;
               $erase(Projects::CONSUMER_DIR . 'PortProbe');
            }
         }
         yield assert(
            assertion: $leaked === [],
            description: 'out-of-range ports are refused, accepted: ' . json_encode($leaked)
         );

         // # Control characters in metadata are refused with their own message
         $returned = $Command->create(
            ['CtrlProbe'],
            ['yes' => true, 'platform' => 'none', 'interfaces' => 'CLI', 'description' => "bad\x01desc"]
         );
         yield assert(
            assertion: $returned === false && is_dir(Projects::CONSUMER_DIR . 'CtrlProbe') === false,
            description: 'create() refuses a control character in --description before writing'
         );

         // # A path outside the naming alphabet is refused on BOTH routes
         $returned = $Command->create(
            ["Bad'Path"],
            ['yes' => true, 'platform' => 'none', 'interfaces' => 'CLI']
         );
         yield assert(
            assertion: $returned === false && is_dir(Projects::CONSUMER_DIR . "Bad'Path") === false,
            description: 'the scratch route refuses a quoted project path'
         );

         // ! The --from route refreshes a user-level copy by ERASING it first —
         //   a planted directory is the only witness that the refusal came
         //   before the erase rather than after it.
         $planted = Projects::CONSUMER_DIR . "Bad'Two";
         mkdir($planted, 0755, true);
         file_put_contents("{$planted}/marker", 'survives');
         $returned = $Command->create(
            ["Bad'Two"],
            ['yes' => true, 'platform' => 'none', 'from' => 'Demo/CLI']
         );
         yield assert(
            assertion: $returned === false && is_file("{$planted}/marker") === true,
            description: 'the --from route refuses a quoted project path before its erase'
         );

         // ? None of the refusals may have touched the registry
         $current = is_file($registry) ? file_get_contents($registry) : null;
         yield assert(
            assertion: $current === $snapshot,
            description: 'a refused create leaves the registry byte-identical'
         );

         // # An ordinary quoted author is stored safely, end to end
         $returned = $Command->create(
            ['AuthorProbe'],
            ['yes' => true, 'platform' => 'none', 'interfaces' => 'CLI', 'author' => "O'Neil"]
         );
         $signature = Projects::CONSUMER_DIR . 'AuthorProbe/AuthorProbe.Project.php';

         $parses = true;
         try {
            token_get_all((string) file_get_contents($signature), TOKEN_PARSE); // @phpstan-ignore function.resultUnused
         }
         catch (ParseError) {
            $parses = false;
         }
         yield assert(
            assertion: $returned === true && $parses === true,
            description: "create() with author O'Neil emits a signature that parses"
         );

         // ? The re-emitted registry itself must parse and carry the entry —
         //   read through the file, never through the memoised map
         $reemitted = (array) include $registry;
         yield assert(
            assertion: array_key_exists('AuthorProbe', $reemitted) === true,
            description: 'the re-emitted registry parses and carries the new entry'
         );
      }
      finally {
         // ! A regression writes; leave the repository as it was found —
         //   every probe is erased, including the ones a refusal never creates,
         //   because the refusal is exactly what a regression removes.
         foreach (['PortProbe', 'CtrlProbe', "Bad'Path", "Bad'Two", 'AuthorProbe'] as $probe) {
            $erase(Projects::CONSUMER_DIR . $probe);
         }
         if ($snapshot !== null && $snapshot !== false) {
            file_put_contents($registry, $snapshot);
         }
         // ? A FRESH kit has no registry to snapshot, and the success section
         //   above is what emits it — so restoring "nothing" would leave this
         //   case's own probe in the allow-list forever, and the next run of
         //   this suite red. Re-emit what the run legitimately stocked, minus
         //   the probes. (`register()` writes; nothing in the tree deletes.)
         else if (is_file($registry) === true) {
            $loaded = include $registry;
            /** @var array<string,array{interfaces?:array<string>}> $entries */
            $entries = is_array($loaded) ? $loaded : [];
            foreach (['PortProbe', 'CtrlProbe', "Bad'Path", "Bad'Two", 'AuthorProbe'] as $probe) {
               unset($entries[$probe]);
            }

            // ! Build the replacement BESIDE the registry and move it into
            //   place: unlinking first would leave the kit with no allow-list
            //   at all if any `register()` refused an entry or threw.
            $restore = "{$registry}.restore";
            if (is_file($restore) === true) {
               unlink($restore);
            }

            $kept = true;
            foreach ($entries as $probe => $meta) {
               if (Projects::register($probe, $meta, $restore) === false) {
                  $kept = false;

                  break;
               }
            }

            // ? The probes must go whatever happens: leaving the original in
            //   place would keep exactly the phantom entry this exists to drop.
            //   An empty survivor set is a legitimate outcome — the registry
            //   then holds nothing but probes — so an absent replacement means
            //   "remove it", never "keep what is there".
            $moved = $kept === true
                  && is_file($restore) === true
                  && rename($restore, $registry) === true;

            // ? Anything short of a completed move drops the registry: keeping
            //   one this case's own probes are in is the permanently-red state
            //   the re-emission exists to prevent, and a fresh kit rebuilds it
            //   on the next create.
            if ($moved === false) {
               if (is_file($restore) === true) {
                  unlink($restore);
               }
               if (is_file($registry) === true) {
                  unlink($registry);
               }
            }
         }
         $Memo->setValue(null, null);
      }
   }
);
