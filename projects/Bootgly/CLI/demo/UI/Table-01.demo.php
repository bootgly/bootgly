<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Table;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*: 
 * @#green: Bootgly CLI UI - Table component @;
 * @#yellow: @@: Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);

$Table = new Table($Output);

$Table->Data->Header->set([['Alura Courses', 'Quantity']]);
$Table->Data->Body->set([
   ['Programação',       280], // using multibyte text
   ['Front-end',         112],
   ['Data Science',      209],
   ['DevOps',            135],
   ['@---;'],                  // That's a row separator!
   ['UX & Design',       276],
   ['Mobile',            93],
   ['Inovação & Gestão', 297], // using multibyte text
]);
$Table->Data->Footer->set([[
   'Total:',  $Table->Data->sum(column: 1)
]]);

$Output->write("\n");

$loops = 3;
for ($i = 1; $i <= $loops; $i++) {
   $alignment = match ($i) {
      1 => 'left',
      2 => 'center',
      3 => 'right'
   };

   // ! Cells
   $Table->Cells->align($alignment);
   $Output->append('Cells - text aligment: ' . $alignment . '   ');

   $Table->render();

   sleep(1);

   $Output->write("\n");
   $Output->Cursor->up(16);
   $Output->Text->clear(down: true);
}
