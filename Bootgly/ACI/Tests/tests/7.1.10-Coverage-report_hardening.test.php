<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Driver;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — report formats are allowlisted and escaped',

   test: new Assertions(Case: function (): Generator {
      $file = BOOTGLY_WORKING_DIR . 'tmp/coverage-<node>-"quotes"-&-\'apostrophe\'.php';

      $Driver = new class ($file) extends Driver {
         public function __construct (private string $file) {}

         public function collect (): array
         {
            return [$this->file => [7 => 1, 8 => 0]];
         }
      };

      $Cov = new Coverage($Driver);
      $Cov->start();
      $Cov->stop();

      $html = $Cov->report('HTML');
      $expectedHTML = htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

      yield (new Assertion(description: 'HTML report escapes file paths with explicit UTF-8 flags'))
         ->expect(str_contains($html, $expectedHTML))
         ->to->be(true)
         ->assert();

      $clover = $Cov->report('clover');
      $expectedXML = htmlspecialchars($file, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

      yield (new Assertion(description: 'Clover report escapes file paths with XML-safe flags'))
         ->expect(str_contains($clover, $expectedXML))
         ->to->be(true)
         ->assert();

      $message = null;
      try {
         $Cov->report('Text\\Injected');
      }
      catch (LogicException $Exception) {
         $message = $Exception->getMessage();
      }

      yield (new Assertion(description: 'unknown report format is rejected by allowlist'))
         ->expect(str_contains((string) $message, 'Coverage report not found'))
         ->to->be(true)
         ->assert();

      $previous = getcwd();
      if ($previous === false) {
         throw new RuntimeException('Could not capture current working directory.');
      }

      chdir(sys_get_temp_dir());
      try {
         $text = $Cov->report('text');
      }
      finally {
         chdir($previous);
      }

      yield (new Assertion(description: 'text report stays relative to BOOTGLY_WORKING_DIR'))
         ->expect(str_contains($text, 'tmp/coverage-<node>-"quotes"-&-\'apostrophe\'.php'))
         ->to->be(true)
         ->assert();
   })
);
