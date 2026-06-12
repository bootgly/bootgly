<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Body;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header;


/**
 * Reflection parity — `Request::assume()` must account for EVERY backed,
 * non-readonly instance property of Request (and of its Header / Body
 * sub-objects). A property added to Request but forgotten in `assume()`
 * would survive between requests on the same keep-alive connection.
 *
 * Method: poison every such property with a sentinel value, run
 * `assume($Template, $Connection)`, then require each property to land on
 * one of the legitimate outcomes — the template's value (copied), the
 * fresh-constructed default (scrubbed), uninitialized (lazily re-derived),
 * or the connection truth (address/port/scheme). A surviving sentinel
 * means `assume()` missed that property.
 *
 * Excluded: readonly $on/$at/$time (instance-creation timestamps —
 * documented staleness, same class as the previous clone path) and
 * virtual hooked properties (no backing store — derive from backed state).
 */

const BOOTGLY_PARITY_UNINIT = "\0__uninitialized__\0";

return new Specification(
   description: 'Request::assume() covers every backed property (reflection parity with clone/reboot)',
   test: function () {
      $rawGet = function (object $object, ReflectionProperty $P): mixed {
         try {
            return $P->getRawValue($object);
         }
         catch (Error) {
            return BOOTGLY_PARITY_UNINIT;
         }
      };

      // @ Connection stub (assume() only reads ip / port / encrypted).
      $ConnectionReflection = new ReflectionClass(Connection::class);
      $Connection = $ConnectionReflection->newInstanceWithoutConstructor();
      $Connection->ip = '198.51.100.7';
      $Connection->port = 4711;
      $Connection->encrypted = true;
      // ! __destruct iterates $timers — initialize it on the stub.
      $ConnectionReflection->getProperty('timers')->setValue($Connection, []);

      // @ Template — a "decoded" Request with distinctive values.
      $Template = new Request;
      $TemplateReflection = new ReflectionClass($Template);
      foreach ([
         'base' => 'template-base',
         'method' => 'POST',
         'URI' => '/parity/path?k=v',
         'protocol' => 'HTTP/1.1',
         'closeConnection' => true,
      ] as $name => $value) {
         $TemplateReflection->getProperty($name)->setRawValue($Template, $value);
      }
      $Template->Header->define("host: parity.example\r\nx-parity: 1");
      $Template->Header->adopt(['host' => 'parity.example', 'x-parity' => '1']);
      $Template->Body->raw = 'parity-body';
      $Template->Body->input = 'parity-input';
      $Template->Body->length = 11;
      $Template->Body->position = 3;
      $Template->Body->downloaded = 11;

      // @ Fresh instances — scrub-default values.
      $Fresh = new Request;

      // @ Sentinel factory: a value guaranteed outside the allowed set, or
      //   null when no such value exists for the type (vacuous — recorded).
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
                  break; // vacuous: both bool values are legitimate outcomes
               default:
                  // Class type: assume() must null it or unset it (both are
                  // in the allowed set) — a surviving foreign instance fails.
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

      // @ Poison-and-verify sweep over one object graph node.
      $sweep = function (
         object $Worker, object $TemplateNode, object $FreshNode,
         array $extraAllowed, array $skip
      ) use ($rawGet, $sentinel): array {
         $failures = [];
         $vacuous = [];
         $poisoned = [];

         $Reflection = new ReflectionClass($Worker);

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
               $rawGet($TemplateNode, $P),
               $rawGet($FreshNode, $P),
               BOOTGLY_PARITY_UNINIT, // lazily re-derived (unset for next access)
               ...($extraAllowed[$name] ?? []),
            ];

            $value = $sentinel($P, $allowed);
            if ($value === null || in_array($value, $allowed, true)) {
               $vacuous[] = $name;
               continue;
            }

            $P->setRawValue($Worker, $value);
            $poisoned[$name] = ['sentinel' => $value, 'allowed' => $allowed];
         }

         return [$poisoned, $vacuous, $failures];
      };

      // @ A property fails parity iff its SENTINEL survives assume() — strict
      //   identity, so lazily re-derived values (e.g. `__get` re-instantiating
      //   an unset Cookies) are legitimate outcomes, while a forgotten
      //   property necessarily still holds the exact sentinel.
      $verify = function (object $Worker, array $poisoned) use ($rawGet): array {
         $failures = [];
         $Reflection = new ReflectionClass($Worker);

         foreach ($poisoned as $name => $expectation) {
            $P = $Reflection->getProperty($name);
            $final = $rawGet($Worker, $P);

            if ($final === $expectation['sentinel']) {
               $failures[] = $name;
            }
         }

         return $failures;
      };

      // @@ Worker — the per-connection reused instance.
      $Worker = new Request;
      $WorkerHeader = $Worker->Header;
      $WorkerBody = $Worker->Body;

      [$poisonedRequest, $vacuousRequest] = $sweep(
         $Worker, $Template, $Fresh,
         extraAllowed: [
            // Connection truth — assume() re-sets these from the Connection.
            'address' => [$Connection->ip],
            'port' => [$Connection->port],
            'scheme' => ['https'],
         ],
         skip: ['Header', 'Body'] // object identity preserved; swept below
      );
      [$poisonedHeader, $vacuousHeader] = $sweep(
         $WorkerHeader, $Template->Header, $Fresh->Header,
         extraAllowed: [],
         skip: []
      );
      [$poisonedBody, $vacuousBody] = $sweep(
         $WorkerBody, $Template->Body, $Fresh->Body,
         extraAllowed: [],
         skip: []
      );

      // @@ Act
      $Worker->assume($Template, $Connection);

      // @@ Assert — no sentinel survives anywhere in the graph.
      $failures = [
         ...array_map(fn ($n) => "Request::\${$n}", $verify($Worker, $poisonedRequest)),
         ...array_map(fn ($n) => "Header::\${$n}", $verify($WorkerHeader, $poisonedHeader)),
         ...array_map(fn ($n) => "Body::\${$n}", $verify($WorkerBody, $poisonedBody)),
      ];

      yield assert(
         assertion: $failures === [],
         description: 'Every poisoned property was re-assumed or scrubbed'
            . ($failures !== [] ? ' — MISSED: ' . implode(', ', $failures) : '')
            . ' (poisoned: ' . count($poisonedRequest) . ' Request, '
            . count($poisonedHeader) . ' Header, '
            . count($poisonedBody) . ' Body)'
      );

      // ? Connection truth applied
      yield assert(
         assertion: $Worker->address === '198.51.100.7'
            && $Worker->port === 4711
            && $Worker->scheme === 'https',
         description: 'address/port/scheme come from the Connection, not the template'
      );

      // ? Sub-object identity preserved (the perf contract: no realloc)
      yield assert(
         assertion: $Worker->Header === $WorkerHeader
            && $Worker->Body === $WorkerBody,
         description: 'assume() reuses the existing Header/Body instances'
      );

      // ? Vacuous-skip budget — booleans whose both values are legitimate
      //   outcomes. Growth here means new untestable properties: review.
      $vacuous = [...$vacuousRequest, ...$vacuousHeader, ...$vacuousBody];
      yield assert(
         assertion: count($vacuous) <= 6,
         description: 'Vacuous (untestable) properties stay bounded: '
            . implode(', ', $vacuous)
      );

      // ? G2 — template integrity: post-assume mutations on the worker must
      //   not write through to the cached template (COW).
      $Worker->Header->append('X-Mutate', '1');
      $Worker->tenant = 'mutated';
      $Worker->Body->raw = 'mutated-body';

      yield assert(
         assertion: $Template->Header->get('X-Mutate') === null
            && $Template->attributes === []
            && $Template->Body->raw === 'parity-body',
         description: 'Worker mutations after assume() never reach the template'
      );
   }
);
