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
 * @#yellow: @@: Demo 31 - Example #8 - vertical grid (columns) @;
 * {$location}
 */\n\n
OUTPUT);

// @ 3-column grid — ←/→ move one cell, ↑/↓ move one visual line
$Menu = new Menu($Input, $Output);
$Menu->prompt = "@#Cyan:Pick a month@;\n@#Black:(←/→ and ↑/↓ to move, Enter to confirm)@;\n";
Menu::$width = 60;

$Options = $Menu->Items->Options;
$Options->Selection::Unique->set();
$Options->columns = 3;

$months = [
   'January', 'February', 'March', 'April', 'May', 'June',
   'July', 'August', 'September', 'October', 'November', 'December'
];
foreach ($months as $month) {
   $Options->add(label: $month);
}

// @@ Render until Enter
foreach ($Menu->rendering() as $ignored);

$index = (int) ($Menu->selected[0] ?? 0);
$month = $months[$index] ?? $months[0];

$Output->render("@.;Month: @#green:{$month}@;@..;");
