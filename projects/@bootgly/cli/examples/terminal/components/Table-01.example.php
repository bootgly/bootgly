<?php
namespace Bootgly\CLI;

use Bootgly\CLI;
use Bootgly\CLI\Terminal\components\Table;

$Output = CLI::$Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal - Table component @;
 * @#yellow: @@ Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);

$Table = new Table($Output);

$Table->Data->set(header: ['Alura Courses', 'Quantity']);
$Table->Data->set(body: [
   ['Programação',       280], // using multibyte text
   ['Front-end',         112],
   ['Data Science',      209],
   ['DevOps',            135],
   ['@---;'],                  // That's a row separator!
   ['UX & Design',       276],
   ['Mobile',            93],
   ['Inovação & Gestão', 297], // using multibyte text
]);
$Table->Data->set(footer: [
   'Total:',  $Table->Data->sum(column: 1)
]);

$Output->write("\n");

$loops = 3;
for ($i = 1; $i <= $loops; $i++) {
   switch ($i) {
      case 1:
         $alignment = 'left';
         break;
      case 2:
         $alignment = 'center';
         break;
      case 3:
         $alignment = 'right';
         break;
   }

   // ! Cells
   $Table->Cells->align($alignment);
   $Output->append('Cells - text aligment: ' . $alignment . '   ');

   $Table->render();

   sleep(1);

   $Output->write("\n");
   $Output->Cursor->up(16);
   $Output->Text->clear(down: true);
}
