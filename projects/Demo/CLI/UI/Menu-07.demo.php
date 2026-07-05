<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Menu;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Menu component @;
 * @#yellow: @@: Demo - Example #7 - viewport + type-ahead filter @;
 * {$location}
 */\n\n
OUTPUT);

// @ Long list windowed to 5 visible options — type letters to filter, Backspace pops, Esc clears
$Menu = new Menu($Input, $Output);
$Menu->prompt = "@#Cyan:Pick a country@;\n@#Black:(↑/↓ to move, type to filter, Enter to confirm)@;\n";

$Options = $Menu->Items->Options;
$Options->Selection::Unique->set();
$Options->viewport = 5;

$countries = [
   'Argentina', 'Australia', 'Brazil', 'Canada', 'Chile', 'France', 'Germany',
   'India', 'Italy', 'Japan', 'Mexico', 'Netherlands', 'Norway', 'Portugal',
   'Spain', 'Sweden', 'Switzerland', 'United Kingdom', 'United States', 'Uruguay'
];
foreach ($countries as $country) {
   $Options->add(label: $country);
}

// @@ Render until Enter
foreach ($Menu->rendering() as $ignored);

$index = (int) ($Menu->selected[0] ?? 0);
$country = $countries[$index] ?? $countries[0];

$Output->render("@.;Country: @#green:{$country}@;@..;");
