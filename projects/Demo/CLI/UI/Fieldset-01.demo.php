<?php

namespace Bootgly\CLI;


use function is_string;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Base\Fieldset;
use Bootgly\CLI\UI\Components\Select;


$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*: 
 * @#green: Bootgly CLI UI - Fieldset component @;
 * @#yellow: @@: Demo 22 - Example #1 @;
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
$Select = new Select($Input, $Output);
// * Config
$Select->render = Select::RETURN_OUTPUT;
$Select->multiple = true;
$Select->title = 'Choose one or more options:';
// * Data
$Select->options = ['Option 1', 'Option 2', 'Option 3'];
// ---
// @@ Streaming: the pinned RETURN mode yields each frame for the host to place
foreach ($Select->selecting() as $frame) {
   if (is_string($frame) === true) {
      $Fieldset2->content = $frame;
      $Fieldset2->render();
   }
}
