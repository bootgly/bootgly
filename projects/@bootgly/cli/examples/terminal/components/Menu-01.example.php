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
 * @#yellow: @@ Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);


$Menu = new Menu($Input, $Output);
// * Config
$Menu->prompt = "Choose one or more options:";

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
#$Options->separator = '-';
// @ Displaying
$Options->Orientation::Vertical->set();
$Options->Aligment::Left->set();

/* Set 1 */
/*
$Menu->Builder
->Option->add(...);
->Separator->add(...);
*/

/* Set 2 */
#$Items->Options->push();
$Items->Options->add(label: 'Option 1');
$Items->Separators->add('-');
$Items->Options->add(label: 'Option 2');
$Items->Separators->add('_');
$Items->Options->add(label: 'Option 3');
$Items->Separators->add('=');
$Items->Options->advance();
#$Items->Groups->add('Test');

$selected = $Menu->open();


echo "\n";
if ( ! empty($selected) ) {
   $Output->write("Selected options (index): " . implode(", ", $selected) . PHP_EOL);
} else {
   echo "No options selected." . PHP_EOL;
}
