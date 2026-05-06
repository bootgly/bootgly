<?php

namespace Bootgly\PHPStan\Rules;


use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use Bootgly\API\Environment\Configs\Config;


class ConfigPropertyReflection implements PropertyReflection
{
   public function __construct (
      private ClassReflection $classReflection
   )
   {}

   public function getDeclaringClass (): ClassReflection
   {
      return $this->classReflection;
   }

   public function isStatic (): bool
   {
      return false;
   }

   public function isPrivate (): bool
   {
      return false;
   }

   public function isPublic (): bool
   {
      return true;
   }

   public function isReadable (): bool
   {
      return true;
   }

   public function isWritable (): bool
   {
      return false;
   }

   public function getReadableType (): Type
   {
      return new ObjectType(Config::class);
   }

   public function getWritableType (): Type
   {
      return new ObjectType(Config::class);
   }

   public function canChangeTypeAfterAssignment (): bool
   {
      return false;
   }

   public function isDeprecated (): TrinaryLogic
   {
      return TrinaryLogic::createNo();
   }

   public function getDeprecatedDescription (): null|string
   {
      return null;
   }

   public function isInternal (): TrinaryLogic
   {
      return TrinaryLogic::createNo();
   }

   public function getDocComment (): null|string
   {
      return null;
   }
}
