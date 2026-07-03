<?php
namespace Bootgly\CLI;


use function sleep;
use function usleep;

use const Bootgly\CLI;


$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal (>>) - Viewport Panning @;
 * @#yellow: @@: Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);

$Viewport = $Output->Viewport;

// @ Fill the viewport with a numbered content block
$colors = ['red', 'yellow', 'green', 'cyan', 'blue', 'magenta'];
for ($line = 1; $line <= 12; $line++) {
   $color = $colors[($line - 1) % 6];
   $Output->render("@#{$color}:Content line #{$line}@; — this block will be panned by the viewport\n");
   usleep(80000);
}

sleep(1);

// @ Pan down: the content scrolls up and new lines fill in from the bottom
$Output->write("\nPanning down (content scrolls up)...\n");
sleep(1);
for ($step = 0; $step < 6; $step++) {
   $Viewport->panDown(1);
   usleep(180000);
}

sleep(1);

// @ Pan up: the content scrolls down and new lines fill in from the top
$Output->write("Panning up (content scrolls down)...\n");
sleep(1);
for ($step = 0; $step < 6; $step++) {
   $Viewport->panUp(1);
   usleep(180000);
}

sleep(1);
$Output->render("\n@#green:Viewport panning finished!@;\n");
