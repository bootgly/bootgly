<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Projects;


return new Specification(
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
         assertion: is_file("{$base}App/Console/Console.project.php") === true,
         description: 'the project file is named after the leaf'
      );

      $content = (string) file_get_contents("{$base}App/Console/Console.project.php");
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
            && is_file("{$base}App/Web/Web.project.php") === true
            && is_file("{$base}App/Web/router/router.index.php") === true
            && is_file("{$base}App/Web/router/routes/Welcome.php") === true,
         description: 'generates a WPI project with its router'
      );

      $content = (string) file_get_contents("{$base}App/Web/Web.project.php");
      yield assert(
         assertion: str_contains($content, "'9999'") === true,
         description: 'the port token is substituted'
      );

      // ! Rejections
      yield assert(
         assertion: Projects::generate(BOOTGLY_ROOT_DIR . 'Bootgly/commands/stubs/WPI', 'App/Web', ['interfaces' => ['WPI']], $base) === false,
         description: 'an existing target directory is refused'
      );
      yield assert(
         assertion: Projects::generate(BOOTGLY_ROOT_DIR . 'Bootgly/commands/stubs/CLI', 'App/None', [], $base) === false,
         description: 'entries without interfaces are rejected'
      );

      $erase(rtrim($base, '/'));
   }
);
