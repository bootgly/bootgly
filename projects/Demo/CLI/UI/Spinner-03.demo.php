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
 * @#yellow: @@: Demo 32.2 - Example #3 - named animation sets @;
 * {$location}
 */\n\n
OUTPUT);

// @ Named animation sets — one spinner per registry entry
foreach (Spinner::$Sets as $set => $frames) {
   $Spinner = new Spinner($Output);
   $Spinner->set = $set;
   $Spinner->start("Spinning with the @#Cyan:{$set}@; set…");

   for ($work = 0; $work < 18; $work++) {
      usleep(70_000); // simulated work

      $Spinner->spin();
   }

   $Spinner->finish("@#Green:✔@; {$set}");
}
