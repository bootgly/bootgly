<?php

use Bootgly\ABI\Syntax\Nullables\Analyzer;
use Bootgly\ABI\Syntax\Nullables\Formatter;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Formatter: every `?T` becomes `null|T`, whitespace after the `?` included, and the result is still PHP',
   test: function () {
      // ! Analyzer::analyze() reads a path, so each probe is a scratch file
      $dir = Temporaries::reserve('syntax-nullables-fix');

      try {

         $Analyzer = new Analyzer();
         $Formatter = new Formatter();

         $fix = function (string $body) use ($Analyzer, $Formatter, $dir): string {
            $source = "<?php\n\nnamespace Demo;\n\n{$body}\n";
            $file = $dir . '/probe-' . md5($source) . '.php';
            file_put_contents($file, $source);

            return $Formatter->format($Analyzer->analyze($file));
         };

         // @ Several shorthands in one file — applied from the end, so no offset drifts
         $corrected = $fix(
            "class P\n{\n   public ?int \$n = null;\n   protected static ? string \$s;\n\n"
            . "   public function __construct (private readonly ?\\Foo\\Bar \$Bar) {}\n\n"
            . "   public function run (?int \$x, ?callable \$c = null): ?static\n   {\n"
            . "      \$y = \$x ? 1 : 2;\n      \$z = \$x ?? \$x?->n;\n      return null;\n   }\n}"
         );
         yield assert(
            assertion: str_contains($corrected, 'public null|int $n = null;')
               && str_contains($corrected, 'protected static null|string $s;')
               && str_contains($corrected, 'private readonly null|\Foo\Bar $Bar')
               && str_contains($corrected, 'run (null|int $x, null|callable $c = null): null|static'),
            description: 'Every shorthand must be rewritten as null|T, got: ' . json_encode($corrected)
         );
         yield assert(
            assertion: str_contains($corrected, '$y = $x ? 1 : 2;')
               && str_contains($corrected, '$z = $x ?? $x?->n;'),
            description: 'Ternary, coalesce and nullsafe must be untouched, got: ' . json_encode($corrected)
         );

         // ! The fix must always still be PHP
         $probe = $dir . '/corrected.php';
         file_put_contents($probe, $corrected);
         $process = proc_open([PHP_BINARY, '-l', $probe], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
         $output = '';
         $status = -1;
         if (is_resource($process)) {
            /** @var array<int,resource> $pipes */
            $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
         }
         yield assert(
            assertion: $status === 0,
            description: 'The rewritten file must parse, got: ' . $output
         );

         // @ A file with nothing to fix comes back byte-identical
         $clean = "class P { public function run (null|int \$x): null|int { return \$x ? \$x : null; } }";
         yield assert(
            assertion: $fix($clean) === "<?php\n\nnamespace Demo;\n\n{$clean}\n",
            description: 'A clean file must not be rewritten'
         );

         // @ The whitespace after a `?` is consumed with it
         $corrected = $fix("class P { public function run (?\tint \$x, ?\n      string \$y): void {} }");
         yield assert(
            assertion: str_contains($corrected, 'run (null|int $x, null|string $y)'),
            description: 'A tab or a newline after the `?` must be consumed, got: ' . json_encode($corrected)
         );

      }
      finally {
         foreach (glob($dir . '/*.php') ?: [] as $path) {
            @unlink($path);
         }
         @rmdir($dir);
      }
   }
);
