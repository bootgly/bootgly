<?php
namespace Bootgly\CLI;

use Bootgly\CLI;
use Bootgly\CLI\Terminal\components\Menu\ {
   Menu
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
$Items->Selection::Multiple->set();
$Items->selectable = true;
$Items->deselectable = true;
// @ Displaying
$Items->Orientation::Vertical->set();
$Items->Aligment::Left->set();
// @ Styling
$Items->separator = '-';

// @ Setting
/* Menu Builder
$Builder = $Menu->Builder;
$MenuBuilder
->Option->add(id: 'O-0-1', label: 'Option 1')
->Option->add(id: 'O-0-2', label: 'Option 2')
->Option->add(id: 'O-0-3', label: 'Option 3')
->Separator->add('-')
->build();
*/

/* */
$Items->Options->add(id: 'O-0-1', label: 'Option 1');
$Items->Options->add(id: 'O-0-2', label: 'Option 2');
$Items->Options->add(id: 'O-0-3', label: 'Option 3');

$selected = $Menu->open();


echo "\n";
if ( ! empty($selected) ) {
   $Output->write("Selected options (index): " . implode(", ", $selected) . PHP_EOL);
} else {
   echo "No options selected." . PHP_EOL;
}
