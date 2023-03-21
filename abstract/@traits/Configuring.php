<?php
namespace Bootgly;


trait Configuring
{
   public static function get ()
   {
      $Reflection = new \ReflectionEnum(self::class);

      return $Reflection->name;
   }
   public function set ()
   {
      // TODO
   }
}
