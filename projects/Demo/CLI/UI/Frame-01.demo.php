<?php
namespace Bootgly\CLI;


use const BOOTGLY_TTY;
use function date;
use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Frame;
use Bootgly\CLI\UI\Components\Frame\Borders;


$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Frame component @;
 * @#yellow: @@: Demo 45 - Example #1 - isolated Output boxes (tail + clear) @;
 * {$location}
 */\n\n
OUTPUT);

// @ Two frames side by side — each one owns an isolated/individual Output;
//   the left one accumulates rows (tail view), the right one is cleared and
//   rewritten every tick (clock). Only changed rows repaint (diff blit).
$Ticks = new Frame($Output);
$Ticks->row = 6;
$Ticks->column = 2;
$Ticks->width = 34;
$Ticks->height = 9;
$Ticks->title = 'Ticks';

$Clock = new Frame($Output);
$Clock->row = 6;
$Clock->column = 38;
$Clock->width = 24;
$Clock->height = 9;
$Clock->title = 'Clock';
$Clock->Borders = Borders::Round;

$Output->Cursor->hide();

// @@ ~5s at 10 FPS
for ($tick = 1; $tick <= 50; $tick++) {
   $Ticks->Output->render("@#Black:#{$tick}@; fed row {$tick}\n");

   $time = date('H:i:s');
   $Clock->clear();
   $Clock->Output->render("\n   @#Cyan:{$time}@;\n");

   $Ticks->render();
   $Clock->render();

   // ? Non-interactive output writes one frame only
   if (BOOTGLY_TTY === false) {
      break;
   }

   usleep(100000);
}

$Output->Cursor->show();
$Output->Cursor->moveTo(line: 16, column: 1);
$Output->render("@.;@#Green:✔@; Frame closed.@.;");
