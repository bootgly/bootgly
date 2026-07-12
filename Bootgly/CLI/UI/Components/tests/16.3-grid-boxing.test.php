<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should place any Boxing implementer over the grid tracks',
   test: function () {
      // ! A non-Frame Boxing implementer (stub)
      $Stub = new class implements Boxing {
         // * Config
         public int $row = 1;
         public int $column = 1;
         public int $width = 0;
         public int $height = 0;

         // * Metadata
         public int $invalidated = 0;
         public int $rendered = 0;


         public function invalidate (): void
         {
            $this->invalidated++;
         }

         public function render (int $mode = Component::WRITE_OUTPUT): null|string
         {
            $this->rendered++;

            // :
            return "stub\n";
         }
      };

      $Host = new Output('php://memory');

      $Grid = new Grid($Host);
      $Grid->width = 80;
      $Grid->height = 30;
      $Grid->rows = [1, 1];
      $Grid->columns = [1, 1];

      // @ Placement assigns the stub geometry like any Frame
      $Grid->place($Stub, row: 2, column: 2);

      yield assert(
         assertion: $Stub->row === 16 && $Stub->column === 41
            && $Stub->width === 40 && $Stub->height === 15,
         description: 'Placing assigns geometry to any Boxing implementer'
      );
      yield assert(
         assertion: $Grid->Cells[0]->Box === $Stub,
         description: 'The cell exposes the placed box'
      );

      // @ Rendering delegates in painter order; resize invalidates the box
      $Frame = new Frame($Host);
      $Grid->place($Frame, row: 1, column: 1);

      $returned = (string) $Grid->render(Grid::RETURN_OUTPUT);

      yield assert(
         assertion: $Stub->rendered === 1 && str_contains($returned, 'stub') === true,
         description: 'Rendering delegates to every placed box in painter order'
      );

      $Grid->resize(60, 20);

      yield assert(
         assertion: $Stub->invalidated === 1 && $Stub->width === 30 && $Stub->height === 10,
         description: 'Resizing invalidates and reflows every placed box'
      );
   }
);
