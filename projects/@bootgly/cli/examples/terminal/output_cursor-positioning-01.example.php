<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal (>) - Cursor Positioning @;
 * @#yellow: @@ Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);

$Output->writing("Cursor Positioning on _:\n");
$Output->writing("Moving up 1 line from current line and going back 53 columns to the left...");

$Output->Cursor->up(lines: 1);
$Output->Cursor->left(columns: 53);

$Output->writing("Bootgly: moving down 2 lines and going to column 1...");

$Output->Cursor->down(lines: 2);
$Output->Cursor->moveTo(column: 1);

$Output->writing("Continuing writing and moving down 2 lines to column 3...");
$Output->Cursor->down(lines: 2, column: 3);

$Output->writing("- Continuing writing and moving up 2 lines to column 1...");
$Output->Cursor->up(lines: 2, column: 1);

$Output->writing("Moving down 3 lines to column 1 and moving 6 columns to the right...");
$Output->Cursor->down(3, column: 1);
$Output->Cursor->right(columns: 6);

$Output->writing("+ Continue writing and moving down 2 lines to column 1...");
$Output->Cursor->down(2, column: 1);
// Example 2 - Cursor right / left methods
$Output->write("_______");
$Output->Cursor->moveTo(column: 1);

$Output->wait = 100000; // @ Set wait time between writes (in microseconds)

$Output
   ->write("B")
   ->Cursor->right(columns: 5)
   ->write("y")
   ->Cursor->left(columns: 4)
   ->write("t")
   ->Cursor->right(columns: 1)
   ->write("l")
   ->Cursor->left(columns: 5)
   ->write("o")
   ->Cursor->right(columns: 2)
   ->write("g")
   ->Cursor->left(columns: 3)
   ->write("o");

$Output->write("\n");
