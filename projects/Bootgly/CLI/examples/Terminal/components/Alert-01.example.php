<?php
namespace Bootgly\CLI;

use Bootgly\CLI;
use Bootgly\CLI\Terminal\components\Alert\Alert;

$Output = CLI::$Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*: 
 * @#green: Bootgly CLI Terminal - Alert component @;
 * @#yellow: @@: Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);

$Alert = new Alert($Output);

$Alert->Type::SUCCESS->set();
$Alert->message = 'Success message.';
$Alert->render();

$Alert->Type::ATTENTION->set();
$Alert->message = 'Attention message.';
$Alert->render();

$Alert->Type::FAILURE->set();
$Alert->message = 'Failure message.';
$Alert->render();

$Alert->Type::DEFAULT->set();
$Alert->message = 'Default message.';
$Alert->render();
