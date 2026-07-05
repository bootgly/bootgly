<?php
namespace Bootgly\CLI;

use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Spinner;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Spinner component @;
 * @#yellow: @@: Demo - Example #1 - indeterminate activity @;
 * {$location}
 */\n\n
OUTPUT);

// @ Tick-driven spinner — the working loop drives spin()
$Spinner = new Spinner($Output);
$Spinner->start('Resolving dependencies...');

for ($work = 0; $work < 25; $work++) {
   usleep(80_000); // simulated work

   if ($work === 10) {
      $Spinner->describe('Downloading packages...');
   }
   if ($work === 20) {
      $Spinner->describe('Linking binaries...');
   }

   $Spinner->spin();
}

$Spinner->finish('@#Green:✔@; Dependencies ready.');
