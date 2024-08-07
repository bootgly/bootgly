<?php
namespace Bootgly\CLI;


use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Menu;
use Bootgly\CLI\UI\Components\Menu\Items\Option;
use Bootgly\CLI\UI\Components\Menu\Items\extensions\Divisors\Divisors;
use Bootgly\CLI\UI\Components\Menu\Items\extensions\Divisors\Divisor;


$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*: 
 * @#green: Bootgly CLI UI - Menu component @;
 * @#yellow: @@: Demo - Example #2 - Options: divisors in Builder @;
 * {$location}
 */\n\n
OUTPUT);


$Menu = new Menu($Input, $Output);
// * Config
$Menu->prompt = "Choose one or more options:\n";

// > Items
$Items = $Menu->Items;

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
$Items->extend(
   new Divisors($Menu)
);
$Items->push(
   (new Divisor(characters: '')),
   new Option(label: 'Option 1'),
   new Divisor(characters: '#'),
   new Option(label: 'Option 2'),
   new Divisor(characters: '.'),
   new Option(label: 'Option 3'),
   new Divisor(characters: '='),
);

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
