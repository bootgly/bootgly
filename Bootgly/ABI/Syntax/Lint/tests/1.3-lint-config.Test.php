<?php

use Bootgly\ABI\Syntax\Analyzers\Rules;
use Bootgly\ABI\Syntax\Lint;
use Bootgly\ABI\Syntax\Lint\Exemption;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Lint: the defaults are today\'s four rules; every malformed entry throws naming it; exclude() and select() match relative paths by glob and rule',
   test: function () {
      // @ Defaults — only the version and the paths are required
      $Lint = new Lint(version: 1, paths: ['Bootgly/']);
      yield assert(
         assertion: $Lint->version === 1 && Lint::VERSIONS === [1]
            && $Lint->rules === [Rules::Imports, Rules::Nullables, Rules::Promotions, Rules::Methods]
            && $Lint->excludes === [] && $Lint->Exemptions === [] && $Lint->paths === ['Bootgly/'] && $Lint->file === null,
         description: 'A Lint built with paths only must enable the four rules of 1.1 and nothing else'
      );

      // @@ Malformed entries — each throws, naming what is wrong
      $Exemption = new Exemption(rule: Rules::Methods, path: 'a.php', reason: 'r');
      $malformed = [
         'later version'      => [fn () => new Lint(version: 2, paths: ['.']), 'Lint version 2 is not supported: this Bootgly reads version 1.'],
         'no version'         => [fn () => new Lint(version: 0, paths: ['.']), 'Lint version 0 is not supported'],
         'no paths'           => [fn () => new Lint(version: 1, paths: []), 'at least one'],
         'keyed paths'        => [fn () => new Lint(version: 1, paths: ['a' => 'Bootgly/']), 'paths must be a list'],
         'non-string path'    => [fn () => new Lint(version: 1, paths: [1]), 'paths[0] must be a string'],
         'absolute path'      => [fn () => new Lint(version: 1, paths: ['/etc/']), 'is absolute'],
         'climbing path'      => [fn () => new Lint(version: 1, paths: ['../x/']), 'climbs out'],
         'pattern as path'    => [fn () => new Lint(version: 1, paths: ['Bootgly/*/']), 'plain path'],
         'dot segment path'   => [fn () => new Lint(version: 1, paths: ['src/.']), "paths[0] 'src/.' carries an empty or . segment"],
         'bad exclude'        => [fn () => new Lint(version: 1, paths: ['.'], excludes: ['/abs/']), 'is absolute'],
         'non-string exclude' => [fn () => new Lint(version: 1, paths: ['.'], excludes: [null]), 'excludes[0] must be a string'],
         'no rules'           => [fn () => new Lint(version: 1, paths: ['.'], rules: []), 'at least one rule'],
         'rule as string'     => [fn () => new Lint(version: 1, paths: ['.'], rules: ['imports']), 'rules[0] must be a Rules case'],
         'repeated rule'      => [fn () => new Lint(version: 1, paths: ['.'], rules: [Rules::Imports, Rules::Imports]), 'repeats Imports'],
         'not an exemption'   => [fn () => new Lint(version: 1, paths: ['.'], Exemptions: [['rule' => 'methods']]), 'must be an Exemption'],
         'disabled rule'      => [fn () => new Lint(version: 1, paths: ['.'], rules: [Rules::Imports], Exemptions: [$Exemption]), 'not an enabled rule'],
         'bad exemption path' => [fn () => new Lint(version: 1, paths: ['.'], Exemptions: [new Exemption(rule: Rules::Methods, path: '../a.php', reason: 'r')]), 'climbs out'],
      ];
      $wrong = [];
      foreach ($malformed as $label => [$build, $needle]) {
         try {
            $build();
            $wrong[$label] = 'accepted';
         }
         catch (InvalidArgumentException $Exception) {
            if (str_contains($Exception->getMessage(), $needle) === false) {
               $wrong[$label] = $Exception->getMessage();
            }
         }
      }
      yield assert(
         assertion: $wrong === [],
         description: 'Every malformed entry must throw a message naming it, wrong: ' . json_encode($wrong)
      );

      // @ exclude() and select()
      $Lint = new Lint(
         version: 1,
         paths: ['.'],
         excludes: ['vendor/', '**/tests/fixtures/**'],
         rules: [Rules::Methods, Rules::Casing],
         Exemptions: [
            new Exemption(rule: Rules::Methods, path: 'Bootgly/ACI/**', reason: 'r1', symbol: 'Assertion::assertTrue'),
            new Exemption(rule: Rules::Casing, path: 'Bootgly/ACI/**', reason: 'r2'),
            new Exemption(rule: Rules::Methods, path: 'Bootgly/ACI/Tests/Assertion.php', reason: 'r3'),
         ]
      );
      yield assert(
         assertion: $Lint->exclude('vendor/x.php') && $Lint->exclude('Bootgly/A/tests/fixtures/b.php')
            && $Lint->exclude('Bootgly/A/tests/b.php') === false,
         description: 'exclude() must match the exclude globs only'
      );
      $selected = $Lint->select(Rules::Methods, 'Bootgly/ACI/Tests/Assertion.php');
      yield assert(
         assertion: array_keys($selected) === [0, 2] && $Lint->select(Rules::Casing, 'Bootgly/ACI/x.php') === [1 => $Lint->Exemptions[1]]
            && $Lint->select(Rules::Methods, 'Bootgly/ABI/x.php') === [] && $Lint->select(Rules::Spacing, 'Bootgly/ACI/x.php') === [],
         description: 'select() must return the exemptions of that rule whose glob matches, keyed by index, got: ' . json_encode(array_keys($selected))
      );
   }
);
