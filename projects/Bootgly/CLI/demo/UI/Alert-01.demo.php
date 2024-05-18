<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Alert\Alert;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*: 
 * @#green: Bootgly CLI UI - Alert component @;
 * @#yellow: @@: Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);

$Alert = new Alert($Output);

$Alert->Type::Success->set();
$Alert->message = 'Success message.';
$Alert->render();

$Alert->Type::Attention->set();
$Alert->message = 'Attention message.';
$Alert->render();

$Alert->Type::Failure->set();
$Alert->message = 'Failure message.';
$Alert->render();

$Alert->Type::Default->set();
$Alert->message = 'Default message.';
$Alert->render();
