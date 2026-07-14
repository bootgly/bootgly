<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Chart\Gradient;
use Bootgly\CLI\UI\Components\Charts\Bars;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Chart components @;
 * @#yellow: @@: Demo 42 - Example #2 - gradient bars @;
 * {$location}
 */\n\n
OUTPUT);

// @ Bars — one labeled row per entry, scaled to the widest value;
// each bar colored by its share of the top
$Bench = new Bars($Output);
$Bench->width = 30;
$Bench->precision = 0;
$Bench->Gradient = new Gradient(['#ff1744', '#ffd600', '#00c853']);
$Bench->series = [
   'bootgly'   => 166700.0,
   'swoole'    => 150200.0,
   'workerman' => 83350.0,
   'laravel'   => 12400.0
];

$Output->render("@#Cyan:HTTP /db — requests/s@; (bars):@.;");
$Bench->render();
