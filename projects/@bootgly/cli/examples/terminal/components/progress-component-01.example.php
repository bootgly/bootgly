<?php
namespace Bootgly\CLI;

use Bootgly\CLI;
use Bootgly\CLI\Terminal\components\Progress;

$Output = CLI::$Terminal->Output;
$Output->reset();

/* 
 * Terminal components - Progress component - Example #1
 */
$Progress = new Progress($Output);
// * Config
// @ Ticks
$Progress->ticks = 250000;
$Progress->throttle = 0;
// @ Templating
$Progress->template = <<<'TEMPLATE'
@description;
@ticked;/@ticks; [@bar;] @percent;%
⏱️ @elapsed;s - 🏁 @eta;s - 📈 @rate; loops/s
TEMPLATE;
// ! Bar
// Symbols
$Progress->Bar->symbols = [
   'determined'   => [
      // Symbols array map:
      // 0 => incomplete / 1 => current / 2 => complete
      '🖤', '', '❤️'
   ],
   'indetermined' => ['-']
];
// Units
$Progress->Bar->units = 10;


$Progress->start();

$i = 0;
while ($i++ < 250000) {
   if ($i === 1) {
      $Progress->describe('Performing progress!');
   }
   if ($i === 125000) {
      $Progress->describe('There\'s only half left...');
   }
   if ($i === 249999) {
      $Progress->describe('Finished!!!');
   }

	$Progress->tick();
}


$Progress->finish();