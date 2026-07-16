<?php
namespace Bootgly\CLI;

use function preg_match;

use const Bootgly\CLI;
use Bootgly\CLI\UX\Components\Form;
use Bootgly\CLI\UX\Components\Form\Controls;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Form component @;
 * @#yellow: @@: Demo 28 - Example #1 - sequential multi-field input @;
 * {$location}
 */\n\n
OUTPUT);

// @ Declarative fields — asked one at a time (`↑` + Enter goes back one field)
$Form = new Form($Input, $Output);
$Form->title = 'New project';

$Form->add(
   'Name',
   required: true,
   Validator: static function (string $answer): true|string {
      // ?:
      if (preg_match('#^[A-Z][A-Za-z0-9_-]*$#', $answer) !== 1) {
         return 'Invalid name: use letters, numbers, `_` or `-`, starting uppercase.';
      }

      // :
      return true;
   }
);
$Form->add('Password', Controls::Secret);
$Form->add('Platform', Controls::Select, default: 'Console', options: ['Console', 'Web', 'Both']);
$Form->add('Git', Controls::Confirm, default: 'yes');

// @ Ask all fields — ends with a summary + confirm loop on interactive terminals
$answers = $Form->ask();

$Output->render("@.;Answers:@..;");
foreach ($answers as $label => $answer) {
   // ? Secret answers stay secret
   if ($label === 'Password') {
      $answer = '•••';
   }

   $Output->render("@#cyan:{$label}@;: @#green:{$answer}@;@.;");
}
