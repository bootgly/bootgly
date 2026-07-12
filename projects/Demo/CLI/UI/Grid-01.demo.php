<?php
namespace Bootgly\CLI;


use const BOOTGLY_TTY;
use function date;
use function function_exists;
use function pcntl_signal_dispatch;
use function sin;
use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Charts\Graph;
use Bootgly\CLI\UI\Components\Charts\Meter;
use Bootgly\CLI\UI\Components\Frame;
use Bootgly\CLI\UI\Components\Frame\Borders;
use Bootgly\CLI\UI\Components\Grid;


$Output = CLI->Terminal->Output;
$Screen = CLI->Terminal->Screen;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Grid component @;
 * @#yellow: @@: Demo - Example #1 - btop-like dashboard (weighted tracks) @;
 * {$location}
 */\n\n
OUTPUT);

// @ A btop-like dashboard — weighted tracks placing frames, each frame with
//   its own isolated/individual Output
$Grid = new Grid($Output);
$Grid->rows = [2, 1];
$Grid->columns = [2, 1];

$CPU = new Frame($Output);
$CPU->title = 'CPU';

$MEM = new Frame($Output);
$MEM->title = 'MEM';
$MEM->Borders = Borders::Round;

$Log = new Frame($Output);
$Log->title = 'Log';

$Clock = new Frame($Output);
$Clock->title = 'Clock';
$Clock->Borders = Borders::Heavy;

$Grid
   ->place($CPU, row: 1, column: 1)
   ->place($MEM, row: 1, column: 2)
   ->place($Log, row: 2, column: 1)
   ->place($Clock, row: 2, column: 2);

// @ Regular components bind to a frame's isolated Output and just work
$Graph = new Graph($CPU->Output);
$Graph->width = $CPU->columns;
$Graph->height = $CPU->lines;
$Graph->ceiling = 100.0;

$Meter = new Meter($MEM->Output);
$Meter->width = $MEM->columns;

if (BOOTGLY_TTY === true) {
   $Screen->open();
   $Output->Cursor->hide();

   // @ Terminal resizes reflow the whole dashboard
   $Screen->watch(function (int $columns, int $lines)
      use ($Grid, $CPU, $MEM, $Graph, $Meter): void {
      $Grid->resize($columns, $lines);

      $Graph->width = $CPU->columns;
      $Graph->height = $CPU->lines;
      $Meter->width = $MEM->columns;
   });
}

// @@ ~10s at 10 FPS — only changed rows repaint (diff blit)
for ($tick = 0; $tick < 100; $tick++) {
   $load = 50.0 + 35.0 * sin($tick / 4) + 12.0 * sin($tick / 1.7);

   $CPU->clear();
   $Graph->feed($load);
   $Graph->render();

   $MEM->clear();
   $Meter->value = 40.0 + $load / 3;
   $Meter->render();

   $time = date('H:i:s');
   $Clock->clear();
   $Clock->Output->render("\n  @#Cyan:{$time}@;\n");

   $rounded = (int) $load;
   $Log->Output->render("@#Black:#{$tick}@; load @#yellow:{$rounded}%@;\n");

   $Grid->render();

   // ? Non-interactive output writes one frame only
   if (BOOTGLY_TTY === false) {
      break;
   }

   if (function_exists('pcntl_signal_dispatch') === true) {
      pcntl_signal_dispatch();
   }

   usleep(100000);
}

if (BOOTGLY_TTY === true) {
   $Screen->watch(null);
   $Output->Cursor->show();
   $Screen->close();
}

$Output->render("@.;@#Green:✔@; Grid closed.@.;");
