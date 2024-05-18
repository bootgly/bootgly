<?php
namespace Bootgly\CLI;


use const Bootgly\CLI;
use Bootgly\CLI\UI\Menu\Menu;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*: 
 * @#green: Bootgly CLI UI - Menu component @;
 * @#yellow: @@: Demo - Example #1 - Options: global divisors @;
 * {$location}
 */\n\n
OUTPUT);


$Menu = new Menu($Input, $Output);
// * Config
$Menu::$width = 80;
$Menu->prompt = "Choose one or more options:\n";

// > Items
$Items = $Menu->Items;
// * Config
// @ Selecting
// @ Styling
// @ Displaying

// > Items > Options
$Options = $Items->Options;
// * Config
// @ Selecting
$Options->Selection::Multiple->set();
$Options->selectable = true;
$Options->deselectable = true;
// @ Styling
$Options->divisors = '-';
// @ Displaying
$Options->Orientation::Vertical->set();
$Options->Aligment::Left->set();

// * Items set - Option #1 */
$Items->Options->add(label: 'Option 1');
$Items->Options->add(label: 'Option 2');
$Items->Options->add(label: 'Option 3');
$Items->Options->advance();
// * Items set - Option #2 */
/*
$Option1 = new Option(...);
$Option1->...;
$Items->push($Option1);
$Divisor1 = new Divisor(...);
$Divisor1->...;
$Items->push($Divisor1);
*/

foreach ($Menu->rendering() as $Output) {
   // ...
}
$selected = $Menu->selected;

echo "\n";
if ( ! empty($selected) ) {
   $Output->write("Selected options (index): " . implode(", ", $selected) . PHP_EOL);
} else {
   echo "No options selected." . PHP_EOL;
}
