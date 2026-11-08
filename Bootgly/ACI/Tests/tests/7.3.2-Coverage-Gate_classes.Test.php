<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage\Gate;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Coverage — Gate flags class, trait and enum sources with no covered statement',

   test: new Assertions(Case: function (): Generator {
      // ! Fixture sources — only their declarations matter to the gate
      $directory = Temporaries::reserve('coverage-gate-classes');
      $sources = [
         'Klass.php'     => "<?php\n#[Attribute]\nfinal class Klass\n{\n   public function run () { return 1; }\n}\n",
         'Traited.php'   => "<?php\ntrait Traited\n{\n   public function run () { return 1; }\n}\n",
         'Enumed.php'    => "<?php\nenum Enumed: string\n{\n   case A = 'a';\n   public function run () { return 1; }\n}\n",
         'Anonymous.php' => "<?php\n\$Object = new class {\n   public function run () { return 1; }\n};\n",
         'Constant.php'  => "<?php\n\$name = Foo::class;\nreturn \$name;\n",
         'Script.php'    => "<?php\n\$a = 1;\n\$b = 2;\n",
      ];
      $files = [];
      foreach ($sources as $name => $source) {
         $files[$name] = "{$directory}/{$name}";
         file_put_contents($files[$name], $source);
      }

      try {
         // @ Every source at zero
         $data = [];
         foreach ($files as $file) {
            $data[$file] = [2 => 0, 3 => 0];
         }

         $Gate = new Gate(classes: true);
         $passed = $Gate->check($data);

         yield (new Assertion(description: 'a class source at zero fails the gate'))
            ->expect($passed)
            ->to->be(false)
            ->assert();

         $zeros = $Gate->zeros;
         sort($zeros);
         $expected = [$files['Enumed.php'], $files['Klass.php'], $files['Traited.php']];
         sort($expected);
         yield (new Assertion(description: 'named class, trait and enum are flagged; anonymous classes, Foo::class and scripts are not'))
            ->expect($zeros)
            ->to->be($expected)
            ->assert();

         yield (new Assertion(description: 'the failure lists each flagged source'))
            ->expect(
               str_contains($Gate->failures[0] ?? '', '3 class source(s)')
               && in_array("  {$files['Klass.php']}", $Gate->failures, true)
            )
            ->to->be(true)
            ->assert();

         // @ One covered statement clears the source
         $data[$files['Klass.php']] = [2 => 0, 3 => 1];
         $data[$files['Traited.php']] = [2 => 1, 3 => 0];
         $data[$files['Enumed.php']] = [2 => 1, 3 => 1];
         $Gate = new Gate(classes: true);
         yield (new Assertion(description: 'one covered statement per class source passes'))
            ->expect($Gate->check($data))
            ->to->be(true)
            ->assert();

         // @ A class source with no executable line is not "at zero"
         $Gate = new Gate(classes: true);
         $Gate->check([$files['Klass.php'] => [], $files['Script.php'] => [2 => 1]]);
         yield (new Assertion(description: 'a class source without executable lines is not flagged'))
            ->expect($Gate->zeros)
            ->to->be([])
            ->assert();

         // @ The rule is off by default
         $Gate = new Gate(floor: 0.0);
         $Gate->check([$files['Klass.php'] => [2 => 0]]);
         yield (new Assertion(description: 'zero classes are not collected while the rule is off'))
            ->expect([$Gate->zeros, $Gate->failures])
            ->to->be([[], []])
            ->assert();

         // @ A missing source cannot be proven to declare a class
         $Gate = new Gate(classes: true);
         yield (new Assertion(description: 'an unreadable source is not flagged'))
            ->expect($Gate->check(["{$directory}/Missing.php" => [1 => 0]]))
            ->to->be(true)
            ->assert();
      }
      finally {
         foreach ($files as $file) {
            @unlink($file);
         }
         @rmdir($directory);
      }
   })
);
