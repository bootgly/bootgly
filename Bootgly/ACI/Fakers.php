<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI;


use function class_exists;
use function strtolower;
use LogicException;


/**
 * Entry-point trait — exposes fake($kind, $seed) on the consuming class.
 *
 * $kind is matched case-insensitively against the concrete fakers shipped
 * under Bootgly\ACI\Fakers\*.
 */
trait Fakers // @phpstan-ignore trait.unused
{
   /**
    * Generate one fake value by concrete faker kind.
    */
   public function fake (string $kind, null|int $seed = null): mixed
   {
      $requested = $kind;
      $kind = match (strtolower($kind)) {
         'email'   => 'Email',
         'integer' => 'Integer',
         'name'    => 'Name',
         'text'    => 'Text',
         'uuid'    => 'UUID',
         default   => $kind,
      };

      /** @var class-string<Faker> $class */
      $class = __NAMESPACE__ . '\\Fakers\\' . $kind;

      if (! class_exists($class)) {
         throw new LogicException("Faker not found: {$requested}");
      }

      return (new $class($seed))->generate();
   }
}
