<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Driver;
use Bootgly\ACI\Tests\Coverage\Drivers\Native;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Coverage — measured paths add unloaded sources at zero; changes restrict the report to changed lines',

   test: new Assertions(Case: function (): Generator {
      // ! A source tree: one loaded file, one never loaded, one test script
      $root = Temporaries::reserve('coverage-paths');
      mkdir("{$root}/Source");
      mkdir("{$root}/Source/tests");
      mkdir("{$root}/Source/examples");
      mkdir("{$root}/Source/commands");
      mkdir("{$root}/Source/vendor");
      file_put_contents("{$root}/Source/commands/Run.php", "<?php\n\$r = 1;\n");
      mkdir("{$root}/Source/vendor/examples");
      file_put_contents("{$root}/Source/vendor/Package.php", "<?php\n\$p = max(\n   1, 2\n);\n// note\n");
      file_put_contents("{$root}/Source/vendor/examples/Demo.php", "<?php\n\$d = 1;\n");
      // An import (2), an alternative-syntax body (3–7) the analyzer has no
      // line for, an enum (8) and a `default:` label (10)
      file_put_contents("{$root}/Source/vendor/Zeta.php", "<?php\nuse Demo\\Thing;\nif (\$z ?? 0):\n   \$z = max(\n      1, 2\n   );\nendif;\nenum Zed { case A; }\nswitch (\$z ?? 0) {\n   default:\n}\n");
      file_put_contents("{$root}/Source/Upper.PHP", "<?php\n\$u = 1;\n");
      file_put_contents("{$root}/Source/examples/Sample.php", "<?php\nfinal class Sample\n{\n   public function run () { return 1; }\n}\n");
      file_put_contents("{$root}/Source/Loaded.php", "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n");
      file_put_contents("{$root}/Source/Unloaded.php", "<?php\n\$x = 1;\n\$y = 2;\n");
      // Statements over several lines: only their first line is executable
      // (2, 6, 10, 14 and 18) — arguments, a heredoc, an interpolation, a
      // `for` header, a closure argument; then control bodies (23–41), match
      // arms (42–49), a call closed after a closure (52), inline HTML
      // after a close tag that ends a statement (53–56), a braceless `else`
      // body (61–63), a `case` holding a ternary (65–68), match arms with a
      // nullable type, an array, a closure and an attribute (70–82), a
      // `case` with a nullable type (83–88), braceless `while`/`for`/
      // `elseif` and an alternative `switch` (89–107), bodies sharing a
      // row with `catch`/`declare` (108–116), a one-line `if` (117–119) and a
      // body sharing its row with a `case` label (120–125), a braceless
      // `else if` body (126–130), a `case` holding an attribute and a nullable
      // type (131–136), a `default:` body sharing rows with a label and a
      // sibling (137–140), a `match (true)` arm condition (141–147), an arm
      // body sharing rows with its condition and a `default` arm (148–152),
      // a row closing two statements (153–157), a `case …;` label (158–162),
      // a `case` after a block inside its switch (163–168), a `case`
      // holding a ternary across rows (169–175), an arm condition over two
      // rows (176–182), a last arm without a trailing comma (183–186), closure
      // bodies (187–191), an arrow function in an arm (192–196), a `case`
      // sharing rows in an alternative-syntax switch (197–201) and a
      // `foreach` over a `match` (202–207), a `match` on strings (208–217)
      // and a `case` on a constant (218–221): PHP compares those in a jump
      // table at the subject, so PCOV gives their rows no line —, a block
      // after `::class` (222–225), a constant `case` in an
      // alternative-syntax switch (226–229), a `case` on a variable
      // (230–233), a block after a `class:` argument (234–237), one after
      // an empty anonymous class (238–241), closures beside them (242–249),
      // an anonymous class after a closure argument (250–252), a `case` on a
      // closure call (253–258), closures as property defaults (259–283), a
      // `case` on a `match` (284–289), an arm condition resuming after a
      // block (290–296), a method named `class` (297–302), a braceless
      // `do` body (303–305), a `static` initializer (306–311) and an arm
      // body whose call resumes after a closure argument (312–317), and
      // members named after keywords: `A::do(` before an arm condition
      // (318–323), in a short hook (324–329) and `function &class` (330–335),
      // a `case` on a static property (336–339), and arguments named after
      // keywords: `do:` in an arm condition (340–344), `else:` in a hook
      // body (345–353) and `endswitch:` before a `case` (354–359)
      file_put_contents("{$root}/Source/Multi.php", <<<'PHP'
      <?php
      $m = max(
         1,
         2
      );
      $s = <<<TXT
        body
        TXT;
      // note
      $v = max(
         "{$s}",
         1
      );
      for ($i = 0;
         $i < 1;
         $i++) {
      }
      $f = array_map(static function ($x) {
         return $x;
      }, [
         1,
      ]);
      switch ($m) {
         case 1:
            $r = max(
               5, 6
            );
            break;
         default: $r = max(
            7, 8
         );
      }
      if ($m)
         echo max(
            9, 10
         );
      foreach ([1] as $i):
         echo max(
            13, 14
         );
      endforeach;
      $v = match ($m) {
         1 => max(
            1, 2
         ),
         default => min(
            3, 4
         ),
      };
      $w = array_map(function ($x) {
         return $x;
      });
      $q = max(1, 2) ?>
      <p>
        x
      </p>
      <?php
      $z = 1;
      if ($m) {
      } else
         echo max(
            9, 10
         );
      switch ($m) {
         case $m ? 1 : 2:
            echo max(
               5, 6
            );
      }
      $n = match ($m) {
         1 => static fn (?int $v): ?int => $v,
         2 => [
            1,
         ],
         3 => function () {
            return 1;
         },
         4 => #[A,
            B] static fn () => max(
            1, 2
         ),
      };
      switch ($m) {
         case array_map(fn (?int $v) => $v, [1]):
            echo max(
               1, 2
            );
      }
      while ($m)
         echo max(
            1, 2
         );
      for (; $m;)
         echo max(
            1, 2
         );
      if ($m) {
      } elseif ($m)
         echo max(
            1, 2
         );
      switch ($m):
         case 1:
            echo max(
               1, 2
            );
      endswitch;
      try {
      } catch (Exception $e) { $r = max(
         1,
         2); $t = 1;
      }
      declare(ticks=1) { $r = max(
         1,
         2); $t = 1;
      }
      if ($m) echo max(
         1, 2
      );
      switch ($m) {
         case 2: $r = max(
            1,
            2
         );
      }
      if ($m) {
      } else if ($m)
         echo max(
            1, 2
         );
      switch ($m) {
         case (fn (#[A] ?int $v) => $v)(1):
            echo max(
               1, 2
            );
      }
      switch ($m) {
         default: $r = max(
            1,
            2); } $k = 1;
      $o = match (true) {
         $m > 1 => sprintf(
            '%s',
            [1][0]
         ),
         default => 0,
      };
      $q = match (true) {
         $m > 2 => max(
            1,
            2), default => 0,
      };
      $g = array_map(function ($x) { return max(
         $x, 1
      ); }, [
         1,
      ]);
      switch ($m) {
         case 1; $r = max(
            1,
            2); $s = 1;
      }
      switch ($m) {
         case 1: if ($m) { $m = 1; }
         case 2; $r = max(
            1,
            2); $s = 1;
      }
      switch ($m) {
         case $m ? 1
            : 2
            : $r = max(
               1,
               2); $s = 1;
      }
      $r = match ($m) {
         1,
         2 => max(
            1,
            2),
         default => 0,
      };
      $r = match ($m) {
         1 => 1,
         default => max(1, 2)
      }; // closing
      $f = array_map(function ($v) {
         return $v;
      }, [1]);
      $g = array_map(function ($v) {
         $v++; return $v; }, [1]);
      $h = match ($m) {
         1 => fn () => max(
            1, 2),
         default => 0,
      };
      switch ($m):
         case 1: $s = max(
            1,
            2); $t = 1;
      endswitch;
      foreach (match ($m) {
         1 => [1],
         default => [],
      } as $k => $v) {
         $o = $v;
      }
      $q = match ($m) {
         'array' => [
            'format' => 1,
         ],
         'help' =>
            max(1, 2),
         default => [
            'x' => 1,
         ],
      };
      switch ($m) {
         case SIGALRM:
            $r = 1;
      }
      if ($m === Demo::class) {
         $w = max(
            1, 2);
      }
      switch ($m):
         case SIGHUP:
            $r = 1;
      endswitch;
      switch ($m) {
         case $y:
            $r = 1;
      }
      if (k(class: 1)) {
         $w = max(
            1, 2);
      }
      foreach ([new class {}] as $x) {
         $w = max(
            1, 2);
      }
      k(class: 1, then: function () {
         $w = max(
            1, 2);
      });
      $f = [new class {}, function () {
         $w = max(
            1, 2);
      }];
      $o = new class (function () { $a = 1; }) {
         static $count = 0;
      };
      switch ($m) {
         case k(function () {
            1;
         }):
            $r = 1;
      }
      class Z {
         public static $d = static function () {
            $w = max(
               1, 2);
         };
         public array $c = [static function () {
            return 1;
         }] {
            get {
               return $this->c;
            }
         }
      }
      abstract class Y {
         public static $e = static function (#[A(static function () { return 1; })] $p) {
            $w = max(
               1, 2);
         };
         abstract function f ();
         public int $p {
            get {
               return 1;
            }
         }
      }
      switch ($m) {
         case match ($y) {
            default => 1,
         };
            $r = 1;
      }
      $r = match ($m) {
         match ($y) {
            default => 1,
         }
         , 2
         => 'a',
      };
      class W {
         Function class ($v) {
            $r = max(
               $v, 3);
         }
      }
      do $r = max(
         1, 2);
      while (false);
      function q () {
         static $q = match (true) {
            default => max(
               1, 2),
         };
      }
      $r = match ($m) {
         1 => k(function () {
            1;
         }, max(
            2, 3)),
      };
      $r = match ($m) {
         1 => A::do(
            5),
         $y
            + 1 => 'b',
      };
      class Hk {
         public int $p {
            get => A::do(
               7, 8);
         }
      }
      class Rf {
         Function &class ($v) {
            $w = max(
               $v, 3);
         }
      }
      switch ($m) {
         case A::$x:
            $r = 1;
      }
      $r = match ($m) {
         0 => 'a',
         k(do:
            2) => 'b',
      };
      class Nh {
         public int $p {
            get {
               k(else:
                  1);
               return 1;
            }
         }
      }
      switch ($m):
         case 1:
            k(endswitch: 1);
         case 2:
            $r = 2;
      endswitch;

      PHP);
      file_put_contents("{$root}/Source/notes.txt", "not php\n");
      file_put_contents("{$root}/Source/tests/1.Test.php", "<?php\n\$t = 1;\n");
      $source = str_replace('\\', '/', (string) realpath("{$root}/Source"));
      $loaded = "{$source}/Loaded.php";
      $unloaded = "{$source}/Unloaded.php";
      $command = "{$source}/commands/Run.php";
      $package = "{$source}/vendor/Package.php";
      $zeta = "{$source}/vendor/Zeta.php";
      $upper = "{$source}/Upper.PHP";
      $multi = "{$source}/Multi.php";

      $Driver = new class ($loaded) extends Driver {
         // * Data
         private string $file;
         /** @var array<int,int> */
         private array $lines;


         /**
          * @param array<int,int> $lines
          */
         public function __construct (string $file, array $lines = [2 => 1, 3 => 0, 4 => 1])
         {
            // * Data
            $this->file = $file;
            $this->lines = $lines;
         }

         public function collect (): array
         {
            return [$this->file => $this->lines];
         }
      };

      try {
         // ? The temporary root itself sits in a resource directory (a TMPDIR
         //   such as /home/ci/tests/tmp): sources outside the working
         //   directory are read by their absolute path, so every one here
         //   would count as a test script
         foreach (explode('/', $source) as $segment) {
            if (isset(Coverage::RESOURCES[$segment])) {
               yield (new Assertion(description: "the temporary root sits in a '{$segment}/' directory: {$source}"))->skip();
               return;
            }
         }

         // @ Without paths: only what ran
         $Coverage = new Coverage($Driver);
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'without paths the report holds only loaded files'))
            ->expect(array_keys($Coverage->data))
            ->to->be([$loaded])
            ->assert();

         // @ With a measured path
         $Coverage = new Coverage($Driver);
         $Coverage->paths = [$source];
         $Coverage->start();
         $Coverage->stop();

         yield (new Assertion(description: 'an unloaded source joins the report (commands/ is code); tests/ and examples/ resource folders do not'))
            ->expect(array_keys($Coverage->data))
            ->to->be([$loaded, $multi, $unloaded, $upper, $command, $package, $zeta])
            ->assert();

         yield (new Assertion(description: 'the unloaded source is counted at zero hits'))
            ->expect($Coverage->data[$unloaded])
            ->to->be([2 => 0, 3 => 0])
            ->assert();

         yield (new Assertion(description: 'a loaded source keeps its measured hits'))
            ->expect($Coverage->data[$loaded])
            ->to->be([2 => 1, 3 => 0, 4 => 1])
            ->assert();

         // @ The include scope still applies to unloaded sources
         $Coverage = new Coverage($Driver);
         $Coverage->paths = [$source];
         $Coverage->includes = ['/Loaded.php'];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'unloaded sources outside the include scope stay out'))
            ->expect(array_keys($Coverage->data))
            ->to->be([$loaded])
            ->assert();

         // @ A path that is not a directory
         $Coverage = new Coverage($Driver);
         $Coverage->paths = ["{$root}/missing"];
         $Coverage->start();
         $thrown = false;
         try {
            $Coverage->stop();
         }
         catch (LogicException) {
            $thrown = true;
         }
         yield (new Assertion(description: 'a missing measured path throws a LogicException'))
            ->expect($thrown)
            ->to->be(true)
            ->assert();

         // @ Changed lines
         $Coverage = new Coverage($Driver);
         $Coverage->paths = [$source];
         $Coverage->changes = [
            $loaded => [3 => true, 4 => true, 9 => true],
            "{$root}/Source/../Source/Unloaded.php" => [1 => true],
         ];
         $Coverage->start();
         $Coverage->stop();

         yield (new Assertion(description: 'changes keep only the changed executable lines'))
            ->expect($Coverage->data)
            ->to->be([$loaded => [3 => 0, 4 => 1]])
            ->assert();

         // @ A changed source no test loaded — without a measured path
         $Coverage = new Coverage($Driver);
         $Coverage->changes = [$unloaded => [2 => true, 3 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a changed source no test loaded joins at zero even without paths'))
            ->expect($Coverage->data)
            ->to->be([$unloaded => [2 => 0, 3 => 0]])
            ->assert();

         $Coverage = new Coverage($Driver);
         $Coverage->includes = ['/Loaded.php'];
         $Coverage->changes = [$unloaded => [2 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a changed source outside the include scope stays out'))
            ->expect([$Coverage->data, $Coverage->unmeasured])
            ->to->be([[], []])
            ->assert();

         $Coverage = new Coverage($Driver);
         $Coverage->changes = ["{$root}/Source/examples/Sample.php" => [2 => true, 3 => true, 4 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a changed resource source (examples/) stays out'))
            ->expect($Coverage->data)
            ->to->be([])
            ->assert();

         $Coverage = new Coverage($Driver);
         $Coverage->changes = ["{$root}/Source/tests/1.Test.php" => [2 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a changed test script stays out of the report'))
            ->expect($Coverage->data)
            ->to->be([])
            ->assert();

         $Coverage = new Coverage($Driver);
         $Coverage->changes = [];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'an empty change set measures nothing'))
            ->expect($Coverage->data)
            ->to->be([])
            ->assert();

         $Coverage = new Coverage($Driver);
         $Coverage->paths = [$source];
         $Coverage->changes = [$unloaded => [2 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a changed source no test loaded is measured at zero'))
            ->expect($Coverage->data)
            ->to->be([$unloaded => [2 => 0]])
            ->assert();

         $Coverage = new Coverage($Driver);
         $Coverage->changes = [$command => [2 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a changed source under commands/ is code, not a resource'))
            ->expect($Coverage->data)
            ->to->be([$command => [2 => 0]])
            ->assert();

         $Coverage = new Coverage($Driver);
         $Coverage->changes = [$upper => [2 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a changed .PHP source (any case) no test loaded joins at zero'))
            ->expect($Coverage->data)
            ->to->be([$upper => [2 => 0]])
            ->assert();

         // @ A changed line inside a statement over several lines counts as
         //   the statement's executable line; a comment line counts as none
         $results = [];
         foreach ([[4 => true], [7 => true], [9 => true], [3 => true, 8 => true], [12 => true], [15 => true], [20 => true], [21 => true], [17 => true]] as $rows) {
            $Coverage = new Coverage($Driver);
            $Coverage->changes = [$multi => $rows];
            $Coverage->start();
            $Coverage->stop();
            $results[] = $Coverage->data;
         }
         yield (new Assertion(description: 'a changed source no test loaded: argument, heredoc, interpolation, for-header and closure-argument rows map to their statement; a comment or a closing brace maps to none'))
            ->expect($results)
            ->to->be([
               [$multi => [2 => 0]], [$multi => [6 => 0]], [], [$multi => [2 => 0, 6 => 0]],
               [$multi => [10 => 0]], [$multi => [14 => 0]], [$multi => [18 => 0]], [$multi => [18 => 0]], [],
            ])
            ->assert();

         $Coverage = new Coverage(new (get_class($Driver))($multi, [2 => 1, 6 => 0]));
         $Coverage->changes = [$multi => [5 => true, 9 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a loaded source keeps the statement whose closing row changed'))
            ->expect($Coverage->data)
            ->to->be([$multi => [2 => 1]])
            ->assert();

         // @ A driver that marks control headers and the first row of each
         //   body (as PCOV does): a body row maps to its body statement, never
         //   to the header — which runs whether or not the body does
         $map = [
            2 => 1, 23 => 1, 24 => 1, 25 => 0, 28 => 0, 29 => 0, 33 => 1, 34 => 0, 37 => 1, 38 => 0,
            41 => 1, 42 => 1, 43 => 1, 46 => 0, 50 => 1, 51 => 1, 53 => 1, 54 => 0, 58 => 1,
            59 => 1, 61 => 0, 64 => 1, 65 => 1, 66 => 0,
            70 => 1, 71 => 1, 72 => 0, 75 => 0, 76 => 0, 78 => 0, 79 => 0, 84 => 1, 85 => 0,
            89 => 1, 90 => 0, 93 => 1, 94 => 0, 97 => 1, 98 => 1, 99 => 0, 103 => 1, 104 => 0,
            109 => 1, 111 => 0, 113 => 1, 115 => 0, 117 => 1, 121 => 1, 123 => 0, 126 => 1, 127 => 1, 128 => 0,
            132 => 1, 133 => 0, 138 => 0, 140 => 1, 142 => 1, 144 => 0, 146 => 1,
            149 => 1, 151 => 0, 153 => 1, 154 => 0, 156 => 1, 159 => 1, 161 => 0, 165 => 1, 167 => 0, 170 => 1, 172 => 1, 174 => 0,
            176 => 1, 178 => 0, 181 => 1, 183 => 1, 185 => 0, 187 => 1, 190 => 1, 192 => 1, 193 => 0, 195 => 1,
            197 => 1, 198 => 1, 200 => 0, 202 => 1, 203 => 1, 204 => 1, 206 => 0,
            208 => 1, 210 => 1, 213 => 0, 215 => 0, 218 => 1, 220 => 1, 222 => 1, 223 => 1, 226 => 1, 228 => 1,
         ];
         $results = [];
         foreach ([26, 30, 35, 39, 47, 52, 55, 62, 67, 74, 80, 86, 91, 95, 100, 105, 110, 114, 118, 122, 129, 134, 139, 143, 141, 102, 150, 155, 160, 166, 171, 177, 179, 186, 194, 199, 205, 209, 212, 214, 219, 224, 227] as $row) {
            $Coverage = new Coverage(new (get_class($Driver))($multi, $map));
            $Coverage->changes = [$multi => [$row => true]];
            $Coverage->start();
            $Coverage->stop();
            $results[$row] = $Coverage->data[$multi] ?? [];
            $unmeasured[$row] = $Coverage->unmeasured;
         }
         yield (new Assertion(description: 'body rows map to their body statement (case, default, braceless, alternative syntax, else, match arm, `});`, inline HTML), preferring a row of its own, then one shared with a sibling, then one shared with a header; a one-line `if` keeps its shared row; a match subject maps to its first arm; a `case` or arm condition without a line maps to its subject'))
            ->expect($results)
            ->to->be([
               26 => [25 => 0], 30 => [29 => 0], 35 => [34 => 0], 39 => [38 => 0], 47 => [46 => 0],
               52 => [50 => 1], 55 => [54 => 0], 62 => [61 => 0], 67 => [66 => 0],
               74 => [72 => 0], 80 => [79 => 0], 86 => [85 => 0], 91 => [90 => 0], 95 => [94 => 0],
               100 => [99 => 0], 105 => [104 => 0], 110 => [111 => 0], 114 => [115 => 0], 118 => [117 => 1],
               122 => [123 => 0], 129 => [128 => 0], 134 => [133 => 0], 139 => [138 => 0], 143 => [144 => 0],
               141 => [142 => 1], 102 => [103 => 1], 150 => [151 => 0], 155 => [154 => 0, 156 => 1],
               160 => [161 => 0], 166 => [167 => 0], 171 => [170 => 1],
               177 => [178 => 0], 179 => [178 => 0], 186 => [183 => 1], 194 => [193 => 0],
               199 => [200 => 0], 205 => [202 => 1],
               209 => [208 => 1, 210 => 1], 212 => [208 => 1], 214 => [215 => 0], 219 => [218 => 1],
               224 => [223 => 1], 227 => [226 => 1],
            ])
            ->assert();

         yield (new Assertion(description: 'a changed row that an executable line stands for is never unmeasured'))
            ->expect(array_filter($unmeasured))
            ->to->be([])
            ->assert();

         // @ Changed code no executable line stands for, under any driver: a
         //   closure body the driver recorded nothing for, alone (188) or
         //   beside a statement it did record (191), a body after `::class`
         //   (224), a `class:` argument (236) or an empty anonymous class
         //   (240) — none opens a class-like body, nor does it for a closure
         //   beside them (244, 248) —, a property of an anonymous class
         //   after a closure argument (251: a declaration), a `case` label
         //   that resumes after a closure (256), the body of a closure as a
         //   property default (262) — but not the hooks after one (267:
         //   a declaration) —, one after a closure in its attribute (275),
         //   the hooks after a bodyless method (279: a declaration), a `case`
         //   label that resumes after a block (287), an arm condition that
         //   does (294), the body of a method named `class` (300) and a
         //   braceless `do` body (304) or of a `static` initializer (309) —
         //   but not an argument after a closure in an arm body (316) —, an
         //   arm condition after an `A::do(` call (321), a short hook calling
         //   it (327), the body of `function &class` (333), a `case` on a
         //   static property (337: not constant), an argument named `do`
         //   (342) or `else` (348) — while `endswitch` (357) leaves the
         //   next `case` a label of its switch —, and a
         //   `case` on a
         //   variable without a line (231: compared on its own, never at
         //   its subject)
         $results = [];
         foreach ([188 => [187 => 1], 191 => [190 => 1], 224 => [222 => 1], 231 => [230 => 1, 232 => 1], 236 => [234 => 1], 240 => [238 => 1], 244 => [242 => 1], 248 => [246 => 1], 251 => [250 => 1],
            256 => [253 => 1, 255 => 1, 257 => 1], 262 => [259 => 1], 267 => [259 => 1],
            275 => [272 => 1], 279 => [272 => 1], 287 => [284 => 1, 288 => 1],
            294 => [290 => 1], 300 => [298 => 1], 304 => [305 => 1],
            309 => [306 => 1], 316 => [312 => 1, 313 => 1, 314 => 1],
            321 => [318 => 1, 319 => 1], 327 => [324 => 1], 333 => [330 => 1],
            337 => [336 => 1, 338 => 1], 342 => [340 => 1, 341 => 1], 348 => [345 => 1],
            357 => [354 => 1, 356 => 1, 358 => 1]] as $row => $lines) {
            $Coverage = new Coverage(new (get_class($Driver))($multi, $lines));
            $Coverage->changes = [$multi => [$row => true]];
            $Coverage->start();
            $Coverage->stop();
            $results[$row] = [$Coverage->data, $Coverage->unmeasured];
         }
         yield (new Assertion(description: 'changed code that no line stands for is unmeasured under any driver, even beside a measured statement; a class-like body declares only after its own keyword'))
            ->expect($results)
            ->to->be([
               188 => [[], [$multi]], 191 => [[$multi => [190 => 1]], [$multi]], 224 => [[], [$multi]],
               231 => [[], [$multi]], 236 => [[], [$multi]], 240 => [[], [$multi]],
               244 => [[], [$multi]], 248 => [[], [$multi]], 251 => [[], []],
               256 => [[], [$multi]], 262 => [[], [$multi]], 267 => [[], []],
               275 => [[], [$multi]], 279 => [[], []],
               287 => [[], [$multi]], 294 => [[], [$multi]], 300 => [[], [$multi]], 304 => [[], [$multi]],
               309 => [[], [$multi]], 316 => [[$multi => [313 => 1]], []],
               321 => [[], [$multi]], 327 => [[], [$multi]], 333 => [[], [$multi]],
               337 => [[], [$multi]], 342 => [[], [$multi]], 348 => [[], [$multi]],
               357 => [[$multi => [354 => 1]], []],
            ])
            ->assert();

         // @ Declarations hold no code: a source changed only in them is
         //   neither measured nor unmeasured
         $declared = "{$source}/Declared.php";
         file_put_contents($declared, "<?php\ndeclare(strict_types=1);\nclass Declared {\n   use Shared {\n      fake as private faker;\n   }\n   static \$count = 0;\n   static public function make (\n      int \$a = 5\n   ) {\n      return \$a;\n   }\n   public function &match (\n      int \$a\n   ) {\n      return \$a;\n   }\n   public function run (\$n) {\n      global \$g;\n      {\n         \$n--;\n      }\n      do {\n         \$n--;\n      } while (\$n > 0);\n      return f(match: 1, then: function () {\n         \$v = fn () =>\n            2;\n         return \$v;\n      });\n   }\n   public int \$a {\n      get {\n         return 1;\n      }\n      set (int \$value) {\n         \$this->a = \$value;\n      }\n   }\n   public int \$b { get => max(\n      1, 2); }\n}\ninterface Boxed { public int \$row { get; set; } }\nuse Demo\\Thing; \$boot = max(\n   1, 2);\n");
         try {
            $Coverage = new Coverage(new (get_class($Driver))($declared, [11 => 0, 16 => 0, 21 => 0, 24 => 0, 25 => 0, 26 => 0, 28 => 0, 29 => 0, 34 => 0, 37 => 0, 45 => 0]));
            $Coverage->changes = [$declared => array_fill_keys([2, 4, 5, 7, 8, 9, 13, 14, 18, 19, 20, 23, 27, 32, 33, 36, 43, 44], true)];
            $Coverage->start();
            $Coverage->stop();

            // ! A short hook holds code no line stands for
            $Short = new Coverage(new (get_class($Driver))($declared, [45 => 0]));
            $Short->changes = [$declared => [41 => true]];
            $Short->start();
            $Short->stop();
         }
         finally {
            unlink($declared);
         }
         yield (new Assertion(description: 'a `declare`, a class-like body (trait adaptation, `static` property and method, `&match` signature), `global`, a bare block, a `do`, a closure passed as a `match:` argument, property hook headers, an interface hook and an import beside code hold no code'))
            ->expect([$Coverage->data, $Coverage->unmeasured])
            ->to->be([[$declared => [28 => 0, 45 => 0]], []])
            ->assert();

         yield (new Assertion(description: 'a changed short hook (`get => …`) no line stands for is unmeasured'))
            ->expect([$Short->data, $Short->unmeasured])
            ->to->be([[], [$declared]])
            ->assert();

         $Coverage = new Coverage(new (get_class($Driver))($multi, [5 => 1]));
         $Coverage->changes = [$multi => [3 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a driver that marks only the closing row of a statement still sees a change to its argument'))
            ->expect($Coverage->data)
            ->to->be([$multi => [5 => 1]])
            ->assert();

         // @ Changed sources the Native driver never instruments (/vendor/)
         //   — never started here, so an outer coverage run stays untouched
         $Coverage = new Coverage(new Native());
         $Coverage->includes = [$source];
         $Coverage->changes = [$zeta => [3 => true], $package => [2 => true]];
         $Coverage->stop();
         yield (new Assertion(description: 'under Native, changed sources out of its reach are unmeasured (sorted), not dropped'))
            ->expect([$Coverage->unmeasured, $Coverage->data])
            ->to->be([[$package, $zeta], []])
            ->assert();

         $Coverage = new Coverage(new Native());
         $Coverage->includes = [$source];
         $Coverage->changes = [$package => [5 => true]];
         $Coverage->stop();
         yield (new Assertion(description: 'under Native, a comment-only change out of its reach is not unmeasured'))
            ->expect([$Coverage->unmeasured, $Coverage->data])
            ->to->be([[], []])
            ->assert();

         $results = [];
         foreach ([[3 => true], [3 => true, 5 => true]] as $rows) {
            $Coverage = new Coverage(new Native());
            $Coverage->includes = [$source];
            $Coverage->changes = [$package => $rows];
            $Coverage->stop();
            $results[] = $Coverage->unmeasured;
         }
         yield (new Assertion(description: 'under Native, a change inside a statement out of its reach is unmeasured, with or without a comment change beside it'))
            ->expect($results)
            ->to->be([[$package], [$package]])
            ->assert();

         $results = [];
         foreach ([[5 => true], [2 => true], [8 => true], [10 => true]] as $rows) {
            $Coverage = new Coverage(new Native());
            $Coverage->includes = [$source];
            $Coverage->changes = [$zeta => $rows];
            $Coverage->stop();
            $results[] = $Coverage->unmeasured;
         }
         yield (new Assertion(description: 'under Native, a change in an alternative-syntax body out of its reach is unmeasured; an import, an enum case or a `default:` label is not code'))
            ->expect($results)
            ->to->be([[$zeta], [], [], []])
            ->assert();

         // @ A source a test loaded that Native never instruments
         //   — and one under a resource folder, which no report holds
         $demo = "{$source}/vendor/examples/Demo.php";
         foreach ([$package, $demo] as $file) {
            (static function (string $file): void {
               require $file;
            })($file);
         }
         $Coverage = new Coverage(new Native());
         $Coverage->includes = [$source];
         $Coverage->stop();
         yield (new Assertion(description: 'under Native, a loaded source out of its reach is unmeasured, not dropped; a loaded resource is neither'))
            ->expect([$Coverage->unmeasured, $Coverage->data])
            ->to->be([[$package], []])
            ->assert();

         // @ The coverage engine is always loaded and never instruments
         //   itself: it is not a source the tests failed to reach
         $engine = array_values(array_filter(
            get_included_files(),
            static fn (string $file): bool => str_contains(str_replace('\\', '/', $file), Native::ENGINE)
         ));
         $Coverage = new Coverage(new Native());
         $Coverage->includes = ['/Bootgly/ACI/Tests/'];
         $Coverage->stop();
         yield (new Assertion(description: 'under Native, the loaded coverage engine is never reported as unmeasured'))
            ->expect([$engine !== [], array_values(array_filter(
               $Coverage->unmeasured,
               static fn (string $file): bool => str_contains($file, Native::ENGINE)
            ))])
            ->to->be([true, []])
            ->assert();

         // ? Only the engine's own directory: a project source at a
         //   same-named path (an extension kept under `projects/…`) is not it
         mkdir("{$root}/Kit/Bootgly/ACI/Tests/Coverage", 0o755, true);
         file_put_contents("{$root}/Kit/Bootgly/ACI/Tests/Coverage/Extension.php", "<?php\n\$e = 1;\n");
         $extension = str_replace('\\', '/', (string) realpath("{$root}/Kit/Bootgly/ACI/Tests/Coverage/Extension.php"));
         (static function (string $file): void {
            require $file;
         })($extension);
         $Coverage = new Coverage(new Native());
         $Coverage->includes = ["{$root}/Kit/"];
         $Coverage->stop();
         yield (new Assertion(description: 'under Native, a loaded project source at a path named like the engine stays unmeasured'))
            ->expect($Coverage->unmeasured)
            ->to->be([$extension])
            ->assert();

         // @ Native with the temporary tree in its reach (only /tmp/ dropped
         //   from its exclusions, restored below): an unloaded source joins
         //   at zero, and only a source still out of reach is unmeasured
         //   — every stop() runs before the list is restored, and only then
         //   are the results asserted
         $excludes = Native::$excludes;
         $reach = null;
         Native::$excludes = array_values(array_diff($excludes, ['/tmp/']));
         try {
            if (Native::allow($unloaded)) {
               $Changed = new Coverage(new Native());
               $Changed->includes = [$source];
               $Changed->changes = [$unloaded => [2 => true]];
               $Changed->stop();

               // ! A loaded source in its reach that the (idle) driver
               //   recorded nothing for is not unmeasured
               (static function (string $file): void {
                  require $file;
               })($loaded);
               $Loaded = new Coverage(new Native());
               $Loaded->includes = [$source];
               $Loaded->stop();

               $Measured = new Coverage(new Native());
               $Measured->includes = [$source];
               $Measured->paths = [$source];
               $Measured->stop();

               $Both = new Coverage(new Native());
               $Both->includes = [$source];
               $Both->paths = [$source];
               $Both->changes = [$unloaded => [2 => true]];
               $Both->stop();

               // ! A changed body in its reach that it has no probe for
               $Probe = new Coverage(new Native());
               $Probe->includes = [$source];
               $Probe->changes = [$multi => [39 => true]];
               $Probe->stop();

               // ! A changed constant `case` label: Native has no line for it
               //   and its subject's line runs whether or not it is compared
               $Label = new Coverage(new Native());
               $Label->includes = [$source];
               $Label->changes = [$multi => [219 => true]];
               $Label->stop();

               $reach = [$Changed, $Loaded, $Measured, $Both, $Probe, $Label];
            }
         }
         finally {
            Native::$excludes = $excludes;
         }

         if ($reach === null) {
            yield (new Assertion(description: "Native reach probes: the temporary tree stays excluded without /tmp/ ({$source})"))->skip();
         }
         else {
            [$Changed, $Loaded, $Measured, $Both, $Probe, $Label] = $reach;
            yield (new Assertion(description: 'under Native, a changed source in its reach joins at zero, not as unmeasured'))
               ->expect([$Changed->unmeasured, $Changed->data])
               ->to->be([[], [$unloaded => [2 => 0]]])
               ->assert();

            yield (new Assertion(description: 'under Native, a changed body in its reach that it has no line for is unmeasured, not unchanged'))
               ->expect($Probe->unmeasured)
               ->to->be([$multi])
               ->assert();

            yield (new Assertion(description: 'under Native, a changed constant `case` label is unmeasured, never its subject\'s line'))
               ->expect([$Label->unmeasured, $Label->data[$multi][218] ?? null])
               ->to->be([[$multi], null])
               ->assert();

            yield (new Assertion(description: 'under Native, a loaded source in its reach is not unmeasured'))
               ->expect($Loaded->unmeasured)
               ->to->be([$package])
               ->assert();

            yield (new Assertion(description: 'under Native, a measured path reports the sources out of its reach as unmeasured'))
               ->expect([$Measured->unmeasured, isset($Measured->data[$unloaded]), isset($Measured->data[$package])])
               ->to->be([[$package, $zeta], true, false])
               ->assert();

            yield (new Assertion(description: 'under Native, a diff keeps only the changed sources out of its reach'))
               ->expect([$Both->unmeasured, $Both->data])
               ->to->be([[], [$unloaded => [2 => 0]]])
               ->assert();
         }

         // @ A checkout that itself sits in a tests/ directory (a CI
         //   workspace named so): its sources are not test scripts. The
         //   working directory is a constant, so a child process sets it.
         $workspace = "{$root}/tests/app";
         mkdir($workspace, 0o755, true);
         file_put_contents("{$workspace}/Foo.php", "<?php\n\$f = 1;\n");
         $foo = str_replace('\\', '/', (string) realpath("{$workspace}/Foo.php"));
         file_put_contents("{$root}/child.php", <<<'PHP'
         <?php
         [, $framework, $working, $file] = $argv;
         define('BOOTGLY_WORKING_DIR', "{$working}/");
         spl_autoload_register(static function (string $class) use ($framework): void {
            $path = $framework . str_replace('\\', '/', $class) . '.php';
            if (is_file($path)) {
               require $path;
            }
         });
         $Driver = new class ($file) extends Bootgly\ACI\Tests\Coverage\Driver {
            private string $file;
            public function __construct (string $file) { $this->file = $file; }
            public function collect (): array { return [$this->file => [2 => 0]]; }
         };
         $Idle = new class extends Bootgly\ACI\Tests\Coverage\Driver {
            public function collect (): array { return []; }
         };
         $result = [];
         foreach ([[$Driver, null], [$Driver, [$file => [2 => true]]], [$Idle, [$file => [2 => true]]]] as [$Used, $changes]) {
            $Coverage = new Bootgly\ACI\Tests\Coverage($Used);
            $Coverage->changes = $changes;
            $Coverage->start();
            $Coverage->stop();
            $result[] = $Coverage->data;
         }
         echo json_encode($result);
         PHP);
         $process = proc_open(
            [PHP_BINARY, "{$root}/child.php", BOOTGLY_ROOT_DIR, (string) realpath($workspace), $foo],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
         );
         $output = '';
         if (is_resource($process)) {
            /** @var array<int,resource> $pipes */
            $output = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
         }
         yield (new Assertion(description: 'a checkout under a tests/ directory keeps its sources: loaded in full and diff mode, and changed but never loaded'))
            ->expect(json_decode($output, true))
            ->to->be([[$foo => [2 => 0]], [$foo => [2 => 0]], [$foo => [2 => 0]]])
            ->assert();
      }
      finally {
         @unlink("{$root}/child.php");
         @unlink("{$root}/Kit/Bootgly/ACI/Tests/Coverage/Extension.php");
         foreach (['/Kit/Bootgly/ACI/Tests/Coverage', '/Kit/Bootgly/ACI/Tests', '/Kit/Bootgly/ACI', '/Kit/Bootgly', '/Kit'] as $directory) {
            @rmdir("{$root}{$directory}");
         }
         @unlink("{$root}/tests/app/Foo.php");
         @rmdir("{$root}/tests/app");
         @rmdir("{$root}/tests");
         @unlink("{$root}/Source/commands/Run.php");
         @unlink("{$root}/Source/vendor/Package.php");
         @unlink("{$root}/Source/vendor/Zeta.php");
         @unlink("{$root}/Source/vendor/examples/Demo.php");
         @rmdir("{$root}/Source/vendor/examples");
         @unlink("{$root}/Source/Multi.php");
         @unlink("{$root}/Source/Upper.PHP");
         @rmdir("{$root}/Source/commands");
         @rmdir("{$root}/Source/vendor");
         @unlink("{$root}/Source/tests/1.Test.php");
         @unlink("{$root}/Source/examples/Sample.php");
         @rmdir("{$root}/Source/examples");
         @rmdir("{$root}/Source/tests");
         @unlink("{$root}/Source/Loaded.php");
         @unlink("{$root}/Source/Unloaded.php");
         @unlink("{$root}/Source/notes.txt");
         @rmdir("{$root}/Source");
         @rmdir($root);
      }
   })
);
