<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging\Data\Vars;


use ReflectionClass;

use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;


/**
 * Debug dumps with secrets masked.
 *
 * An object that uses it shows in `var_dump`, `print_r`, `debug_zval_dump`
 * and `Dumper` as PHP would show it — every initialized property under its
 * visibility, in its order — except that each `#[Secret]` property is
 * redacted. Nothing runs until the object is dumped.
 *
 * The body is the object's `(array)` cast copied by value: no get hook runs
 * (a hook may create a directory or build a key), and a property that holds
 * a reference keeps its live value. Uninitialized and virtual properties are
 * absent from it, as from any `(array)` cast — `Dumper` still lists them,
 * without a value.
 *
 * Covers debug dumps only: `var_export()`, `(array)`, `get_object_vars()`,
 * `json_encode()` and `serialize()` still see a public property as it is.
 */
trait Redactable
{
   /**
    * The properties as PHP lists them — mangled names, raw values — each
    * `#[Secret]` one redacted.
    *
    * Untyped on purpose: a subclass may still declare its own `__debugInfo()`
    * without a return type, or with `?array`.
    *
    * @return array<string,mixed>
    */
   public function __debugInfo ()
   {
      // ! Per class: mangled name => Secret, built once
      /** @var array<string,array<string,Secret>> $Maps */
      static $Maps = [];
      $class = $this::class;
      if (isSet($Maps[$class]) === false) {
         $Map = [];
         $Class = new ReflectionClass($this);
         // @@ The class, then each ancestor — a private property keeps its declaring class
         do {
            foreach ($Class->getProperties() as $Property) {
               if ($Property->isStatic() === true || $Property->class !== $Class->name) {
                  continue;
               }
               $Attributes = $Property->getAttributes(Secret::class);
               if ($Attributes === []) {
                  continue;
               }

               $name = $Property->name;
               $mangled = match (true) {
                  $Property->isPrivate() === true => "\0{$Property->class}\0{$name}",
                  $Property->isProtected() === true => "\0*\0{$name}",
                  default => $name
               };
               $Map[$mangled] ??= $Attributes[0]->newInstance();
            }
            $Class = $Class->getParentClass();
         } while ($Class !== false);
         $Maps[$class] = $Map;
      }
      $Map = $Maps[$class];

      // @@ Copied by value — never assigned into the cast, whose slots may be references
      $body = [];
      foreach ((array) $this as $key => $value) {
         $body[$key] = isSet($Map[$key]) === true ? $Map[$key]->redact($value) : $value;
      }

      // :
      return $body;
   }
}
