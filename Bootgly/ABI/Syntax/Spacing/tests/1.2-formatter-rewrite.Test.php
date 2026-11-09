<?php

use Bootgly\ABI\Syntax\Analyzers\Issue;
use Bootgly\ABI\Syntax\Analyzers\Result;
use Bootgly\ABI\Syntax\Spacing;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Spacing: format() rewrites every gap but a commented one, keeps the source valid and is idempotent; a stale offset is left alone',
   test: function () {
      $Spacing = new Spacing;
      $dir = Temporaries::reserve('syntax-spacing');

      try {
         // @ Rewrite the rejected fixture
         $Result = $Spacing->analyze(__DIR__ . '/fixtures/rejected.php');
         $rewritten = $Spacing->format($Result);

         $valid = true;
         try {
            token_get_all($rewritten, TOKEN_PARSE);
         }
         catch (ParseError) {
            $valid = false;
         }
         yield assert(
            assertion: $valid
               && str_contains($rewritten, 'function main (int $a)')
               && str_contains($rewritten, '$double = function (int $x)')
               && str_contains($rewritten, '$triple = fn (int $x)')
               && str_contains($rewritten, '$wide = function (): int')
               && str_contains($rewritten, "strlen((string) \$double(\$triple(\$a))) + \$wide() + count(\n")
               && str_contains($rewritten, "intval('1') + new Holder()->measure()")
               && str_contains($rewritten, 'set (string $value)')
               && str_contains($rewritten, 'public function measure (): int { return strlen($this->name)')
               && str_contains($rewritten, 'public function keep /* note */ (): int'),
            description: 'Every gap but the commented one must be rewritten into valid PHP, got: ' . $rewritten
         );

         // @ Analyzed again, only the commented gap remains; a second pass changes nothing
         $file = "{$dir}/Rewritten.php";
         file_put_contents($file, $rewritten);
         $Again = $Spacing->analyze($file);
         $left = [];
         foreach ($Again->issues as $Issue) {
            $left[] = $Issue->symbol;
         }
         yield assert(
            assertion: $left === ['keep'] && $Spacing->format($Again) === $rewritten,
            description: 'Only the commented gap may remain and a second format must be a no-op, got: ' . json_encode($left)
         );

         // @ An offset that no longer points at a whitespace run ending in `(` is left alone
         $source = "<?php\nfoo (1);\n";
         $Stale = new Result(file: $file, source: $source, issues: [
            new Issue(type: 'call_spacing', symbol: 'foo', kind: 'call', line: 2, message: '', offset: 1),
            new Issue(type: 'declaration_spacing', symbol: 'x', kind: 'declaration', line: 2, message: '', offset: 99),
         ]);
         yield assert(
            assertion: $Spacing->format($Stale) === $source,
            description: 'A stale or out-of-range offset must leave the source untouched'
         );
      }
      finally {
         @unlink("{$dir}/Rewritten.php");
         @rmdir($dir);
      }
   }
);
