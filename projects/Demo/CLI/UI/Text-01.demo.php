<?php
namespace Bootgly\CLI;

use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Text;
use Bootgly\CLI\UI\Components\Text\Effects;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Text component @;
 * @#yellow: @@: Demo - Example #1 - typewriter, fade and shimmer @;
 * {$location}
 */\n\n
OUTPUT);

// @ Typewriter — one character at a time
$Text = new Text($Output);
$Text->Effects = Effects::Typewriter;
$Text->interval = 40_000;
$Text->content = 'Bootgly writes this one character at a time...';
$Text->play();

// @ Fade — dim → normal → bold, repainted in place
$Fade = new Text($Output);
$Fade->Effects = Effects::Fade;
$Fade->interval = 60_000;
$Fade->content = 'This line fades in.';
$Fade->play();

// @ Shimmer — a color wave passes letter by letter, left to right
$Shimmer = new Text($Output);
$Shimmer->Effects = Effects::Shimmer;
$Shimmer->interval = 45_000;
$Shimmer->content = 'Shimmering while you wait...';

$Shimmer->start();

for ($wait = 0; $wait < 70; $wait++) {
   usleep(45_000); // simulated waiting

   $Shimmer->tick();
}

$Shimmer->finish();

$Output->render("@#Green:✔@; Effects done.@.;");
