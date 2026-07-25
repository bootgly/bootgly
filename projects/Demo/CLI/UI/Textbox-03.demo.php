<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Textbox;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Textbox component @;
 * @#yellow: @@: Demo 38 - Example #3 - autocomplete options @;
 * {$location}
 */\n\n
OUTPUT);

// @ Autocomplete — type to filter, ↑/↓ aim, Tab completes, Enter confirms
$Textbox = new Textbox($Input, $Output);
$Textbox->prompt = 'Country';
$Textbox->options = [
   'Argentina', 'Australia', 'Brazil', 'Canada', 'Chile', 'France', 'Germany',
   'India', 'Italy', 'Japan', 'Mexico', 'Netherlands', 'Norway', 'Portugal',
   'Spain', 'Sweden', 'Switzerland', 'United Kingdom', 'United States', 'Uruguay'
];
$Textbox->viewport = 5;
$Textbox->strict = true;

$country = $Textbox->ask();

$Output->render("@.;Country: @#green:{$country}@;@..;");

// @ Non-strict — free text wins; the options only assist
$Textbox = new Textbox($Input, $Output);
$Textbox->prompt = 'Editor';
$Textbox->options = ['vim', 'nano', 'emacs', 'helix'];

$editor = $Textbox->ask();

$Output->render("@.;Editor: @#green:{$editor}@; (free text allowed)@.;");
