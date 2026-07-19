<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Chart\Gradient;
use Bootgly\CLI\UI\Components\Charts\Meter;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Chart components @;
 * @#yellow: @@: Demo 43 - Example #3 - gradient meters @;
 * {$location}
 */\n\n
OUTPUT);

// @ Meter — a percentage gauge; filled cells sample the gradient at their position
$Output->render("@#Cyan:Workers load@; (meter):@.;");

foreach (['worker-1' => 92.0, 'worker-2' => 58.0, 'worker-3' => 13.0] as $worker => $load) {
   $Meter = new Meter($Output);
   $Meter->width = 30;
   $Meter->Gradient = new Gradient(['#00c853', '#ffd600', '#ff1744']);
   $Meter->value = $load;

   $Output->write("{$worker} ");
   $Meter->render();
}

$Output->write("\n");

// @ Corner labels — heading/summary above, caption/note below (markup ok)
$Output->render("@#Cyan:Test cases@; (labeled meter):@.;");

$Meter = new Meter($Output);
$Meter->width = 40;
$Meter->Gradient = new Gradient(['#98c379']);
$Meter->value = 75.0;
$Meter->heading = '@#White:Cases@;';
$Meter->summary = '@:error:1 failed@;, @:success:3 passed@;';
$Meter->caption = '@#Black:3 / 4 cases@;';
$Meter->note = '75%';
$Meter->render();
