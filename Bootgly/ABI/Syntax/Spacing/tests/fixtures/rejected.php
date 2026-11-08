<?php

namespace Demo\Spacing;


use function count;
use function intval;
use function strlen;


function main(int $a): int
{
   $double = function(int $x): int { return $x * 2; };
   $triple = fn(int $x): int => $x * 3;
   $wide = function  (): int { return 1; };

   return strlen ((string) $double($triple($a))) + $wide () + count (
      [1]
   ) + intval ('1') + new Holder ()->measure();
}

class Holder
{
   public string $name {
      set(string $value) {
         $this->name = $value;
      }
   }

   public function measure(): int { return isset ($this->name) ? 1 : 0; }
   public function keep /* note */ (): int { return 0; }
}
