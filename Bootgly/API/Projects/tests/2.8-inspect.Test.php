<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Projects;


return new Test(
   description: 'Projects::inspect() reads signature metadata as tokens — never runs the file',
   test: function () {
      // ! A scratch directory of signatures
      $base = Temporaries::reserve('projects-inspect');
      $marker = "{$base}/MARKER";
      $erase = function (string $target) use (&$erase): void {
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
      $write = function (string $name, string $code) use ($base): string {
         $file = "{$base}/{$name}.Project.php";
         file_put_contents($file, "<?php\n{$code}\n");
         return $file;
      };
      if (is_dir($base) === false) {
         mkdir($base, 0o700, true);
      }
      $import = "use Bootgly\\API\\Projects\\Project;\n";
      $effect = "file_put_contents('{$marker}', 'ran', FILE_APPEND);\n";

      try {
         // @ Inert — top-level side effects never run
         $literal = Projects::inspect($write('Literal',
            "{$import}{$effect}return new Project(boot: fn () => null, exportable: true, name: 'Lit', description: 'Desc', version: '1.2.3', author: 'Ann');"
         ), 'App/Literal');

         yield assert(
            assertion: file_exists($marker) === false,
            description: 'inspecting a signature never executes its top-level code'
         );
         yield assert(
            assertion: $literal === [
               'name' => 'Lit', 'description' => 'Desc', 'version' => '1.2.3', 'author' => 'Ann',
               'exportable' => true, 'error' => '',
            ],
            description: 'literal named arguments are extracted'
         );

         // @ Positional arguments bind through the constructor order
         $positional = Projects::inspect($write('Positional',
            "{$import}return new Project(fn () => null, false, 'Pos', '', 'PDesc', '2.0.0', 'Bob');"
         ), 'App/Positional');

         yield assert(
            assertion: [$positional['name'], $positional['description'], $positional['version'], $positional['author'], $positional['exportable']]
               === ['Pos', 'PDesc', '2.0.0', 'Bob', false],
            description: 'positional arguments map name/description/version/author by position'
         );

         // @ Class resolution — namespace, alias, group, fully qualified
         $aliased = Projects::inspect($write('Aliased',
            "namespace Foo;\nuse Bootgly\\API\\{Projects\\Project as Signature};\nreturn new Signature(boot: fn () => null, exportable: \\true, name: \"A\\x41\");"
         ), 'App/Aliased');

         yield assert(
            assertion: $aliased['name'] === 'AA' && $aliased['exportable'] === true && $aliased['error'] === '',
            description: 'an aliased import resolves to Project and double-quoted escapes decode'
         );

         // @ Dynamic fields
         $dynamic = Projects::inspect($write('Dynamic',
            "{$import}{$effect}return new Project(boot: fn () => null, exportable: getenv('X') === '1', name: 'D' . 'yn', description: getenv('HOME'), version: \"v{\$v}\");"
         ), 'App/Dynamic');

         yield assert(
            assertion: $dynamic['name'] === Projects::DYNAMIC
               && $dynamic['description'] === Projects::DYNAMIC
               && $dynamic['version'] === Projects::DYNAMIC
               && $dynamic['author'] === ''
               && $dynamic['exportable'] === false
               && $dynamic['error'] === ''
               && file_exists($marker) === false,
            description: 'non-literal fields read (dynamic) and an omitted one keeps its default'
         );

         // @ A class that is not Bootgly\API\Projects\Project
         $impostor = Projects::inspect($write('Impostor',
            "namespace Evil;\nclass Project { public function __construct (mixed ...\$a) {} }\nreturn new Project(boot: fn () => null, exportable: true, name: 'Impostor');"
         ), 'App/Impostor');
         $array = Projects::inspect($write('Array', "{$effect}return ['name' => 'Array'];"), 'App/Array');

         yield assert(
            assertion: $impostor['error'] !== '' && $impostor['name'] === 'App/Impostor'
               && $array['error'] !== '' && $array['name'] === 'App/Array'
               && file_exists($marker) === false,
            description: 'a non-Project return is a marked row, never executed'
         );

         // @ A parse error is a marked row, not a thrown ParseError
         $broken = Projects::inspect($write('Broken', "return new Project(name: 'x' exportable: true);"), 'App/Broken');

         yield assert(
            assertion: $broken['name'] === 'App/Broken' && str_contains($broken['error'], 'line 2'),
            description: 'a signature that does not parse carries an error marker'
         );

         // @ Symlinked signatures — refused by inspect() (even to a valid
         //   signature) and by import() (any of a source's signatures)
         symlink("{$base}/Literal.Project.php", "{$base}/Link.Project.php");
         $linked = Projects::inspect("{$base}/Link.Project.php", 'App/Link');

         mkdir("{$base}/source");
         mkdir("{$base}/target");
         symlink("{$base}/Literal.Project.php", "{$base}/source/Source.Project.php");
         $imported = Projects::import("{$base}/source", 'Linked', ['interfaces' => ['CLI']], "{$base}/target/");
         mkdir("{$base}/pair");
         copy("{$base}/Literal.Project.php", "{$base}/pair/A.Project.php");
         symlink("{$base}/Literal.Project.php", "{$base}/pair/B.Project.php");
         $paired = Projects::import("{$base}/pair", 'Paired', ['interfaces' => ['CLI']], "{$base}/target/");

         yield assert(
            assertion: $linked['error'] === 'symlinked signature refused' && $linked['name'] === 'App/Link'
               && $imported === false && $paired === false
               && is_dir("{$base}/target/Linked") === false
               && is_dir("{$base}/target/Paired") === false
               && is_file("{$base}/target/Bootgly.projects.php") === false,
            description: 'a symlinked signature is refused by inspect() and import() — whichever signature it is'
         );

         // @ A `bootgly` launcher anywhere in a source tree — refused by import()
         mkdir("{$base}/launching/tools", 0o700, true);
         copy("{$base}/Literal.Project.php", "{$base}/launching/Launching.Project.php");
         file_put_contents("{$base}/launching/tools/bootgly", "<?php\ndefine('BOOTGLY_WORKING_BASE', __DIR__);\n");
         mkdir("{$base}/clean/bootgly", 0o700, true);
         copy("{$base}/Literal.Project.php", "{$base}/clean/Clean.Project.php");
         mkdir("{$base}/linking/bin", 0o700, true);
         symlink("{$base}/clean/bootgly", "{$base}/linking/bin/bootgly");
         $probed = Projects::probe("{$base}/launching");
         $launched = Projects::import("{$base}/launching", 'Launching', ['interfaces' => ['CLI']], "{$base}/target/");

         yield assert(
            assertion: $probed === 'tools/bootgly' && $launched === false
               && is_dir("{$base}/target/Launching") === false
               && Projects::probe("{$base}/linking") === 'bin/bootgly'
               && Projects::probe("{$base}/clean") === null,
            description: 'a source carrying a `bootgly` launcher or link is refused by import(); a plain folder named bootgly is not'
         );

         // @ A signature past the size bound is a marked row, never a fatal
         $padding = str_repeat('x', Projects::SIGNATURE);
         $large = $write('Large', "{$import}// {$padding}\nreturn new Project(boot: fn () => null, exportable: true, name: 'Large');");
         $sized = Projects::inspect($large, 'App/Large');
         $missing = Projects::inspect("{$base}/Absent.Project.php", 'App/Absent');

         yield assert(
            assertion: $sized['error'] === 'signature too large' && $sized['name'] === 'App/Large'
               && $missing['error'] === 'missing signature',
            description: 'a signature past the size bound and a missing one each carry an error marker'
         );

         // @ Literals decode exactly as PHP decodes them
         $literals = [
            "'a\\'b\\\\c\\n'",
            '"x\\e\\u{1F600}\\101\\$\\x41\\t\\v\\f\\\\"',
            '"\\u{9B}\\u{D800}\\777"',
            "b'bytes'",
         ];
         $decoded = true;
         foreach ($literals as $index => $literal) {
            $result = Projects::inspect($write("Literal{$index}",
               "{$import}return new Project(boot: fn () => null, exportable: true, name: {$literal}, description: {$literal}, version: {$literal}, author: {$literal});"
            ), "App/Literal{$index}");
            $expected = @eval("return {$literal};");
            if ($result['name'] !== $expected || $result['author'] !== $expected || $result['error'] !== '') {
               $decoded = false;
            }
         }

         yield assert(
            assertion: $decoded,
            description: 'every string literal decodes byte for byte as PHP decodes it'
         );

         // @ The extractor's contract, shape by shape
         $shapes = [
            'and' => ["{$import}return new Project(boot: fn () => null, exportable: true && false, name: 'A');", ['exportable' => false, 'error' => '']],
            'constant' => ["{$import}return new Project(boot: fn () => null, exportable: FOO, name: 'A');", ['exportable' => false, 'error' => '']],
            'empty' => ["{$import}return new Project(boot: fn () => null, exportable: true, name: '');", ['name' => 'App/empty', 'error' => '']],
            'early' => ["{$import}return 1;\nreturn new Project(boot: fn () => null, exportable: true, name: 'A');", ['name' => 'App/early']],
            'chained' => ["{$import}return new Project(boot: fn () => null, exportable: true, name: 'A')->x();", ['name' => 'App/chained']],
            'misplaced' => ["{$import}return new Project(boot: fn () => null, true, name: 'A');", ['name' => 'App/misplaced']],
            'spread' => ["{$import}return new Project(...\$arguments);", ['name' => Projects::DYNAMIC, 'error' => '']],
            'relative' => ["namespace Bootgly\\API\\Projects;\nreturn new namespace\\Project(boot: fn () => null, exportable: true, name: 'R');", ['name' => 'R', 'error' => '']],
            'function' => ["use function Bootgly\\API\\Projects\\Project;\nreturn new Project(boot: fn () => null, exportable: true, name: 'F');", ['name' => 'App/function']],
         ];
         $mismatches = [];
         foreach ($shapes as $label => [$code, $expected]) {
            $result = Projects::inspect($write("Shape{$label}", $code), "App/{$label}");
            foreach ($expected as $key => $value) {
               if ($result[$key] !== $value) {
                  $mismatches[] = "{$label}.{$key}";
               }
            }
            if (isset($expected['error']) === false && $result['error'] === '') {
               $mismatches[] = "{$label}.error";
            }
         }
         $shaped = implode(', ', $mismatches);

         yield assert(
            assertion: $mismatches === [],
            description: "exportable, the binder, class resolution and the first top-level return follow PHP's rules — mismatches: {$shaped}"
         );

         // @ One bad project never aborts a listing
         $rows = [];
         foreach (['Broken', 'Impostor', 'Link', 'Literal'] as $name) {
            $rows[$name] = Projects::inspect("{$base}/{$name}.Project.php", "App/{$name}");
         }

         yield assert(
            assertion: count($rows) === 4 && $rows['Literal']['name'] === 'Lit' && file_exists($marker) === false,
            description: 'a listing over broken and valid signatures completes, inert'
         );
      }
      finally {
         $erase($base);
      }
   }
);
