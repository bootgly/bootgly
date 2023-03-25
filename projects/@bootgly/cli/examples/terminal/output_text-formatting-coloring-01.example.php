<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;
$Output->reset();
$Output->waiting = 10000;

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal (>) - Text Formatting - Coloring @;
 * @#yellow: @@ Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);


// @ Testing Preset colors: foreground + default background
$Output->write(<<<OUTPUT
*
* Testing Preset colors: foreground + default background
*\n\n
OUTPUT);
$Output->Text->colorize(foreground: 'cyan');
$Output->writing("Writing with the color of the text in cyan on a `default` background...\n\n");


// @ Testing Preset colors: foreground + background
$Output->Text->colorize(); // @ Reset colors
$Output->write(<<<OUTPUT
*
* Testing Preset colors (default colors): foreground + background
*\n
OUTPUT);

$Output->Text->colorize(foreground: 'black', background: 'white');
$Output->writing("Writing with the color of the text in `black` on a `white` background...\n");

$Output->Text->colorize(foreground: 'white', background: 'red');
$Output->writing("Writing with the color of the text in `white` on a `red` background...\n");

$Output->Text->colorize(foreground: 'white', background: 'green');
$Output->writing("Writing with the color of the text in `white` on a `green` background...\n");

$Output->Text->colorize(foreground: 'white', background: 'blue');
$Output->writing("Writing with the color of the text in `white` on a `blue` background...\n");

$Output->Text->colorize(foreground: 'black', background: 'yellow');
$Output->writing("Writing with the color of the text in `black` on a `yellow` background...\n");

$Output->Text->colorize(foreground: 'white', background: 'magenta');
$Output->writing("Writing with the color of the text in `white` on a `magenta` background...\n");

$Output->Text->colorize(foreground: 'black', background: 'cyan');
$Output->writing("Writing with the color of the text in `black` on a `cyan` background...\n");

$Output->Text->colorize(foreground: 'white', background: 'black');
$Output->writing("Writing with the color of the text in `white` on a `black` background...\n\n");

// @ Testing Preset colors: foreground + background
$Output->Text->colorize(); // @ Reset colors
$Output->write(<<<OUTPUT
*
* Testing Preset colors ("bright" colors): foreground + background
*\n
OUTPUT);

$Output->Text->Colors::Bright->set(); // @ Config the set of colors using enums 🤯

$Output->Text->colorize(foreground: 'black', background: 'white');
$Output->writing("Writing with the color of the text in `black` on a `white` background...\n");

$Output->Text->colorize(foreground: 'white', background: 'red');
$Output->writing("Writing with the color of the text in `white` on a `red` background...\n");

$Output->Text->colorize(foreground: 'white', background: 'green');
$Output->writing("Writing with the color of the text in `white` on a `green` background...\n");

$Output->Text->colorize(foreground: 'white', background: 'blue');
$Output->writing("Writing with the color of the text in `white` on a `blue` background...\n");

$Output->Text->colorize(foreground: 'black', background: 'yellow');
$Output->writing("Writing with the color of the text in `black` on a `yellow` background...\n");

$Output->Text->colorize(foreground: 'white', background: 'magenta');
$Output->writing("Writing with the color of the text in `white` on a `magenta` background...\n");

$Output->Text->colorize(foreground: 'black', background: 'cyan');
$Output->writing("Writing with the color of the text in `black` on a `cyan` background...\n");

$Output->Text->colorize(foreground: 'white', background: 'black');
$Output->writing("Writing with the color of the text in `white` on a `black` background...\n\n");

// @ Testing extended colors (0-255)
$Output->Text->colorize(); // @ Reset colors
$Output->write(<<<OUTPUT
*
* Testing extended colors (0-255)
*\n
OUTPUT);
$Output->Text->colorize(foreground: 209, background: 18);
$Output->writing("Writing a text with color index '209' on a background with color index '18'!\n\n");


// !!! If you have photosensitive epilepsy, please avoid looking at the screen when running this code below.
// !!! The flashing lights colored may trigger a seizure. Take care of your health and safety.

// !!! Se você tem epilepsia fotossensível, por favor, evite olhar para a tela quando executar esse código abaixo.
// !!! As luzes piscantes coloridas podem desencadear uma crise epiléptica. Cuide da sua saúde e segurança.
/* @*:
$Output->waiting = 1000;

for ($f = 0; $f < 255; $f++) {
   for ($b = 0; $b < 255; $b++) {
      $Output->Text->colorize(foreground: $f, background: $b);

      $foreground = str_pad((string) $f, 3, '0', STR_PAD_LEFT);
      $background = str_pad((string) $b, 3, '0', STR_PAD_LEFT);
      $Output->writing("$foreground $background");

      $Output->Text->colorize();
      $Output->write(" ");
   }
}
*/

// @ Reset foreground and background to default
$Output->Text->colorize();
$Output->append('Text color reseted to default...');

$Output->write("\n");
