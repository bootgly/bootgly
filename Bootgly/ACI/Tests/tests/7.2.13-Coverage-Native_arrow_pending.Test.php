<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage\Drivers\Native;
use Bootgly\ACI\Tests\Coverage\Drivers\Native\Compiler;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Coverage/Native: an arrow function leaves no block kind pending',
   test: new Assertions(Case: function (): Generator
   {
      /**
       * Reports the ParseError of a source, or '' when it parses.
       *
       * `token_get_all()` alone is a lexer and accepts a hit marker in places
       * PHP will not, so the real parser is what decides here.
       */
      $parsing = static function (string $source): string {
         try {
            token_get_all($source, TOKEN_PARSE);

            return '';
         }
         catch (ParseError $Error) {
            return $Error->getMessage();
         }
      };

      $compile = static function (string $source): string {
         $lines = [];

         return Compiler::compile($source, '/probe.php', $lines);
      };

      // @@ The shape from `CLI/Terminal/Input/Lines.php`: a hook whose getter
      //    carries an arrow function, followed by a second hook. `fn` owns no
      //    `{`, so the pending BODY it used to arm was consumed by the NEXT
      //    hook list, which became an injectable body.
      $shape = <<<'PHP'
      <?php
      class Probe
      {
         public array $Lines = [];

         public array $lines {
            get => array_map(static fn (object $Line): string => $Line->value, $this->Lines);
         }
         public string $value {
            get => implode("\n", $this->lines);
         }
      }
      PHP;

      yield new Assertion(
         description: 'A hook list after an arrow function still compiles to parseable PHP'
      )
         ->assert(
            actual: $parsing($compile($shape)),
            expected: ''
         );

      // @@ Controls — everything the pending flag exists to do must keep working
      $body = <<<'PHP'
      <?php
      class Probe
      {
         public function run (): int
         {
            $value = 1;

            return $value;
         }
      }
      PHP;
      $compiled = $compile($body);

      yield new Assertion(
         description: 'A method body is still instrumented'
      )
         ->assert(
            actual: substr_count($compiled, 'Coverage::hit') >= 2,
            expected: true
         );

      $hook = <<<'PHP'
      <?php
      class Probe
      {
         public int $count {
            get => 1;
         }
      }
      PHP;
      $compiled = $compile($hook);

      yield new Assertion(
         description: 'A hook list is never anchored before `get`'
      )
         ->assert(
            actual: str_contains($compiled, "Coverage::hit('/probe.php',5); get"),
            expected: false
         );

      $hookBody = <<<'PHP'
      <?php
      class Probe
      {
         public int $count {
            get {
               $value = 1;

               return $value;
            }
         }
      }
      PHP;
      $compiled = $compile($hookBody);

      yield new Assertion(
         description: 'A hook with a real body is still instrumented inside it'
      )
         ->assert(
            actual: substr_count($compiled, 'Coverage::hit') >= 2,
            expected: true
         );

      $arrowMatch = <<<'PHP'
      <?php
      class Probe
      {
         public function run (): callable
         {
            return static fn (int $x): string => match ($x) { 1 => 'a', default => 'b' };
         }
         public int $count {
            get => 1;
         }
      }
      PHP;

      yield new Assertion(
         description: 'A `match` inside an arrow function still owns its own brace'
      )
         ->assert(
            actual: $parsing($compile($arrowMatch)),
            expected: ''
         );

      // ---

      // @@ The structural guard. Instrumenting the whole framework and parsing
      //    the result costs ~2 s, which is what makes it worth shipping: TCOV-1
      //    and TCOV-2 together broke 13 files under the native driver while the
      //    coverage suite stayed green, because its only validity check ran a
      //    lexer. This is the check that could not have missed them.
      $root = BOOTGLY_ROOT_DIR . 'Bootgly';
      $Files = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
      );

      $checked = 0;
      $skipped = [];
      $broken = [];

      foreach ($Files as $File) {
         /** @var SplFileInfo $File */
         if ($File->isFile() === false || $File->getExtension() !== 'php') {
            continue;
         }

         $path = $File->getPathname();
         $source = (string) file_get_contents($path);
         $relative = substr($path, strlen($root) + 1);

         // ? A file that does not parse BEFORE instrumentation proves nothing
         //   about the compiler — the tree ships deliberately malformed
         //   fixtures. Counted, never silently dropped.
         if ($parsing($source) !== '') {
            $skipped[] = $relative;
            continue;
         }

         $checked++;

         foreach ([Native::MODE_STRICT, Native::MODE_PARITY] as $mode) {
            $lines = [];
            $reason = $parsing(Compiler::compile($source, $path, $lines, $mode));

            if ($reason !== '') {
               $broken[] = "{$relative} [{$mode}]: {$reason}";
            }
         }
      }

      yield new Assertion(
         description: 'Every framework file still parses after instrumentation, got: '
            . json_encode(array_slice($broken, 0, 5))
      )
         ->assert(
            actual: $broken,
            expected: []
         );
      yield new Assertion(
         description: 'The guard actually covered the tree, checked: ' . $checked
      )
         ->assert(
            actual: $checked > 2000,
            expected: true
         );
      yield new Assertion(
         description: 'Only the known malformed fixtures were skipped, got: '
            . json_encode($skipped)
      )
         ->assert(
            actual: count($skipped) <= 1,
            expected: true
         );
   })
);
