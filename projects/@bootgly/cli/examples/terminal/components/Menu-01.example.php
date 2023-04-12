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
// @ Selection
$Items->Selection::Multiple->set();
$Items->selectable = true;
$Items->deselectable = true;
// @ Display
$Items->Orientation::Vertical->set();
$Items->Aligment::Left->set();


/*
$Items->set([
   'Option 1' => [
      'prepend' => '@#red: ',
      'append' => ' @;'
   ],
   '@#blue: Option 2 @;',
   'Option 3'
]);
*/
$Items
   ->add(label: 'Option 1')
   ->add(label: 'Option 2')
   ->add(label: 'Option 3');

$selected = $Menu->open();


echo "\n";
if ( ! empty($selected) ) {
   $Output->write("Selected options (index): " . implode(", ", $selected) . PHP_EOL);
} else {
   echo "No options selected." . PHP_EOL;
}
