<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Doubles\Mock;


use function array_map;
use function class_exists;
use function implode;
use function interface_exists;
use function str_starts_with;
use function var_export;
use LogicException;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Throwable;


/**
 * Generates a typesafe proxy class extending/implementing the target.
 *
 * Every public/protected non-final, non-static method is overridden to
 * delegate to $Handler->handle(string $method, array $arguments): mixed.
 *
 * Used by Mock (handler returns stubbed values) and Spy (handler delegates
 * to a real instance while recording calls).
 *
 * Limitations:
 * - final classes cannot be mocked (PHP language constraint)
 * - final methods are inherited from the target unchanged
 * - constructors and destructors are not proxied
 *
 * Implementation note: PHP cannot dynamically declare arbitrary method
 * signatures without `eval`. The emitted code is small, deterministic,
 * and operates only on developer-supplied class names — the same trust
 * boundary as `new $target`.
 */
final class Proxy
{
   /**
    * @var array<string, true>
    */
   private static array $builtin = [
      'self'     => true,
      'static'   => true,
      'parent'   => true,
      'mixed'    => true,
      'never'    => true,
      'void'     => true,
      'iterable' => true,
      'object'   => true,
      'array'    => true,
      'callable' => true,
      'bool'     => true,
      'int'      => true,
      'float'    => true,
      'string'   => true,
      'true'     => true,
      'false'    => true,
      'null'     => true,
   ];


   /**
    * Build a typesafe proxy object for the requested target.
    *
    * @param class-string $target
    */
   public static function build (string $target, object $Handler): object
   {
      if (! class_exists($target) && ! interface_exists($target)) {
         throw new LogicException("Mock target does not exist: {$target}");
      }

      $Class = new ReflectionClass($target);

      if ($Class->isFinal()) {
         throw new LogicException("Cannot mock final class: {$target}");
      }

      $isInterface = $Class->isInterface();

      $body = '';
      foreach ($Class->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $Method) {
         if ($Method->isStatic()) continue;
         if ($Method->isConstructor() || $Method->isDestructor()) continue;
         if ($Method->isFinal() && ! $isInterface) continue;
         if ($Method->returnsReference()) {
            throw new LogicException("Cannot proxy by-reference return method: {$target}::{$Method->getName()}");
         }

         $body .= self::compile($Method);
      }

      $keyword = $isInterface ? 'implements' : 'extends';
      $code = "
return new class(\$Handler) {$keyword} \\{$target} {
   private object \$__bg_Handler;
   public function __construct (object \$Handler)
   {
      \$this->__bg_Handler = \$Handler;
   }
   {$body}
};";

      /** @var object */
      return eval($code);
   }

   /**
    * Compile a target method signature into a proxy method body.
    */
   private static function compile (ReflectionMethod $Method): string
   {
      $method = $Method->getName();

      $params = [];
      $args = [];

      foreach ($Method->getParameters() as $Parameter) {
         $params[] = self::prepare($Parameter);
         $variable = '$' . $Parameter->getName();
         $args[] = $Parameter->isVariadic()
            ? '...' . $variable
            : ($Parameter->isPassedByReference() ? '&' : '') . $variable;
      }

      $paramStr = implode(', ', $params);
      $argStr = implode(', ', $args);

      $returnType = self::resolve($Method->getReturnType());
      $returnDecl = $returnType !== '' ? ': ' . $returnType : '';
      $isVoid = $returnType === 'void';
      $isNever = $returnType === 'never';

      $invoke = "\$this->__bg_Handler->handle('{$method}', [{$argStr}])";
      if ($isNever) {
         // never-returning methods MUST not return — handler is expected to throw.
         $stmt = "{$invoke}; throw new \\LogicException('Mocked never-returning method {$method} did not throw');";
      }
      else if ($isVoid) {
         $stmt = "{$invoke};";
      }
      else {
         $stmt = "return {$invoke};";
      }

      $visibility = $Method->isProtected() ? 'protected' : 'public';

      return "\n   {$visibility} function {$method}({$paramStr}){$returnDecl}\n   {\n      {$stmt}\n   }\n";
   }

   /**
    * Render one reflected method parameter for the generated proxy.
    */
   private static function prepare (ReflectionParameter $Parameter): string
   {
      $type = self::resolve($Parameter->getType());
      $type = $type === '' ? '' : $type . ' ';
      $byRef = $Parameter->isPassedByReference() ? '&' : '';
      $variadic = $Parameter->isVariadic() ? '...' : '';
      $name = '$' . $Parameter->getName();

      $default = '';
      if ($Parameter->isOptional() && ! $Parameter->isVariadic()) {
         if ($Parameter->isDefaultValueConstant()) {
            $constName = $Parameter->getDefaultValueConstantName();
            if ($constName !== null) {
               $default = ' = ' . self::export($constName);
            }
         }
         else if ($Parameter->isDefaultValueAvailable()) {
            try {
               $value = $Parameter->getDefaultValue();
               $default = ' = ' . var_export($value, true);
            }
            catch (Throwable) {
               $default = ' = null';
            }
         }
      }

      return "{$type}{$byRef}{$variadic}{$name}{$default}";
   }

   /**
    * Render a default-value constant name inside generated proxy code.
    */
   private static function export (string $constant): string
   {
      if (
         str_starts_with($constant, 'self::')
         || str_starts_with($constant, 'parent::')
         || str_starts_with($constant, 'static::')
      ) {
         return $constant;
      }

      return '\\' . $constant;
   }

   /**
    * Render a reflected type for generated proxy code.
    */
   private static function resolve (null|ReflectionType $Type): string
   {
      if ($Type === null) {
         return '';
      }

      if ($Type instanceof ReflectionNamedType) {
         $name = $Type->getName();
         $nullable = ($Type->allowsNull() && $name !== 'mixed' && $name !== 'null')
            ? '?'
            : '';
         $rendered = isset(self::$builtin[$name])
            ? $name
            : '\\' . $name;

         return $nullable . $rendered;
      }

      if ($Type instanceof ReflectionUnionType) {
         return implode('|', array_map(
            static fn (ReflectionType $Type): string => self::resolve($Type),
            $Type->getTypes()
         ));
      }

      if ($Type instanceof ReflectionIntersectionType) {
         return implode('&', array_map(
            static fn (ReflectionType $Type): string => self::resolve($Type),
            $Type->getTypes()
         ));
      }

      return '';
   }
}