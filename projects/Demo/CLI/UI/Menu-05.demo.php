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
 * @#yellow: @@: Demo - Example #5 - Options: center aligment @;
 * {$location}
 */\n\n
OUTPUT);


$Menu = new Menu($Input, $Output);
// * Config
$Menu->prompt = "Choose one option:\n";

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
$Options->Selection::Unique->set();
$Options->selectable = true;
$Options->deselectable = true;
// @ Styling
#$Options->divisors = '-';
// @ Displaying
$Options->Orientation::Vertical->set();
$Options->Aligment::Center->set();

// * Items set - Option #1 */
$Items->Options->add(label: 'Option 1');
$Items->Options->add(label: 'Option 2');
$Items->Options->add(label: 'Option 3');

foreach ($Menu->rendering() as $Output) {
   // ...
}
$selected = $Menu->$selected;


echo "\n";
if ( ! empty($selected) ) {
   $Output->write("Selected options (index): " . implode(", ", $selected) . PHP_EOL);
} else {
   echo "No options selected." . PHP_EOL;
}

$wait = 1;
