<?php

namespace Bootgly\CLI;


use Bootgly\CLI;
use Bootgly\CLI\Terminal\components\Fieldset\Fieldset;
use Bootgly\CLI\Terminal\components\Menu\Menu;

$Input = CLI::$Terminal->Input;
$Output = CLI::$Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*: 
 * @#green: Bootgly CLI Terminal - Fieldset component @;
 * @#yellow: @@: Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);


$Fieldset = new Fieldset($Output);

// @ Content length > Title length
// * Config
$Fieldset->title = 'Example title';
// * Data
$Fieldset->content = 'Some content here...';
$Fieldset->render();

// @ Title length > Content length
// * Config
$Fieldset->title = 'Example title';
// * Data
$Fieldset->content = '...';
$Fieldset->render();

// @ No title
// * Config
$Fieldset->title = null;
// * Data
$Fieldset->content = 'Some content here...';
$Fieldset->render();



$Fieldset2 = new Fieldset($Output);
$Fieldset2->title = 'Using another component inside!!';
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
      break;
   }

   if (is_string($output) === true) {
      $Fieldset2->content = $output;
      $Fieldset2->render();
   }
}
