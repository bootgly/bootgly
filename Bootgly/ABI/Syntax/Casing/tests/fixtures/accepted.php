<?php

namespace Demo\Casing;


use const E_ALL;
use const JSON_PRETTY_PRINT;
use const PHP_EOL;
use function json_encode;
use function strlen;
use Closure;
use DateTime;
use Throwable;


class Holder
{
   // * Config
   public string $name;
   public int $SQL;
   public null|DateTime $Clock = null;
   public Modes $mode;
   public object $generated;
   public int|DateTime $mixed;
   /** @var array<int,DateTime> */
   public array $Dates = [];
   /** @var array<int,string> */
   public array $labels = [];
   /** @var array<int,Modes> */
   public array $modes = [];


   /**
    * @param array<int,DateTime> $Moments
    * @param array<string,int> $counts
    */
   public function run (Closure $Callback, string $URL, array $Moments, array $counts, Modes $mode, &$untyped): void
   {
      $Date = new DateTime();
      $HTML = '<p>';
      $stamp = new DateTime()->format('U');
      $copy = clone $Date;
      $this->name = 'x';
      $count = 0;

      try {
         $Callback($URL, $Moments, $counts, $mode, $untyped, $HTML, $stamp, $copy, $count);
      }
      catch (Throwable $Throwable) {
         unset($Throwable);
      }
   }
}

enum Modes
{
   case Strict;
}

class Operands
{
   public function mix (int $flags, int $a, int $b): array
   {
      // # A constant before a variable in an expression is an operand, not a type
      $encoded = json_encode([], JSON_PRETTY_PRINT | $flags);
      $masked = (E_ALL & $flags);
      $picked = strlen(PHP_EOL ? (string) $a : (string) $b);

      return [$encoded, $masked, $picked];
   }
}
