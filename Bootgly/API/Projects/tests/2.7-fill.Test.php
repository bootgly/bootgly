<?php

use function Bootgly\ABI\remove_recursively;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;
use Bootgly\API\Projects\Project;


return new Test(
   description: 'Projects::generate() escapes metadata into the stub literals',
   test: function () {
      // ! Scratch projects base
      $base = sys_get_temp_dir() . '/bootgly-test-fill-' . getmypid() . '/';
      $erase = function (string $target) use (&$erase): void {
         // ? A link is removed as a link — never followed into its target
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);
            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach ((array) scandir($target) as $entry) {
            if ($entry === '.' || $entry === '..') {
               continue;
            }
            $erase("{$target}/{$entry}");
         }
         rmdir($target);
      };
      $erase(rtrim($base, '/'));
      mkdir($base, 0755, true);

      // @ An attack payload in author — the stubs own the quotes, so the value
      //   is escaped for the single-quoted literal it lands in and must
      //   round-trip byte-exact through the generated signature
      $payload = "O'Neil \\'; system('id');";
      $done = Projects::generate(
         BOOTGLY_ROOT_DIR . 'Bootgly/commands/stubs/CLI',
         'Attack',
         ['interfaces' => ['CLI'], 'author' => $payload],
         $base
      );
      $signature = "{$base}Attack/Attack.Project.php";

      yield assert(
         assertion: $done === true && is_file($signature) === true,
         description: 'a quoted author generates a project'
      );

      $parses = true;
      try {
         token_get_all((string) file_get_contents($signature), TOKEN_PARSE); // @phpstan-ignore function.resultUnused
      }
      catch (ParseError) {
         $parses = false;
      }
      yield assert(
         assertion: $parses === true,
         description: 'the emitted signature parses'
      );

      $Generated = include $signature;
      yield assert(
         assertion: $Generated instanceof Project && $Generated->author === $payload,
         description: 'the author round-trips byte-exact through the literal'
      );

      // @ The WPI port literal — `(int) '__PORT__'` must survive a hostile value
      $done = Projects::generate(
         BOOTGLY_ROOT_DIR . 'Bootgly/commands/stubs/WPI',
         'PortProbe',
         ['interfaces' => ['WPI'], 'port' => "80'); die();//"],
         $base
      );
      $parses = true;
      try {
         token_get_all(
            (string) file_get_contents("{$base}PortProbe/PortProbe.Project.php"),
            TOKEN_PARSE
         ); // @phpstan-ignore function.resultUnused
      }
      catch (ParseError) {
         $parses = false;
      }
      yield assert(
         assertion: $done === true && $parses === true,
         description: 'a hostile port value cannot break out of the port literal'
      );

      // @ Every string token is escaped, not just the author — a payload in
      //   all four fields must come back byte-exact from the signature
      $done = Projects::generate(
         BOOTGLY_ROOT_DIR . 'Bootgly/commands/stubs/CLI',
         'Quad',
         [
            'interfaces'  => ['CLI'],
            'name'        => $payload,
            'description' => $payload,
            'version'     => $payload,
            'author'      => $payload,
         ],
         $base
      );
      $Generated = $done === true ? include "{$base}Quad/Quad.Project.php" : null;
      yield assert(
         assertion: $Generated instanceof Project
            && $Generated->name === $payload
            && $Generated->description === $payload
            && $Generated->version === $payload
            && $Generated->author === $payload,
         description: 'name, description, version and author all round-trip byte-exact'
      );

      // @ Control characters would survive the literal byte for byte — they
      //   are refused because every listing that renders them would break,
      //   and NOTHING lands on disk
      $done = Projects::generate(
         BOOTGLY_ROOT_DIR . 'Bootgly/commands/stubs/CLI',
         'CtrlProbe',
         ['interfaces' => ['CLI'], 'description' => "bad\x01desc"],
         $base
      );
      $registry = is_file("{$base}Bootgly.projects.php")
         ? (array) include "{$base}Bootgly.projects.php"
         : [];
      yield assert(
         assertion: $done === false
            && is_dir("{$base}CtrlProbe") === false
            && array_key_exists('CtrlProbe', $registry) === false,
         description: 'control characters in metadata are refused with zero residue'
      );

      // @ Defense-in-depth: a stub whose signature emits unparseable PHP is
      //   refused BEFORE registration, and the stub copy leaves with it
      $stub = "{$base}broken-stub";
      mkdir($stub, 0755, true);
      file_put_contents("{$stub}/__LEAF__.Project.php", "<?php return [\n");
      $done = Projects::generate($stub, 'Broken', ['interfaces' => ['CLI']], $base);
      $registry = is_file("{$base}Bootgly.projects.php")
         ? (array) include "{$base}Bootgly.projects.php"
         : [];
      yield assert(
         assertion: $done === false && array_key_exists('Broken', $registry) === false,
         description: 'an unparseable emitted signature is never registered'
      );
      yield assert(
         assertion: is_dir("{$base}Broken") === false,
         description: 'the refused copy is removed with the refusal'
      );

      // @ The helper that removal relies on treats a DIRECTORY symlink as a
      //   link — following it would recurse into a tree the refusal never
      //   created. (copy_recursively() dereferences links, so the shape can
      //   only be planted directly.)
      $outside = "{$base}outside-dir/keep.txt";
      mkdir("{$base}outside-dir", 0755, true);
      file_put_contents($outside, 'must survive');
      mkdir("{$base}linked", 0755, true);
      symlink("{$base}outside-dir", "{$base}linked/link");
      remove_recursively("{$base}linked");
      yield assert(
         assertion: is_dir("{$base}linked") === false && file_get_contents($outside) === 'must survive',
         description: 'remove_recursively() removes a directory symlink as a link, never following it'
      );

      $erase(rtrim($base, '/'));
   }
);
