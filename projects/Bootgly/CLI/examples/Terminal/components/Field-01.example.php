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
// * Config
$Field->title = 'Example title';
// * Data
$Field->content = 'Some content here...';
$Field->render();

// * Config
$Field->title = 'Example title';
// * Data
$Field->content = '...';
$Field->render();
