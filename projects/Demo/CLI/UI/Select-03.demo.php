<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Select;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Select component @;
 * @#yellow: @@: Demo 15 - Example #3 - viewport + type-ahead filter @;
 * {$location}
 */\n\n
OUTPUT);

// @ Long list windowed to 5 visible options — type letters to filter, Backspace pops, Esc clears
$Select = new Select($Input, $Output);
$Select->title = "@#Cyan:Pick a country@;\n@#Black:(↑/↓ to move, type to filter, Enter to confirm)@;";
$Select->viewport = 5;

$Select->options = [
   'Argentina', 'Australia', 'Brazil', 'Canada', 'Chile', 'France', 'Germany',
   'India', 'Italy', 'Japan', 'Mexico', 'Netherlands', 'Norway', 'Portugal',
   'Spain', 'Sweden', 'Switzerland', 'United Kingdom', 'United States', 'Uruguay'
];

// @@ Render until Enter
foreach ($Select->selecting() as $ignored);

$country = $Select->options[$Select->selected[0] ?? 0];

$Output->render("@.;Country: @#green:{$country}@;@..;");
