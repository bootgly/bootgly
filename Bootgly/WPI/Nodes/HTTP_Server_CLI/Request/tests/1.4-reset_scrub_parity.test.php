<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


/**
 * Reflection parity — `Request::reset()` must account for EVERY backed,
 * non-readonly instance property of Request that `Request::decode()` does
 * not itself rewrite on Complete. The cache-miss path runs
 * `reset()` + `decode()` on the connection-owned instance: a property
 * covered by neither would survive between requests on one connection.
 *
 * Method: poison every such property with a sentinel value, run `reset()`,
 * then require each property to land on the fresh-constructed default (or
 * become lazily re-derivable). Properties on the explicit DECODE-COVERED
 * list (rewritten by every `decode()` Complete: request line, protocol,
 * connection truth, Header via define/adopt) legitimately keep their
 * sentinel here. A property missing from BOTH lists fails the sweep.
 *
 * Excluded: readonly $on/$at/$time (documented connection-lifetime
 * staleness) and virtual hooked properties (no backing store).
 */

const BOOTGLY_RESET_UNINIT = "\0__uninitialized__\0";

return new Specification(
   description: 'Request::reset() covers every backed property decode() does not rewrite',
   test: function () {
      $rawGet = function (object $object, ReflectionProperty $P): mixed {
         try {
            return $P->getRawValue($object);
         }
         catch (Error) {
            return BOOTGLY_RESET_UNINIT;
         }
      };

      // ! Members `decode()` rewrites on every Complete — reset() may skip.
      $decodeCovered = [
         'method', 'URI', 'protocol',
         'address', 'peer', 'port', 'scheme',
         'closeConnection',
      ];

      $Fresh = new Request;
      $Worker = new Request;
      $WorkerBody = $Worker->Body;

      $sentinel = function (ReflectionProperty $P, array $allowed): mixed {
         $type = $P->getType();
         $name = $P->getName();

         $branches = [];
         if ($type instanceof ReflectionNamedType) {
            $branches = [$type];
         }
         else if ($type instanceof ReflectionUnionType) {
            $branches = $type->getTypes();
         }

         foreach ($branches as $Branch) {
            if (! $Branch instanceof ReflectionNamedType) {
               continue;
            }

            switch ($Branch->getName()) {
               case 'string': case 'mixed':
                  return "__POISON-{$name}__";
               case 'int':
                  return -424242;
               case 'float':
                  return -42.42;
               case 'array':
                  return ['__poison__' => $name];
               case 'bool':
                  if (! in_array(true, $allowed, true)) return true;
                  if (! in_array(false, $allowed, true)) return false;
                  break;
               default:
                  if ($Branch->isBuiltin() === false) {
                     try {
                        return (new ReflectionClass($Branch->getName()))
                           ->newInstanceWithoutConstructor();
                     }
                     catch (ReflectionException) {
                        break;
                     }
                  }
            }
         }

         return null;
      };

      $sweep = function (
         object $Node, object $FreshNode, array $skip
      ) use ($rawGet, $sentinel): array {
         $poisoned = [];
         $vacuous = [];
         $Reflection = new ReflectionClass($Node);

         foreach ($Reflection->getProperties() as $P) {
            $name = $P->getName();

            if ($P->isStatic()
               || $P->isReadOnly()
               || $P->isVirtual()
               || in_array($name, $skip, true)
            ) {
               continue;
            }

            $allowed = [
               $rawGet($FreshNode, $P),
               BOOTGLY_RESET_UNINIT,
            ];

            $value = $sentinel($P, $allowed);
            if ($value === null || in_array($value, $allowed, true)) {
               $vacuous[] = $name;
               continue;
            }

            $P->setRawValue($Node, $value);
            $poisoned[$name] = $value;
         }

         return [$poisoned, $vacuous];
      };

      $verify = function (object $Node, array $poisoned) use ($rawGet): array {
         $failures = [];
         $Reflection = new ReflectionClass($Node);

         foreach ($poisoned as $name => $sentinelValue) {
            $P = $Reflection->getProperty($name);
            if ($rawGet($Node, $P) === $sentinelValue) {
               $failures[] = $name;
            }
         }

         return $failures;
      };

      // @@ Poison: Request (skipping decode-covered + sub-objects) and the
      //    FULL Body (reset() must restore all constructor defaults there).
      //    Header is decode-covered as a whole (define/adopt rewrite it and
      //    adopt() drops the Cookies memo) — identity asserted below.
      [$poisonedRequest, $vacuousRequest] = $sweep(
         $Worker, $Fresh,
         skip: [...$decodeCovered, 'Header', 'Body']
      );
      [$poisonedBody, $vacuousBody] = $sweep(
         $WorkerBody, $Fresh->Body,
         skip: []
      );

      // @@ Act
      $Worker->reset();

      // @@ Assert
      $failures = [
         ...array_map(fn ($n) => "Request::\${$n}", $verify($Worker, $poisonedRequest)),
         ...array_map(fn ($n) => "Body::\${$n}", $verify($WorkerBody, $poisonedBody)),
      ];

      yield assert(
         assertion: $failures === [],
         description: 'Every poisoned property was scrubbed by reset()'
            . ($failures !== [] ? ' — MISSED: ' . implode(', ', $failures) : '')
            . ' (poisoned: ' . count($poisonedRequest) . ' Request, '
            . count($poisonedBody) . ' Body)'
      );

      // ? Sub-object identity preserved (the perf contract: no realloc)
      yield assert(
         assertion: $Worker->Body === $WorkerBody,
         description: 'reset() reuses the existing Body instance'
      );

      // ? Vacuous-skip budget — review on growth.
      $vacuous = [...$vacuousRequest, ...$vacuousBody];
      yield assert(
         assertion: count($vacuous) <= 6,
         description: 'Vacuous (untestable) properties stay bounded: '
            . implode(', ', $vacuous)
      );
   }
);
