<?php

use Bootgly\ABI\Syntax\Nullables\Analyzer;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Analyzer: every `?T` in a parameter, return or property type is reported — and no `?` that is not one',
   test: function () {
      // ! Analyzer::analyze() reads a path, so each probe is a scratch file
      $dir = Temporaries::reserve('syntax-nullables');

      try {

         $Analyzer = new Analyzer();

         $flag = function (string $body) use ($Analyzer, $dir): array {
            $source = "<?php\n\nnamespace Demo;\n\n{$body}\n";
            $file = $dir . '/probe-' . md5($source) . '.php';
            file_put_contents($file, $source);

            $found = [];
            foreach ($Analyzer->analyze($file)->issues as $Issue) {
               $found[] = "{$Issue->kind}:{$Issue->symbol}";
            }

            return $found;
         };

         // @@ Positions — one `?T` each, by what carries it
         $positions = [
            'a parameter'                => ['class P { public function run (?int $x): void {} }', ['parameter:int']],
            'a parameter with a space'   => ['class P { public function run (? int $x): void {} }', ['parameter:int']],
            'a second parameter'         => ['class P { public function run (int $a, ?string $b): void {} }', ['parameter:string']],
            'a by-reference parameter'   => ['class P { public function run (?int &$x): void {} }', ['parameter:int']],
            'a variadic parameter'       => ['class P { public function run (?int ...$x): void {} }', ['parameter:int']],
            'an attributed parameter'    => ['class P { public function run (#[\SensitiveParameter] ?string $x): void {} }', ['parameter:string']],
            'a promoted parameter'       => ['class P { public function __construct (private readonly ?int $x) {} }', ['parameter:int']],
            'a return type'              => ['class P { public function run (): ?int { return null; } }', ['return:int']],
            'a typed class constant'     => ['class P { const ?int LIMIT = null; }', ['constant:int']],
            'a static return type'       => ['class P { public function make (): ?static { return null; } }', ['return:static']],
            'an abstract return type'    => ['abstract class P { abstract public function run (): ?iterable; }', ['return:iterable']],
            'an arrow function'          => ['class P { public function run (): void { $f = fn (?int $n): ?int => $n; } }', ['parameter:int', 'return:int']],
            'a closure'                  => ['class P { public function run (): void { $f = function (?int $n): ?int { return $n; }; } }', ['parameter:int', 'return:int']],
            'a public property'          => ['class P { public ?int $n = null; }', ['property:int']],
            'a static property'          => ['class P { protected static ?string $s; }', ['property:string']],
            'a readonly property'        => ['class P { public readonly ?array $a; }', ['property:array']],
            'an asymmetric property'     => ['class P { private(set) ?\Foo\Bar $Bar; }', ['property:\Foo\Bar']],
            'a final property'           => ['class P { final public ?self $Parent; }', ['property:self']],
            'a qualified type'           => ['class P { public function run (?Foo\Bar $x): void {} }', ['parameter:Foo\Bar']],
            'a callable type'            => ['class P { public function run (?callable $c): void {} }', ['parameter:callable']],
            'a plain function'           => ['function run (?int $x): ?int { return $x; }', ['parameter:int', 'return:int']],
         ];

         foreach ($positions as $label => [$body, $expected]) {
            $found = $flag($body);
            yield assert(
               assertion: $found === $expected,
               description: "The shorthand in {$label} must be reported as " . json_encode($expected)
                  . ', got: ' . json_encode($found)
            );
         }

         // @@ Negatives — every other `?` PHP has, next to a control that must still be found
         $control = 'public ?int $control = null; ';
         $negatives = [
            'a ternary'                  => 'public function run ($x) { return $x ? 1 : 2; }',
            'a ternary on an array'      => 'public function run ($a) { return $a[0] ? $a : []; }',
            'a ternary on a constant'    => 'public function run ($x) { return $x ? FOO : BAR; }',
            'a ternary on static'        => 'public function run ($x) { return $x instanceof static ? $x : null; }',
            'a short ternary'            => 'public function run ($x) { return $x ?: 3; }',
            'null coalescing'            => 'public function run ($x) { return $x ?? 4; }',
            'a nullsafe call'            => 'public function run ($x) { return $x?->run(); }',
            'a nullsafe property'        => 'public function run ($x) { return $x?->count; }',
            'a union with null'          => 'public function run (null|int $x): null|int { return $x; }',
            'a string'                   => "public function run () { return '?int \$x'; }",
         ];

         foreach ($negatives as $label => $body) {
            $found = $flag("class P { {$control}{$body} }");
            yield assert(
               assertion: $found === ['property:int'],
               description: "{$label} must not be reported (only the control), got: " . json_encode($found)
            );
         }

         // @ Line and offset point at the shorthand
         $source = "<?php\n\nnamespace Demo;\n\nclass P {\n   public function run (?int \$x): void {}\n}\n";
         $file = $dir . '/probe-position.php';
         file_put_contents($file, $source);
         $Issues = $Analyzer->analyze($file)->issues;
         yield assert(
            assertion: count($Issues) === 1 && $Issues[0]->line === 6
               && $Issues[0]->type === 'nullable_shorthand'
               && $source[$Issues[0]->offset] === '?',
            description: 'The issue must carry the line and the byte offset of the `?`, got: '
               . json_encode($Issues)
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
