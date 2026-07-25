<?php
namespace Bootgly\CLI;

use function strlen;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Textbox;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Textbox component @;
 * @#yellow: @@: Demo 29 - Example #2 - masked (secret) input @;
 * {$location}
 */\n\n
OUTPUT);

// @ Masked answer — each typed character echoes `•` instead
$Textbox = new Textbox($Input, $Output);
$Textbox->prompt = 'Password';
$Textbox->required = true;
$Textbox->mask = '•';
$Textbox->Validator = static function (string $answer): true|string {
   // ?:
   if (strlen($answer) < 6) {
      return 'Too short: use at least 6 characters.';
   }

   // :
   return true;
};

$password = $Textbox->ask();

$length = strlen($password);
$Output->render("@.;Password accepted (@#cyan:{$length}@; characters — never echoed).@..;");

// @ Masked default — the prompt shows the mask, never the value
$Textbox = new Textbox($Input, $Output);
$Textbox->prompt = 'API token';
$Textbox->default = 'tk-demo-0000';
$Textbox->mask = '*';

$token = $Textbox->ask();

$masked = $token === 'tk-demo-0000' ? 'the default' : 'a custom token';
$Output->render("@.;Token recorded: @#green:{$masked}@; (value stays masked).@..;");
