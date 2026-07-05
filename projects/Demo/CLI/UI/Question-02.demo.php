<?php
namespace Bootgly\CLI;

use function strlen;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Question;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Question component @;
 * @#yellow: @@: Demo - Example #2 - masked (secret) input @;
 * {$location}
 */\n\n
OUTPUT);

// @ Masked answer — each typed character echoes `•` instead
$Question = new Question($Input, $Output);
$Question->prompt = 'Password';
$Question->required = true;
$Question->mask = '•';
$Question->Validator = static function (string $answer): true|string {
   // ?:
   if (strlen($answer) < 6) {
      return 'Too short: use at least 6 characters.';
   }

   // :
   return true;
};

$password = $Question->ask();

$length = strlen($password);
$Output->render("@.;Password accepted (@#cyan:{$length}@; characters — never echoed).@..;");

// @ Masked default — the prompt shows the mask, never the value
$Question = new Question($Input, $Output);
$Question->prompt = 'API token';
$Question->default = 'tk-demo-0000';
$Question->mask = '*';

$token = $Question->ask();

$masked = $token === 'tk-demo-0000' ? 'the default' : 'a custom token';
$Output->render("@.;Token recorded: @#green:{$masked}@; (value stays masked).@..;");
