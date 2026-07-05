<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Question;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Question component @;
 * @#yellow: @@: Demo - Example #3 - autocomplete suggestions @;
 * {$location}
 */\n\n
OUTPUT);

// @ Autocomplete — type to filter, ↑/↓ aim, Tab completes, Enter confirms
$Question = new Question($Input, $Output);
$Question->prompt = 'Country';
$Question->suggestions = [
   'Argentina', 'Australia', 'Brazil', 'Canada', 'Chile', 'France', 'Germany',
   'India', 'Italy', 'Japan', 'Mexico', 'Netherlands', 'Norway', 'Portugal',
   'Spain', 'Sweden', 'Switzerland', 'United Kingdom', 'United States', 'Uruguay'
];
$Question->limit = 5;
$Question->strict = true;

$country = $Question->ask();

$Output->render("@.;Country: @#green:{$country}@;@..;");

// @ Non-strict — free text wins; suggestions only assist
$Question = new Question($Input, $Output);
$Question->prompt = 'Editor';
$Question->suggestions = ['vim', 'nano', 'emacs', 'helix'];

$editor = $Question->ask();

$Output->render("@.;Editor: @#green:{$editor}@; (free text allowed)@.;");
