<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;
use Bootgly\API\Projects\Project;


return new Test(
   description: 'Projects::generate() escapes metadata into the stub literals',
   test: function () {
      // ! Scratch projects base
      $base = sys_get_temp_dir() . '/bootgly-test-fill-' . getmypid() . '/';
      $erase = function (string $target) use (&$erase): void {
         if (is_file($target) === true) {
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

      // @ Control characters cannot be escaped into a single-quoted literal
      //   faithfully — they are refused, and NOTHING lands on disk
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
      //   refused BEFORE registration (the copied tree stays, inert)
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

      $erase(rtrim($base, '/'));
   }
);
