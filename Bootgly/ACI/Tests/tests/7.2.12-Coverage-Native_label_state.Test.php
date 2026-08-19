<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage\Drivers\Native\Analyzer;
use Bootgly\ACI\Tests\Coverage\Drivers\Native\Compiler;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Coverage/Native: an enum `case` never arms the switch-label state',
   test: new Assertions(Case: function (): Generator
   {
      /**
       * Reports the ParseError message of a compiled source, or '' when it
       * parses.
       *
       * `token_get_all()` alone cannot answer this — it is a lexer, and it
       * accepts a hit marker sitting inside a type declaration without
       * complaint. TOKEN_PARSE runs the real parser.
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

      // ! The shape behind every file the entry names: a plain enum whose
      //   `case` arms the label state, then a BACKED enum whose `:` was read as
      //   the label colon
      $shape = "<?php\nenum Direction { case Vertical; }\nenum Aligment : int { case Left = 0; }\n";
      $lines = [];
      $compiled = Compiler::compile($shape, '/probe.php', $lines);

      yield new Assertion(
         description: 'An enum followed by a backed enum still compiles to parseable PHP'
      )
         ->assert(
            actual: $parsing($compiled),
            expected: ''
         );
      yield new Assertion(
         description: 'The backed-enum type declaration is left intact'
      )
         ->assert(
            actual: str_contains($compiled, 'enum Aligment : int'),
            expected: true
         );

      // @@ The same state leaking onto a return type
      $returning = "<?php\nenum E { case A; }\nfunction f (): string { return 'x'; }\n";
      $lines = [];
      $compiled = Compiler::compile($returning, '/probe.php', $lines);

      yield new Assertion(
         description: 'An enum followed by a typed function still compiles to parseable PHP'
      )
         ->assert(
            actual: $parsing($compiled),
            expected: ''
         );
      yield new Assertion(
         description: 'The return type declaration is left intact'
      )
         ->assert(
            actual: str_contains($compiled, 'function f (): string'),
            expected: true
         );

      // ---

      // @@ Control — a REAL switch label must still anchor the statement that
      //    follows it, which is the whole reason the state exists
      $switching = "<?php\nfunction f (\$x) {\n   switch (\$x) {\n      case 1:\n"
         . "         return 'a';\n      default:\n         return 'b';\n   }\n}\n";
      $lines = [];
      $compiled = Compiler::compile($switching, '/probe.php', $lines);

      yield new Assertion(
         description: 'A switch label still anchors the statement after `case`'
      )
         ->assert(
            actual: isset($lines[5]),
            expected: true
         );
      yield new Assertion(
         description: 'A switch label still anchors the statement after `default`'
      )
         ->assert(
            actual: isset($lines[7]),
            expected: true
         );
      yield new Assertion(
         description: 'The instrumented switch still parses'
      )
         ->assert(
            actual: $parsing($compiled),
            expected: ''
         );

      // @@ Control — `match` arms are expressions; their `default` never armed
      //    the state and must still not
      $matching = "<?php\nfunction f (\$x): string {\n   return match (\$x) {\n"
         . "      1 => 'a',\n      default => 'b'\n   };\n}\n";
      $lines = [];
      $compiled = Compiler::compile($matching, '/probe.php', $lines);

      yield new Assertion(
         description: 'An instrumented match still parses'
      )
         ->assert(
            actual: $parsing($compiled),
            expected: ''
         );

      // ---

      // @@ `Analyzer` carries the same state with no frame stack to consult, so
      //    it disarms on the `;` that proves the label was an enum case. Its
      //    failure is a wrong executable-line map rather than a parse error: a
      //    type declaration counted as executable can never be hit, so it
      //    deflates coverage forever.
      yield new Assertion(
         description: 'Analyzer marks no line inside the backed-enum declaration'
      )
         ->assert(
            actual: Analyzer::scan($shape),
            expected: []
         );
      yield new Assertion(
         description: 'Analyzer still marks the statement after a switch label'
      )
         ->assert(
            actual: isset(Analyzer::scan($switching)[5]) && isset(Analyzer::scan($switching)[7]),
            expected: true
         );
   })
);
