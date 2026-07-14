<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Progress;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*: 
 * @#green: Bootgly CLI UI - Progress component @;
 * @#yellow: @@: Demo 19 - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);

$Progress = new Progress($Output);
// * Config
// @ ~60 fps redraw cap: the loop throughput stays unthrottled
$Progress->throttle = 0.016;

// * Data
// @
$Progress->total = 1000000;
// ! Templating
$Progress->template = <<<'TEMPLATE'
@description;
@current;/@total; [@bar;] @percent;%
⏱️  @elapsed;s - 🏁  @eta;s - 📈  @rate; loops/s
TEMPLATE;

// ! Bar
// * Config
$Progress->Bar->units = 10;
// * Data
$Progress->Bar->Symbols->incomplete = '-';
$Progress->Bar->Symbols->current = '';
$Progress->Bar->Symbols->complete = '#';

$Progress->start();

$i = 0;
while ($i++ < 1000000) {
   if ($i === 1) {
      $Progress->describe('@#red: Performing progress! @;');
   }
   if ($i === 500000) {
      $Progress->describe('@#yellow: There\'s only half left... @;');
   }
   if ($i === 999999) {
      $Progress->describe('@#green: Finished!!! @;');
   }

   $Progress->advance();

   #usleep(100);
}


$Progress->finish();
