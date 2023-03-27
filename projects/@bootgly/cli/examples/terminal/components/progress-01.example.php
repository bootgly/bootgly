<?php
namespace Bootgly\CLI;

use Bootgly\CLI;
use Bootgly\CLI\Terminal\components\Progress;
use Bootgly\CLI\Terminal\components\Progress\Bar;

$Output = CLI::$Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal - Progress component @;
 * @#yellow: @@ Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);

$Progress = new Progress($Output);
// * Config
// @
$Progress->throttle = 0.0;

// * Data
// @
$Progress->total = 250000;
// ! Templating
$Progress->template = <<<'TEMPLATE'
@description;
@current;/@total; [@bar;] @percent;%
⏱️ @elapsed;s - 🏁 @eta;s - 📈 @rate; loops/s
TEMPLATE;

// ! Bar
// * Config
$Progress->Bar->units = 10;
// * Data
$Progress->Bar->Symbols->incomplete = '🖤';
$Progress->Bar->Symbols->current = '';
$Progress->Bar->Symbols->complete = '❤️';

$Progress->start();

$i = 0;
while ($i++ < 250000) {
   if ($i === 1) {
      $Progress->describe('@#red: Performing progress! @;');
   }
   if ($i === 125000) {
      $Progress->describe('@#yellow: There\'s only half left... @;');
   }
   if ($i === 249999) {
      $Progress->describe('@#green: Finished!!! @;');
   }

   $Progress->advance();

   #usleep(100);
}


$Progress->finish();
