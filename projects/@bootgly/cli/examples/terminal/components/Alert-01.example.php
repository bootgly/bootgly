<?php
namespace Bootgly\CLI;

use Bootgly\CLI;
use Bootgly\CLI\Terminal\components\Alert\ {
   Alert
};

$Output = CLI::$Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal - Alert component @;
 * @#yellow: @@ Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);

$Alert = new Alert($Output);

$Alert->Type::SUCCESS->set();
$Alert->emit('Success message.');

$Alert->Type::ATTENTION->set();
$Alert->emit('Attention message.');

$Alert->Type::FAILURE->set();
$Alert->emit('Failure message.');

$Alert->Type::DEFAULT->set();
$Alert->emit('Default message.');
