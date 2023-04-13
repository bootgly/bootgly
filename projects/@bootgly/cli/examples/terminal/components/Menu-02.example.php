<?php
namespace Bootgly\CLI;


use Bootgly\CLI;
use Bootgly\CLI\Terminal\components\Menu\ {
   Menu,
};
use Bootgly\CLI\Terminal\components\Menu\Items\extensions\ {
   Divisors
};


$Input = CLI::$Terminal->Input;
$Output = CLI::$Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal - Menu component @;
 * @#yellow: @@ Demo - Example #2 - Options: divisors in builder @;
 * {$location}
 */\n\n
OUTPUT);


$Menu = new Menu($Input, $Output);
// * Config
$Menu->prompt = "Choose one or more options:\n";

// > Items
$Items = $Menu->Items;
// @ extend
$Items->Divisors = new Divisors($Menu);

// > Items > Options
$Options = $Items->Options;
// * Config
// @ Selecting
$Options->Selection::Multiple->set();
$Options->selectable = true;
$Options->deselectable = true;
// @ Styling
#$Options->divisors = '-';
// @ Displaying
$Options->Orientation::Vertical->set();
#$Options->Aligment::Left->set();

// * Items set - Option #1 */
$Items->Options->add(label: 'Option 1');
$Items->Divisors->add('#');
$Items->Options->add(label: 'Option 2');
$Items->Divisors->add('.');
$Items->Options->add(label: 'Option 3');
$Items->Divisors->add('=');

$selected = $Menu->open();


echo "\n";
if ( ! empty($selected) ) {
   $Output->write("Selected options (index): " . implode(", ", $selected) . PHP_EOL);
} else {
   echo "No options selected." . PHP_EOL;
}
