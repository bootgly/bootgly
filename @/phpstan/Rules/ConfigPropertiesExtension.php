<?php

namespace Bootgly\PHPStan\Rules;


use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertiesClassReflectionExtension;
use PHPStan\Reflection\PropertyReflection;

use Bootgly\API\Environment\Configs\Config;


class ConfigPropertiesExtension implements PropertiesClassReflectionExtension
{
   public function hasProperty (ClassReflection $classReflection, string $propertyName): bool
   {
      return $classReflection->getName() === Config::class;
   }

   public function getProperty (ClassReflection $classReflection, string $propertyName): PropertyReflection
   {
      return new ConfigPropertyReflection($classReflection);
   }
}
