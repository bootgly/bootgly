<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Chart;
use Bootgly\CLI\UI\Components\Chart\Plots;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Chart component @;
 * @#yellow: @@: Demo - Example #1 - ANSI sparkline + bars @;
 * {$location}
 */\n\n
OUTPUT);

// @ Sparkline — one line, min → max normalized
$Chart = new Chart($Output);
$Chart->series = [
   'q1' => 12.0, 'q2' => 25.0, 'q3' => 18.0, 'q4' => 40.0, 'q5' => 33.0,
   'q6' => 52.0, 'q7' => 47.0, 'q8' => 61.0, 'q9' => 55.0, 'q10' => 70.0,
   'q11' => 64.0, 'q12' => 82.0, 'q13' => 76.0, 'q14' => 90.0, 'q15' => 100.0
];

$Output->render("@#Cyan:Requests per quarter@; (sparkline):@.;");
$Chart->render();

// @ Bars — one labeled row per entry, scaled to the widest value
$Bench = new Chart($Output);
$Bench->Plots = Plots::Bars;
$Bench->width = 30;
$Bench->precision = 0;
$Bench->color = '@#Green:';
$Bench->series = [
   'bootgly'   => 166700.0,
   'swoole'    => 150200.0,
   'workerman' => 83350.0,
   'laravel'   => 12400.0
];

$Output->render("@.;@#Cyan:HTTP /db — requests/s@; (bars):@.;");
$Bench->render();
