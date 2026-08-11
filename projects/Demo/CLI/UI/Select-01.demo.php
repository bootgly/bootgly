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
 * @#yellow: @@: Demo 13 - Example #1 - single selection @;
 * {$location}
 */\n\n
OUTPUT);

// @ Single selection — ↑/↓ aim, Enter confirms the aimed option (● radio marks)
$Select = new Select($Input, $Output);
$Select->title = "@#Cyan:Choose a database driver@;\n@#Black:(↑/↓ to move, Enter to confirm)@;";

$Select->options = ['MySQL', 'PostgreSQL', 'SQLite'];

// @@ Render until Enter
foreach ($Select->selecting() as $ignored);

$driver = $Select->options[$Select->selected[0] ?? 0];

$Output->render("@.;Driver: @#green:{$driver}@;@..;");
