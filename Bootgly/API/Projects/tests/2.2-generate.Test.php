<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
   description: 'Projects::generate() creates a project from the interface stubs',
   test: function () {
      // ! Scratch projects base
      $base = sys_get_temp_dir() . '/bootgly-test-generate-' . getmypid() . '/';
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

      // @ CLI project
      $done = Projects::generate(BOOTGLY_ROOT_DIR . 'Bootgly/commands/stubs/CLI', 'App/Console', [
         'interfaces'  => ['CLI'],
         'description' => 'A console app',
         'author'      => 'Tester',
      ], $base);

      yield assert(
         assertion: $done === true,
         description: 'generates a CLI project from scratch'
      );
      yield assert(
         assertion: is_file("{$base}App/Console/Console.Project.php") === true,
         description: 'the project file is named after the leaf'
      );

      $content = (string) file_get_contents("{$base}App/Console/Console.Project.php");
      yield assert(
         assertion: str_contains($content, "name: 'Console'") === true
            && str_contains($content, "description: 'A console app'") === true
            && str_contains($content, '__NAME__') === false,
         description: 'metadata tokens are substituted'
      );

      $registry = include "{$base}Bootgly.projects.php";
      yield assert(
         assertion: ($registry['App/Console']['interfaces'] ?? null) === ['CLI'],
         description: 'the project is registered in the allow-list'
      );

      // @ WPI project
      $done = Projects::generate(BOOTGLY_ROOT_DIR . 'Bootgly/commands/stubs/WPI', 'App/Web', [
         'interfaces' => ['WPI'],
         'port'       => '9999',
      ], $base);

      yield assert(
         assertion: $done === true
            && is_file("{$base}App/Web/Web.Project.php") === true
            && is_file("{$base}App/Web/router/router.index.php") === true
            && is_file("{$base}App/Web/router/routes/Welcome.routes.php") === true,
         description: 'generates a WPI project with its router'
      );

      $content = (string) file_get_contents("{$base}App/Web/Web.Project.php");
      yield assert(
         assertion: str_contains($content, "'9999'") === true,
         description: 'the port token is substituted'
      );

      // ! Rejections
      yield assert(
         assertion: Projects::generate(BOOTGLY_ROOT_DIR . 'Bootgly/commands/stubs/WPI', 'App/Web', ['interfaces' => ['WPI']], $base) === false,
         description: 'an existing target directory is refused'
      );
      // ! A stub whose only file cannot be read — the witness that a refused
      //   path never reaches the copy: copying it would throw, so a clean
      //   `false` proves the gates ran first. (Vacuous for root, which reads
      //   anything; the suites do not run as root.)
      $unreadable = "{$base}unreadable-stub";
      mkdir($unreadable, 0755, true);
      file_put_contents("{$unreadable}/__LEAF__.Project.php", "<?php\nreturn null;\n");
      chmod("{$unreadable}/__LEAF__.Project.php", 0000);
      $refused = [];
      foreach ([['Lib/None', []], ['Data/Sub', ['interfaces' => ['CLI']]]] as [$path, $meta]) {
         try {
            if (Projects::generate($unreadable, $path, $meta, $base) !== false) {
               $refused[] = "{$path}: accepted";
            }
         }
         catch (Throwable $Throwable) {
            $refused[] = "{$path}: " . $Throwable::class;
         }
         if (is_dir("{$base}" . explode('/', $path)[0]) === true) {
            $refused[] = "{$path}: parent created";
         }
      }
      chmod("{$unreadable}/__LEAF__.Project.php", 0644);
      yield assert(
         assertion: $refused === [],
         description: 'an empty interfaces list and a reserved root are refused before the stub is even read, found: '
            . json_encode($refused)
      );

      // @ A registry that cannot be written rolls the generated tree back —
      //   including the parent directory the call created for a nested path
      $broken = sys_get_temp_dir() . '/bootgly-test-generate-broken-' . getmypid() . '/';
      $erase(rtrim($broken, '/'));
      mkdir("{$broken}Bootgly.projects.php", 0755, true);
      file_put_contents("{$broken}Bootgly.projects.php/occupant", 'x');
      $threw = false;
      $returned = null;
      try {
         $returned = Projects::generate(BOOTGLY_ROOT_DIR . 'Bootgly/commands/stubs/CLI', 'Nested/Fresh', ['interfaces' => ['CLI']], $broken);
      }
      catch (Throwable) {
         $threw = true;
      }
      yield assert(
         assertion: ($returned === false || $threw === true) && is_dir("{$broken}Nested") === false,
         description: 'a generation whose registration fails leaves no tree behind, parent included'
      );
      $erase(rtrim($broken, '/'));

      $erase(rtrim($base, '/'));
   }
);
