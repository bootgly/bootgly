<?php
namespace Bootgly\CLI;


use Bootgly\CLI;
use Bootgly\CLI\Terminal\components\Menu\ {
   Menu,
};

$Input = CLI::$Terminal->Input;
$Output = CLI::$Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal - Menu component @;
 * @#yellow: @@ Demo - Example #3 - Options: unique selection mode @;
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
$Options->Aligment::Left->set();

// * Items set - Option #1 */
$Items->Options->add(label: 'Option 1');
$Items->Options->add(label: 'Option 2');
$Items->Options->add(label: 'Option 3');

$selected = $Menu->open();


echo "\n";
if ( ! empty($selected) ) {
   $Output->write("Selected options (index): " . implode(", ", $selected) . PHP_EOL);
} else {
   echo "No options selected." . PHP_EOL;
}
