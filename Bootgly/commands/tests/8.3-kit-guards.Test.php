<?php

namespace Bootgly\commands;


use function array_diff;
use function assert;
use function bin2hex;
use function count;
use function escapeshellarg;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function json_decode;
use function mkdir;
use function random_bytes;
use function rewind;
use function rmdir;
use function scandir;
use function str_contains;
use function stream_get_contents;
use function sys_get_temp_dir;
use function unlink;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Output;


/**
 * The guards: the kit's own changes block a move and are named; the user's
 * data — dirty as it may be — never does, unless the release carries that
 * very path (untracked or ignored alike: `git checkout` overwrites an
 * ignored file in silence); a submodule moved, staged or edited by hand
 * blocks too.
 */

return new Test(
   description: 'a move is refused naming the kit\'s own changes and the files the release would overwrite — never the user\'s dirty projects/',
   test: function () {
      $base = sys_get_temp_dir() . '/bootgly-kit-guards-' . getmypid() . '-' . bin2hex(random_bytes(4));
      mkdir($base, 0775, true);
      $Erase = function (string $target) use (&$Erase): void {
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);

            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
            $Erase("{$target}/{$entry}");
         }
         rmdir($target);
      };

      try {
         $fixture = (require __DIR__ . '/fixtures/kit_fixture.php')($base);
         $canon = $fixture['canon'];
         $commits = $fixture['commits'];
         $shas = $fixture['shas'];
         $Run = $fixture['run'];

         $Bind = static function (string $kit) use ($canon): KitCommand {
            return new class ($kit, $canon) extends KitCommand {
               public function __construct (string $kit, string $repository)
               {
                  parent::__construct();
                  $this->kit = $kit;
                  $this->repository = $repository;
               }

               // ! The registry and the pid files belong to the process's own kit, not the fixture's
               protected function scan (): array
               {
                  return [];
               }
            };
         };
         $Probe = static function (KitCommand $Command, array $arguments, array $options): array {
            $Host = new Output('php://memory');
            $Terminal = CLI->Terminal;
            $Restore = $Terminal->Output;
            $Terminal->Output = $Host;
            try {
               $result = $Command->run($arguments, $options);
            }
            finally {
               $Terminal->Output = $Restore;
            }
            rewind($Host->stream);
            $document = json_decode((string) stream_get_contents($Host->stream), true);

            return [$result, is_array($document) ? $document : []];
         };
         $Blocked = static function (array $document, string $what, null|string $path = null): bool {
            foreach ($document['blockers'] ?? [] as $blocker) {
               if (str_contains($blocker['what'], $what) && ($path === null || in_array($path, $blocker['paths'], true))) {
                  return true;
               }
            }

            return false;
         };

         $kit = $fixture['clone']('kit', 'refs/tags/v1.0.0-beta.1');
         $Kit = $Bind($kit);
         $Still = static fn (): bool => $Run($kit, 'rev-parse HEAD') === $commits['v1.0.0-beta.1']
            && $Run("{$kit}/Bootgly", 'rev-parse HEAD') === $shas['v1.0.0-beta.1'];

         // # The user's data is dirty every which way — and never blocks
         file_put_contents("{$kit}/projects/App/notes.txt", "edited\n");
         file_put_contents("{$kit}/projects/App/new.txt", "new\n");
         mkdir("{$kit}/projects/Other", 0775, true);
         file_put_contents("{$kit}/projects/Other/x.txt", "x\n");
         file_put_contents("{$kit}/storage/state.json", "{\"dirty\":true}\n");

         // # A tracked file of the kit, edited
         file_put_contents("{$kit}/bootgly", "#!/usr/bin/env php\n<?php // edited\n");
         [$result, $document] = $Probe($Kit, ['upgrade', 'v1.0.0-beta.2'], ['json' => true]);

         yield assert(
            assertion: $result === false && ($document['status'] ?? null) === 'refused'
               && $Blocked($document, 'uncommitted changes', 'bootgly') && count($document['blockers'] ?? []) === 1 && $Still(),
            description: 'an edited kit file is refused by name — the dirty projects/ and storage/ are not mentioned'
         );
         $Run($kit, 'checkout --quiet -- bootgly');

         // # A staged file
         file_put_contents("{$kit}/staged.txt", "staged\n");
         $Run($kit, 'add staged.txt');
         [$result, $document] = $Probe($Kit, ['upgrade', 'v1.0.0-beta.2'], ['json' => true]);

         yield assert(
            assertion: $result === false && $Blocked($document, 'uncommitted changes', 'staged.txt') && $Still(),
            description: 'a staged file is refused by name'
         );
         $Run($kit, 'reset --quiet -- staged.txt');
         unlink("{$kit}/staged.txt");

         // # An untracked file the release would overwrite — v1.0.0-beta.2 adds README.md
         file_put_contents("{$kit}/README.md", "# Mine\n");
         [$result, $document] = $Probe($Kit, ['upgrade', 'v1.0.0-beta.2'], ['json' => true]);

         yield assert(
            assertion: $result === false && $Blocked($document, 'would overwrite', 'README.md') && $Still(),
            description: 'an untracked file the release carries is refused by name'
         );
         unlink("{$kit}/README.md");

         // # An IGNORED file the release would overwrite — v2.0.0 tracks storage/seed.json
         file_put_contents("{$kit}/storage/seed.json", "{\"mine\":true}\n");
         [$result, $document] = $Probe($Kit, ['upgrade', 'v2.0.0'], ['json' => true, 'yes' => true]);

         yield assert(
            assertion: $result === false && $Blocked($document, 'would overwrite', 'storage/seed.json') && $Still()
               && file_get_contents("{$kit}/storage/seed.json") === "{\"mine\":true}\n",
            description: 'an ignored file the release carries is refused by name — git would have overwritten it in silence'
         );
         unlink("{$kit}/storage/seed.json");

         // # ...a path git would C-quote (`"caf\303\251.json"`) is matched all the same
         file_put_contents("{$kit}/storage/café.json", "MY OWN DATA\n");
         [$result, $document] = $Probe($Kit, ['upgrade', 'v2.0.0'], ['json' => true, 'yes' => true]);

         yield assert(
            assertion: $result === false && $Blocked($document, 'would overwrite', 'storage/café.json') && $Still()
               && file_get_contents("{$kit}/storage/café.json") === "MY OWN DATA\n",
            description: 'a non-ASCII path the release carries is refused by its real name — git\'s quoting does not hide it'
         );
         unlink("{$kit}/storage/café.json");

         // # A submodule moved away from the pin
         $Run("{$kit}/Bootgly", "checkout --quiet {$shas['v1.0.0-beta.2']}");
         [$result, $document] = $Probe($Kit, ['upgrade', 'v1.0.0-beta.2'], ['json' => true]);

         yield assert(
            assertion: $result === false && $Blocked($document, 'checked out away') && $Run($kit, 'rev-parse HEAD') === $commits['v1.0.0-beta.1'],
            description: 'a framework submodule checked out away from the pin is refused'
         );

         // # ...and staged as the new pin
         $Run($kit, 'add Bootgly');
         [$result, $document] = $Probe($Kit, ['upgrade', 'v1.0.0-beta.2'], ['json' => true]);

         yield assert(
            assertion: $result === false && $Blocked($document, 'is staged'),
            description: 'a staged gitlink is refused'
         );
         $Run($kit, 'reset --quiet -- Bootgly');
         $Run($kit, 'submodule update --quiet -- Bootgly');

         // # A submodule with its own changes
         file_put_contents("{$kit}/Bootgly/constant.txt", "edited\n");
         [$result, $document] = $Probe($Kit, ['upgrade', 'v1.0.0-beta.2'], ['json' => true]);

         yield assert(
            assertion: $result === false && $Blocked($document, 'Bootgly has uncommitted changes', 'constant.txt') && $Still(),
            description: 'an edited file inside the framework submodule is refused by name'
         );
         $Run("{$kit}/Bootgly", 'checkout --quiet -- constant.txt');

         // # Untracked files the release does not carry never block — even under a
         //   directory the release brings (v1.0.0 adds docs/notes.md)
         file_put_contents("{$kit}/notes.local", "kept\n");
         mkdir("{$kit}/docs", 0775, true);
         file_put_contents("{$kit}/docs/other.md", "mine\n");
         [$result, $document] = $Probe($Kit, ['upgrade', 'v1.0.0'], ['json' => true]);

         yield assert(
            assertion: $result === true && ($document['status'] ?? null) === 'moved'
               && is_file("{$kit}/notes.local") && is_file("{$kit}/docs/other.md") && is_file("{$kit}/docs/notes.md")
               && is_file("{$kit}/projects/App/new.txt")
               && $Run($kit, 'rev-parse HEAD') === $commits['v1.0.0'],
            description: 'with the kit clean of its own changes the move proceeds — unrelated untracked files (a sibling in the new docs/ included) and the dirty projects/ survive'
         );

         // # A file that appears WHILE the user is being asked is caught too — the
         //   collision check runs again right before the checkout
         $Racing = new class ($kit, $canon) extends KitCommand {
            public function __construct (string $kit, string $repository)
            {
               parent::__construct();
               $this->kit = $kit;
               $this->repository = $repository;
            }

            protected function scan (): array
            {
               return ['App (7777)'];
            }

            protected function confirm (string $question, bool $default = false): bool
            {
               // ! The "prompt": a running instance writes into the ignored storage/
               file_put_contents("{$this->kit}/storage/seed.json", "WRITTEN DURING THE PROMPT\n");

               return true;
            }
         };
         $Host = new Output('php://memory');
         $Terminal = CLI->Terminal;
         $Restore = $Terminal->Output;
         $Terminal->Output = $Host;
         try {
            $result = $Racing->run(['upgrade', 'v2.0.0'], []);
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         $spoken = (string) stream_get_contents($Host->stream);

         yield assert(
            assertion: $result === false && str_contains($spoken, 'appeared meanwhile') && str_contains($spoken, 'storage/seed.json')
               && file_get_contents("{$kit}/storage/seed.json") === "WRITTEN DURING THE PROMPT\n"
               && $Run($kit, 'rev-parse HEAD') === $commits['v1.0.0'],
            description: 'a file the release carries, created during the confirmation, blocks the checkout by name — nothing moves, the file is intact'
         );
         unlink("{$kit}/storage/seed.json");

         // # A staged file whose name is not UTF-8 still reaches the document
         //   (`escapeshellarg` drops the byte under a UTF-8 locale — the pathspec goes by file)
         file_put_contents("{$kit}/caf\xe9.txt", "latin-1\n");
         file_put_contents("{$base}/pathspec", "caf\xe9.txt");
         $Run($kit, 'add --pathspec-from-file=' . escapeshellarg("{$base}/pathspec"));
         [$result, $document] = $Probe($Kit, ['upgrade', 'v2.0.0'], ['json' => true, 'yes' => true]);
         $Run($kit, 'reset --quiet --pathspec-from-file=' . escapeshellarg("{$base}/pathspec"));
         unlink("{$kit}/caf\xe9.txt");

         yield assert(
            assertion: $result === false && ($document['status'] ?? null) === 'refused'
               && $Blocked($document, 'uncommitted changes') && count($document['blockers'][0]['paths'] ?? []) === 1,
            description: 'a blocker path that is not valid UTF-8 does not sink the JSON document — it is substituted, not thrown'
         );

         // # ...but the very file the release carries, present in that directory, blocks
         $Back = $Bind($kit);
         $Probe($Back, ['downgrade', 'v1.0.0-beta.2'], ['json' => true]);
         file_put_contents("{$kit}/docs/notes.md", "# Mine\n");
         [$result, $document] = $Probe($Kit, ['upgrade', 'v1.0.0'], ['json' => true]);

         yield assert(
            assertion: $result === false && $Blocked($document, 'would overwrite', 'docs/notes.md')
               && $Run($kit, 'rev-parse HEAD') === $commits['v1.0.0-beta.2'],
            description: 'the one file the release carries under that directory is refused by name'
         );
      }
      finally {
         $Erase($base);
      }
   }
);
