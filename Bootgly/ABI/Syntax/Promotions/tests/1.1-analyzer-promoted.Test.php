<?php

use Bootgly\ABI\Syntax\Promotions\Analyzer;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Analyzer: every promoted constructor parameter is reported, whatever its modifier; plain parameters and other functions are not',
   test: function () {
      // ! Analyzer::analyze() reads a path, so each probe is a scratch file
      $dir = Temporaries::reserve('syntax-promotions');

      try {

         $Analyzer = new Analyzer();

         $flag = function (string $body) use ($Analyzer, $dir): array {
            $source = "<?php\n\nnamespace Demo;\n\n{$body}\n";
            $file = $dir . '/probe-' . md5($source) . '.php';
            file_put_contents($file, $source);

            $found = [];
            foreach ($Analyzer->analyze($file)->issues as $Issue) {
               $found[] = "{$Issue->kind} {$Issue->symbol}";
            }

            return $found;
         };

         // @@ Every modifier kind promotes
         $modifiers = [
            'public', 'protected', 'private',
            'public(set)', 'protected(set)', 'private(set)',
            'readonly', 'private readonly', 'protected(set) readonly',
         ];
         foreach ($modifiers as $modifier) {
            $found = $flag("class P { public function __construct ({$modifier} int \$x) {} }");
            yield assert(
               assertion: $found === ["{$modifier} \$x"],
               description: "A `{$modifier}` parameter must be reported as promoted, got: " . json_encode($found)
            );
         }

         // @ Several parameters: each promoted one, once, in order — a plain one between them is not
         $found = $flag(
            'class P { public function __construct (public int $a, int $plain = 0, private ?string $b = null, protected(set) array $c = []) {} }'
         );
         yield assert(
            assertion: $found === ['public $a', 'private $b', 'protected(set) $c'],
            description: 'Each promoted parameter must be reported once, in order, got: ' . json_encode($found)
         );

         // @ A default value with parentheses does not leak its tokens into the list
         $found = $flag(
            'class P { public function __construct (public \Foo $f = new \Foo(1, 2), string $s = \'a,b\') {} }'
         );
         yield assert(
            assertion: $found === ['public $f'],
            description: 'Nested parentheses and commas in defaults must not confuse the walk, got: ' . json_encode($found)
         );

         // @ Case does not matter to PHP, so it does not matter here
         $found = $flag('class P { public function __CONSTRUCT (private int $x) {} }');
         yield assert(
            assertion: $found === ['private $x'],
            description: 'A constructor named in another case is still the constructor, got: ' . json_encode($found)
         );

         // @@ Not promotion
         $negatives = [
            'a plain constructor'         => 'class P { public function __construct (int $x, string $y = "") {} }',
            'an empty constructor'        => 'class P { public function __construct () {} }',
            'a non-constructor method'    => 'class P { public function run (public int $x) {} }',
            'a property declaration'      => 'class P { public int $x; private readonly string $y; public function __construct () { $this->x = 1; } }',
            'a constructor body'          => 'class P { public function __construct () { $f = function (int $a) {}; } }',
            'a plain function'            => 'function __construct (int $x) {}',
         ];
         foreach ($negatives as $label => $body) {
            $found = $flag($body);
            yield assert(
               assertion: $found === [],
               description: "{$label} must not be reported, got: " . json_encode($found)
            );
         }

         // @ Line and type
         $source = "<?php\n\nnamespace Demo;\n\nclass P {\n   public function __construct (\n      public int \$x\n   ) {}\n}\n";
         $file = $dir . '/probe-position.php';
         file_put_contents($file, $source);
         $Issues = $Analyzer->analyze($file)->issues;
         yield assert(
            assertion: count($Issues) === 1 && $Issues[0]->line === 7
               && $Issues[0]->type === 'promoted_property' && $Issues[0]->offset === -1,
            description: 'The issue must carry the parameter line and be report-only, got: ' . json_encode($Issues)
         );

      }
      finally {
         foreach (glob($dir . '/*.php') ?: [] as $probe) {
            @unlink($probe);
         }
         @rmdir($dir);
      }
   }
);
