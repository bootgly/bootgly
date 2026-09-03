<?php
/*
 * A miniature kit lineage for the `upgrade` / `downgrade` cases.
 *
 * `framework`: four commits f1..f4, tagged v1.0.0-beta.1, v1.0.0-beta.2,
 * v1.0.0 and v2.0.0 (annotated, as every Bootgly release tag is).
 * `canon`: the repository every kit descends from — a launcher stub, the
 * ignore file that keeps user data out of git, `.gitmodules` pinning the
 * framework, and one commit per release with the same tags; plus a
 * docs-only commit between v1.0.0 and v2.0.0 (a kit checked out there sits
 * "past" v1.0.0, as the real kit's main did after the beta.6 bump).
 *
 * Returns a closure: `(string $base) => array{...}` with the paths, the
 * commits and two population builders — `clone` (a cloned kit at a given
 * release) and `template` (a squashed single commit, no remote).
 */


return static function (string $base): array {
   // ! Pinned identity: fixtures must not depend on the machine's git config
   $G = '-c user.name=Bootgly -c user.email=tests@bootgly.local -c commit.gpgsign=false -c protocol.file.allow=always';
   $Run = static function (string $directory, string $command) use ($G): string {
      $output = [];
      exec('git -C ' . escapeshellarg($directory) . " {$G} {$command} 2>/dev/null", $output);

      return $output[0] ?? '';
   };

   // # The framework: f1..f4, one tag each
   $framework = "{$base}/framework";
   mkdir($framework, 0775, true);
   $Run($framework, 'init --quiet -b main');
   $shas = [];
   $tags = ['v1.0.0-beta.1', 'v1.0.0-beta.2', 'v1.0.0', 'v2.0.0'];
   mkdir("{$framework}/Bootgly/commands", 0775, true);
   foreach ($tags as $index => $tag) {
      file_put_contents("{$framework}/autoboot.php", "<?php // {$tag}\n");
      file_put_contents("{$framework}/constant.txt", "constant\n");
      $Run($framework, 'add autoboot.php constant.txt');
      // ! The command itself ships from the second release on — the first predates it
      if ($tag !== 'v1.0.0-beta.1') {
         file_put_contents("{$framework}/Bootgly/commands/KitCommand.php", "<?php // {$tag}\n");
         $Run($framework, 'add Bootgly/commands/KitCommand.php');
      }
      $Run($framework, "commit --quiet -m {$tag}");
      $shas[$tag] = $Run($framework, 'rev-parse HEAD');
      $Run($framework, "tag -a {$tag} -m {$tag}");
   }

   // # The canonical kit: one commit per release, the framework pinned at that release
   $canon = "{$base}/canon";
   mkdir($canon, 0775, true);
   $Run($canon, 'init --quiet -b main');
   file_put_contents("{$canon}/bootgly", "#!/usr/bin/env php\n<?php // launcher\n");
   file_put_contents("{$canon}/.gitignore", "/projects/\n/storage/\n");
   $Run($canon, 'add bootgly .gitignore');
   $Run($canon, "submodule add --quiet " . escapeshellarg($framework) . ' Bootgly');
   $commits = [];
   $notes = ['v1.0.0-beta.1' => 'Beta one', 'v1.0.0-beta.2' => 'Beta two', 'v1.0.0' => "Stable\n\nThe first stable release.", 'v2.0.0' => 'Two'];
   foreach ($tags as $tag) {
      $Run("{$canon}/Bootgly", "checkout --quiet {$shas[$tag]}");
      $Run($canon, 'add Bootgly');
      if ($tag !== 'v1.0.0-beta.1') {
         file_put_contents("{$canon}/README.md", "# Kit {$tag}\n");
         $Run($canon, 'add README.md');
      }
      // ! v1.0.0 brings a directory the kit did not have; v2.0.0 tracks a file
      //   under an IGNORED directory (as the kit does with `!@/autoboot.php`) —
      //   what `git checkout` would silently write over a user's copy
      if ($tag === 'v1.0.0') {
         mkdir("{$canon}/docs", 0775, true);
         file_put_contents("{$canon}/docs/notes.md", "# Notes {$tag}\n");
         $Run($canon, 'add docs/notes.md');
      }
      if ($tag === 'v2.0.0') {
         mkdir("{$canon}/storage", 0775, true);
         file_put_contents("{$canon}/storage/seed.json", "{\"seed\":true}\n");
         file_put_contents("{$canon}/storage/café.json", "{\"release\":true}\n");
         $Run($canon, 'add -f storage/seed.json storage/café.json');
      }
      $Run($canon, "commit --quiet -m " . escapeshellarg("bump Bootgly to {$tag}"));
      $commits[$tag] = $Run($canon, 'rev-parse HEAD');
      $Run($canon, "tag -a {$tag} -m " . escapeshellarg($notes[$tag]));

      // ! A docs-only commit right after v1.0.0 — main past a release
      if ($tag === 'v1.0.0') {
         file_put_contents("{$canon}/README.md", "# Kit v1.0.0 — docs\n");
         $Run($canon, 'add README.md');
         $Run($canon, 'commit --quiet -m docs');
         $commits['past'] = $Run($canon, 'rev-parse HEAD');
      }
   }

   // # Populations
   $Clone = static function (string $name, string $at) use ($base, $canon, $Run): string {
      $kit = "{$base}/{$name}";
      exec('git -c protocol.file.allow=always clone --quiet '
         . escapeshellarg($canon) . ' ' . escapeshellarg($kit) . ' 2>/dev/null');
      $Run($kit, "checkout --quiet {$at}");
      $Run($kit, 'submodule update --quiet --init Bootgly');
      // ! The user's data — ignored by the kit, never touched by a move
      mkdir("{$kit}/projects/App", 0775, true);
      file_put_contents("{$kit}/projects/App/notes.txt", "mine\n");
      if (is_dir("{$kit}/storage") === false) {
         mkdir("{$kit}/storage", 0775, true);
      }
      file_put_contents("{$kit}/storage/state.json", "{}\n");

      return $kit;
   };
   $Template = static function (string $name, string $tag) use ($base, $canon, $framework, $shas, $Run): string {
      $kit = "{$base}/{$name}";
      mkdir($kit, 0775, true);
      $Run($kit, 'init --quiet -b main');
      foreach (['bootgly', '.gitignore'] as $file) {
         file_put_contents("{$kit}/{$file}", (string) file_get_contents("{$canon}/{$file}"));
      }
      $Run($kit, 'add bootgly .gitignore');
      $Run($kit, 'submodule add --quiet ' . escapeshellarg($framework) . ' Bootgly');
      $Run("{$kit}/Bootgly", "checkout --quiet {$shas[$tag]}");
      $Run($kit, 'add Bootgly');
      $Run($kit, 'commit --quiet -m "Initial commit"');
      mkdir("{$kit}/projects/App", 0775, true);
      file_put_contents("{$kit}/projects/App/notes.txt", "mine\n");

      return $kit;
   };

   return [
      'framework' => $framework,
      'canon' => $canon,
      'shas' => $shas,
      'commits' => $commits,
      'tags' => $tags,
      'run' => $Run,
      'clone' => $Clone,
      'template' => $Template,
   ];
};
