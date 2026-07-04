<?php
namespace Bootgly\CLI;

use function preg_match;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Question;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Question component @;
 * @#yellow: @@: Demo - Example #1 - single input with validation @;
 * {$location}
 */\n\n
OUTPUT);

// @ Question with a default (press Enter to accept it)
$Question = new Question($Input, $Output);
$Question->prompt = 'Server port';
$Question->default = '8080';
$Question->Validator = static function (string $answer): true|string {
   // ?:
   if (preg_match('#^\d{1,5}$#', $answer) !== 1) {
      return 'Invalid port: use a number between 1 and 65535.';
   }

   // :
   return true;
};

$port = $Question->ask();

$Output->render("@.;Port accepted: @#cyan:{$port}@;@..;");

// @ Required question (re-asks on empty answers; 3 attempts)
$Question = new Question($Input, $Output);
$Question->prompt = 'Project name';
$Question->required = true;
$Question->attempts = 3;
$Question->default = 'MyApp';

$name = $Question->ask();

$Output->render("@.;Project name: @#green:{$name}@; (attempt {$Question->attempt})@..;");
