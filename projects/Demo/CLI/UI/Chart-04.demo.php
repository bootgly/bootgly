<?php
namespace Bootgly\CLI;

use function sin;
use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Chart\Gradient;
use Bootgly\CLI\UI\Components\Charts\Graph;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Chart components @;
 * @#yellow: @@: Demo 44 - Example #4 - live streaming Graph (braille) @;
 * {$location}
 */\n\n
OUTPUT);

$Output->render("@#Cyan:CPU load@; (braille graph — 2 values per cell, gradient by height):@.;");

// @ Live graph — a fake CPU wave fed value by value
$Graph = new Graph($Output);
$Graph->width = 60;
$Graph->height = 6;
$Graph->ceiling = 100.0;
$Graph->Gradient = new Gradient(['#00c853', '#ffd600', '#ff1744']);

$Graph->start();

// @@ ~2.5s of streaming (50 samples × 50ms) — non-interactive output renders once at finish
for ($tick = 0; $tick < 50; $tick++) {
   $load = 50.0 + 35.0 * sin($tick / 4) + 12.0 * sin($tick / 1.7);

   $Graph->feed($load);

   usleep(50000);
}

$Graph->finish();
