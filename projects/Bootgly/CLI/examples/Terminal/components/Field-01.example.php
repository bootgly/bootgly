<?php

namespace Bootgly\CLI;


use Bootgly\CLI;
use Bootgly\CLI\Terminal\components\Field\Field;
use Bootgly\CLI\Terminal\components\Menu\Menu;

$Input = CLI::$Terminal->Input;
$Output = CLI::$Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*: 
 * @#green: Bootgly CLI Terminal - Field component @;
 * @#yellow: @@: Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);


$Field = new Field($Output);

// @ Content length > Title length
// * Config
$Field->title = 'Example title';
// * Data
$Field->content = 'Some content here...';
$Field->render();

// @ Title length > Content length
// * Config
$Field->title = 'Example title';
// * Data
$Field->content = '...';
$Field->render();

// @ No title
// * Config
$Field->title = null;
// * Data
$Field->content = 'Some content here...';
$Field->render();



$Field2 = new Field($Output);
$Field2->title = 'Using another component inside!!';
// ---
$Menu = new Menu($Input, $Output);
// * Config
$Menu->render = Menu::RETURN_OUTPUT;
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
// ---
foreach ($Menu->rendering() as $output) {
   if ($output === false) {
      $selected = $output;
      break;
   }

   if (is_string($output) === true) {
      $Field2->content = $output;
      $Field2->render();
   }
}
