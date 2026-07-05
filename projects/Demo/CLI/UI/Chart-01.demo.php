<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Chart\Gradient;
use Bootgly\CLI\UI\Components\Charts\Sparkline;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Chart components @;
 * @#yellow: @@: Demo - Example #1 - gradient sparkline @;
 * {$location}
 */\n\n
OUTPUT);

// @ Sparkline — one line, min → max normalized; glyphs colored by level
$Sparkline = new Sparkline($Output);
$Sparkline->Gradient = new Gradient(['#00c853', '#ffd600', '#ff1744']);
$Sparkline->series = [
   'q1' => 12.0, 'q2' => 25.0, 'q3' => 18.0, 'q4' => 40.0, 'q5' => 33.0,
   'q6' => 52.0, 'q7' => 47.0, 'q8' => 61.0, 'q9' => 55.0, 'q10' => 70.0,
   'q11' => 64.0, 'q12' => 82.0, 'q13' => 76.0, 'q14' => 90.0, 'q15' => 100.0
];

$Output->render("@#Cyan:Requests per quarter@; (sparkline):@.;");
$Sparkline->render();
