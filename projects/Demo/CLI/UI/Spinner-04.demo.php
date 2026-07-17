<?php
namespace Bootgly\CLI;

use function number_format;
use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Atoms\Text\Effects;
use Bootgly\CLI\UI\Components\Spinner;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Spinner component @;
 * @#yellow: @@: Demo 32.3 - Example #4 - pulse effect and live download status @;
 * {$location}
 */\n\n
OUTPUT);

// @ Pulsed description + live download status — the `(...)` segment is data-driven
$Spinner = new Spinner($Output);
$Spinner->set = 'dots';
$Spinner->effect = Effects::Fade;
$Spinner->tips = [
   'Tip: assign $Spinner->status anytime — the next repaint carries it.',
   'Tip: the @elapsed; token formats the running time for free.'
];
$Spinner->rotation = 2.0;
$Spinner->start('Downloading bootgly.zip');

$downloaded = 0.0;
for ($work = 0; $work < 55; $work++) {
   usleep(80_000); // simulated work

   $downloaded += 17.3;
   $MB = number_format($downloaded / 100, 2);
   $Spinner->status = "@elapsed; · {$MB} MB · 2.1 MB/s";

   $Spinner->spin();
}

$Spinner->finish('@#Green:✔@; bootgly.zip downloaded.');
