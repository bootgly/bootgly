<?php

namespace Demo\Spacing;


use function count;
use function sprintf;
use function strlen;
use function strtolower;
use function strtoupper;


function main (int $a): int
{
   $double = function (int $x): int { return $x * 2; };
   $triple = fn (int $x): int => $x * 3;
   $ref = function & (array &$list): array { return $list; };
   $static = static fn (): int => 1;

   if ($a > 0) {
      foreach ([1, 2] as $n) {
         $a += $n;
      }
   }
   $value = match ($a) { 1 => 'one', default => 'many' };
   $isset = isset($value) && empty($value) === false;
   [$first] = [1];
   list($second) = [2];
   // # Language constructs are not calls: any spacing is accepted
   $constructs = array ('a' => isset ($value), 'b' => empty ($value));
   list ($third) = array (3);
   unset ($constructs['a']);

   return strlen((string) $double($triple($a))) + $static() + count($ref([$first, $second, $isset]));
}

class Holder
{
   public string $name {
      set (string $value) {
         $this->name = strtolower($value);
      }
   }

   public function &read (): array { static $list = []; return $list; }
   public function build (): static { return new static(); }
   public function call (): string { return strtoupper(self::class) . Holder::make() . $this->name; }
   public static function make (): string { return sprintf('%s', static::class); }
}

class Arms
{
   public string $flag {
      #[SensitiveParameter] set (string $value) {
         $this->flag = $value;
      }
   }

   public function pick (object $Object, int $key): array
   {
      // # Calls named get/set before `=>` are calls: an array key, a match arm
      $keys = [get($key) => 1, set($key) => 2];
      $arm = match (true) { $Object->set(1) => 2, default => 3 };
      $call = match ($key) { get(1) => 2, set(2) => 3, default => 4 };

      return [$keys, $arm, $call];
   }
}
