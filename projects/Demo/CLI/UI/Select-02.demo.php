<?php
namespace Bootgly\CLI;


use function implode;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Select;


$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Select component @;
 * @#yellow: @@: Demo 14 - Example #2 - multiple selection + locked options @;
 * {$location}
 */\n\n
OUTPUT);

// @ Multiple selection — Space toggles ◼/◻; locked rows are display-only
$Select = new Select($Input, $Output);
$Select->multiple = true;
$Select->title = "@#Cyan:Enable optional features@;\n@#Black:(↑/↓ to move, Space to toggle, Enter to confirm)@;";

$Select->options = ['Core (always on)', 'Cache', 'Logs', 'Metrics', 'Tracing'];
$Select->locked = [0];

// @@ Render until Enter
foreach ($Select->selecting() as $ignored);

$features = [];
foreach ($Select->selected as $index) {
   $features[] = $Select->options[$index];
}
$summary = $features === [] ? 'none' : implode(', ', $features);

$Output->render("@.;Features: @#green:{$summary}@;@..;");
