<?php
namespace Bootgly\CLI;

use function preg_match;
use function usleep;
use Exception;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Menu;
use Bootgly\CLI\UI\Components\Question;
use Bootgly\CLI\UX\Wizard;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

// @ Declarative steps on the Timeline spine — past ✔ / active ◉ / future ○
// ? The title heads the fixed frame on every repaint (the screen clears per step)
$Wizard = new Wizard($Input, $Output);
$Wizard->title = <<<TITLE
/* @*:
 * @#green: Bootgly CLI UX - Wizard component @;
 * @#yellow: @@: Demo 48 - Example #1 - declarative multi-step flow @;
 * {$location}
 */
TITLE;

$Wizard->add('Name', function (Wizard $Wizard): string {
   $Question = new Question($Wizard->Input, $Wizard->Output);
   $Question->prompt = 'Project name';
   $Question->required = true;
   $Question->default = 'App';
   $Question->Validator = static function (string $answer): true|string {
      // ?:
      if (preg_match('#^[A-Z][A-Za-z0-9_-]*$#', $answer) !== 1) {
         return 'Invalid name: use letters, numbers, `_` or `-`, starting uppercase.';
      }

      // :
      return true;
   };

   // :
   return $Question->ask();
});

$Wizard->add('Interface', function (Wizard $Wizard): string {
   $Menu = new Menu($Wizard->Input, $Wizard->Output);
   $Menu->prompt = "@#Cyan:Which interface?@;\n@#Black:(↑/↓ to move, Space to select one, Enter to confirm)@;\n";

   $Options = $Menu->Items->Options;
   $Options->Selection::Unique->set();
   $Options->add(label: 'CLI — Console app');
   $Options->add(label: 'WPI — Web (HTTP) server');

   // @@ Render until Enter
   foreach ($Menu->rendering() as $ignored);

   $interface = (int) ($Menu->selected[0] ?? 0) === 1 ? 'WPI' : 'CLI';

   // ? WPI flows branch: a Port step slots in right after this one
   if ($interface === 'WPI') {
      $Wizard->add('Port', function (Wizard $Wizard): string {
         $Question = new Question($Wizard->Input, $Wizard->Output);
         $Question->prompt = 'Server port';
         $Question->default = '8080';

         // :
         return $Question->ask();
      });
   }

   // :
   return $interface;
});

$Wizard->add('Confirm', function (Wizard $Wizard): null {
   $Question = new Question($Wizard->Input, $Wizard->Output);

   // ? Throw a short slug to fail the step and stop the flow
   if ($Question->confirm('Build the project?', default: true) === false) {
      throw new Exception('aborted');
   }

   // :
   return null;
});

$Wizard->add('Build', function (Wizard $Wizard): string {
   usleep(700_000);

   // :
   return 'done';
});

// @ Run the flow — false on failure (the Throwable is exposed)
$done = $Wizard->run();

$done === true
   ? $Output->render("@.;@#Green:✔@; Wizard complete.@.;")
   : $Output->render("@.;@#Red:✖@; Wizard stopped: {$Wizard->Throwable?->getMessage()}.@.;");
