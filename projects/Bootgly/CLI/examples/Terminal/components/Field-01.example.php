<?php

namespace Bootgly\CLI;


use Bootgly\CLI;
use Bootgly\CLI\Terminal\components\Field\Field;


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


$Field = new Field(CLI::$Terminal->Output);

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
