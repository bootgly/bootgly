<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage\Gate;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Coverage — Gate checks the statements floor at its exact boundary and never passes an empty measurement',

   test: new Assertions(Case: function (): Generator {
      // ! 4 of 5 statements covered — exactly 80%
      $data = [
         '/src/A.php' => [1 => 1, 2 => 1, 3 => 0],
         '/src/B.php' => [5 => 3, 6 => 1],
      ];

      // @ Boundary: 80 passes, 80.01 fails
      $Gate = new Gate(floor: 80.0);
      yield (new Assertion(description: 'a floor equal to the covered share passes'))
         ->expect($Gate->check($data))
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'statements and covered count lines, not hits'))
         ->expect([$Gate->statements, $Gate->covered, $Gate->failures])
         ->to->be([5, 4, []])
         ->assert();

      yield (new Assertion(description: 'percent reports the covered share'))
         ->expect($Gate->percent)
         ->to->be(80.0)
         ->assert();

      $Gate = new Gate(floor: 80.01);
      yield (new Assertion(description: 'a floor just above the covered share fails'))
         ->expect($Gate->check($data))
         ->to->be(false)
         ->assert();

      yield (new Assertion(description: 'the failure names the share, the statements and the floor'))
         ->expect(
            count($Gate->failures) === 1
            && str_contains($Gate->failures[0], '80.00% of 5 statements covered (4)')
            && str_contains($Gate->failures[0], 'floor of 80.01%')
         )
         ->to->be(true)
         ->assert();

      // @ A decimal floor equal to the share passes (64.40% = 161/250)
      $lines = [];
      for ($line = 1; $line <= 250; $line++) {
         $lines[$line] = $line <= 161 ? 1 : 0;
      }
      $Gate = new Gate(floor: 64.40);
      yield (new Assertion(description: 'a decimal floor equal to the covered share passes (no float rounding)'))
         ->expect($Gate->check(['/src/C.php' => $lines]))
         ->to->be(true)
         ->assert();

      $Gate = new Gate(floor: 64.41);
      yield (new Assertion(description: 'a decimal floor one hundredth above the share fails'))
         ->expect($Gate->check(['/src/C.php' => $lines]))
         ->to->be(false)
         ->assert();

      // @ Empty measurement
      $Gate = new Gate(floor: 0.0);
      yield (new Assertion(description: 'an empty hit map fails even a zero floor'))
         ->expect($Gate->check([]))
         ->to->be(false)
         ->assert();

      yield (new Assertion(description: 'the empty failure says nothing was measured'))
         ->expect(str_contains($Gate->failures[0] ?? '', 'no executable statement was measured'))
         ->to->be(true)
         ->assert();

      $Gate = new Gate(floor: 0.0);
      yield (new Assertion(description: 'files with no executable line count as an empty measurement'))
         ->expect($Gate->check(['/src/Empty.php' => []]))
         ->to->be(false)
         ->assert();

      yield (new Assertion(description: 'percent is 0.0 when nothing was measured'))
         ->expect($Gate->percent)
         ->to->be(0.0)
         ->assert();

      // @ No rule enabled
      $Gate = new Gate();
      yield (new Assertion(description: 'a gate with no rule passes, even on an empty map'))
         ->expect($Gate->check([]) && $Gate->floor === null && $Gate->classes === false)
         ->to->be(true)
         ->assert();

      // @ A re-check replaces the previous metrics
      $Gate = new Gate(floor: 50.0);
      $Gate->check(['/src/A.php' => [1 => 0, 2 => 0]]);
      $Gate->check(['/src/A.php' => [1 => 1]]);
      yield (new Assertion(description: 'check() resets failures and metrics between calls'))
         ->expect([$Gate->statements, $Gate->covered, $Gate->failures])
         ->to->be([1, 1, []])
         ->assert();

      // @ Constructor rejections
      foreach ([-0.01, 100.01, NAN, INF, 2.34375] as $floor) {
         $shown = var_export($floor, true);
         $thrown = false;
         try {
            new Gate(floor: $floor);
         }
         catch (InvalidArgumentException) {
            $thrown = true;
         }

         yield (new Assertion(description: "floor {$shown} is rejected"))
            ->expect($thrown)
            ->to->be(true)
            ->assert();
      }

      $Gate = new Gate(floor: 100.0);
      yield (new Assertion(description: 'a floor of 100 accepts full coverage only'))
         ->expect([$Gate->check(['/a' => [1 => 1]]), $Gate->check(['/a' => [1 => 1, 2 => 0]])])
         ->to->be([true, false])
         ->assert();
   })
);
