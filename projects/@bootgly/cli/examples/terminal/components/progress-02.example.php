<?php
namespace Bootgly\CLI;

use Bootgly\CLI;
use Bootgly\CLI\Terminal\components\Progress;

$Output = CLI::$Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal - Progress component @;
 * @#yellow: @@ Demo - Example #2: Indeterminate state @;
 * {$location}
 */\n\n
OUTPUT);

$Progress = new Progress($Output);
// * Config
// @
$Progress->throttle = 0.0;

// * Data
// @
$Progress->total = 0;
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
while ($i++ < 1500) {
   if ($i === 1) {
      $Progress->describe('@#red: Performing progress! @;');
   }

   $Progress->advance();

   usleep(5000);
}


$Progress->finish();
