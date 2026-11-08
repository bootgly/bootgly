<?php

namespace Demo\Casing;


use Closure;
use DateTime;
use Throwable;


class Holder
{
   // * Config
   public string $url;
   public null|DateTime $clock = null;
   public null|DateTime $date = null;
   /** @var array<int,DateTime> */
   public array $dates = [];
   /** @var DateTime[] */
   protected array $moments = [];
   public string $Json;


   /**
    * @param array<int,DateTime> $stamps
    */
   public function run (Closure $callback, string $html, array $stamps, self $parent, string ...$uris): void
   {
      $date = new DateTime();
      $other = new class () {};
      $sql = 'SELECT 1';
      $sql = 'SELECT 2';

      try {
         $callback($html, $stamps, $parent, $uris, $date, $other, $sql);
      }
      catch (Throwable $caught) {
         unset($caught);
      }
   }
}

$https = 'on';

class Hooked
{
   public DateTime $Moment {
      set (DateTime $moment) {
         $this->Moment = $moment;
      }
   }
}

class Finalized
{
   public DateTime $When {
      final set (DateTime $when) {
         $this->When = $when;
      }
   }
}
