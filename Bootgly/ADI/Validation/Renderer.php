<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Validation;


use function array_is_list;
use function array_key_exists;
use function count;
use function get_class;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function strstr;
use InvalidArgumentException;
use stdClass;


/**
 * Render a rule set as a JSON Schema (draft 2020-12) document, on
 * construction — the shape `Validation` accepts, for MCP tool
 * `inputSchema`s, OpenAPI and other JSON Schema consumers.
 *
 * It is not a second validator: `Validation` stays the authority. Paths
 * become nested `properties` (named segments) and `items` (wildcards); each
 * Condition that implements `Descriptor` contributes its keywords. A
 * Condition that does not — or whose `describe()` returns `null` — is left
 * out and listed in `omissions` under its rule key.
 *
 * A rule key with an index segment (`items.0.id`) is left out whole, every
 * Condition listed in `omissions`: `Validation` reads index `0` of a list
 * and key `"0"` of an object alike, which no single JSON Schema shape
 * expresses without rejecting one of them. Empty schemas are `stdClass`, so
 * `json_encode()` writes `{}`.
 */
class Renderer
{
   public const string DIALECT = 'https://json-schema.org/draft/2020-12/schema';

   /**
    * The instance types each type-specific keyword applies to; a keyword
    * whose types exclude the value's `type` is dropped.
    *
    * @var array<string,array<int,string>>
    */
   private const array KEYWORDS = [
      'minimum' => ['integer', 'number'],
      'maximum' => ['integer', 'number'],
      'exclusiveMinimum' => ['integer', 'number'],
      'exclusiveMaximum' => ['integer', 'number'],
      'multipleOf' => ['integer', 'number'],
      'minLength' => ['string'],
      'maxLength' => ['string'],
      'pattern' => ['string'],
      'minItems' => ['array'],
      'maxItems' => ['array'],
      'items' => ['array'],
      'prefixItems' => ['array'],
      'uniqueItems' => ['array'],
      'contains' => ['array'],
      'minProperties' => ['object'],
      'maxProperties' => ['object'],
      'properties' => ['object'],
      'required' => ['object'],
      'additionalProperties' => ['object'],
      'propertyNames' => ['object'],
   ];

   // * Config
   /**
    * The rules, keyed by path — the same array given to `Validation`.
    *
    * @var array<string,Condition|array<int,Condition>>
    */
   public private(set) array $rules;

   // * Data
   // ...

   // * Metadata
   /**
    * The JSON Schema document (`$schema`, `type: object`, `properties`, …).
    *
    * @var array<string,mixed>
    */
   public private(set) array $document;
   /**
    * The Conditions left out of the document: rule key => class names.
    *
    * @var array<string,array<int,string>>
    */
   public private(set) array $omissions = [];


   /**
    * @param array<string,Condition|array<int,Condition>> $rules The rules, keyed by path.
    *
    * @throws InvalidArgumentException When a rule is not a Condition.
    */
   public function __construct (array $rules)
   {
      // * Config
      $this->rules = $rules;

      // * Metadata
      $Root = self::branch();

      // @@ Every rule key into the tree of paths
      foreach ($rules as $field => $conditions) {
         $field = (string) $field;
         /** @var mixed $raw */
         $raw = $conditions;

         if ($raw instanceof Condition) {
            $raw = [$raw];
         }

         if (is_array($raw) === false) {
            throw new InvalidArgumentException("Validation rules for {$field} must be Condition objects.");
         }

         // ! Index segments: list index or object key, no one shape for both
         $segments = new Path($field)->segments;
         $index = false;
         foreach ($segments as $segment) {
            if ($segment !== null && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $segment) === 1) {
               $index = true;
            }
         }

         $keywords = [];
         $required = false;
         foreach ($raw as $Condition) {
            if ($Condition instanceof Condition === false) {
               throw new InvalidArgumentException("Validation rule for {$field} must be a Condition object.");
            }

            $fragment = $Condition instanceof Descriptor && $index === false ? $Condition->describe() : null;

            // ? `required` is the `true` marker or the keyword's list of names; anything else is not expressible
            if (is_array($fragment) && array_key_exists('required', $fragment) && $fragment['required'] !== true) {
               $names = $fragment['required'];
               $valid = is_array($names) && array_is_list($names);
               foreach (is_array($names) ? $names : [] as $name) {
                  if (is_string($name) === false) {
                     $valid = false;
                  }
               }

               if ($valid === false) {
                  $fragment = null;
               }
            }

            // ? Not expressible: left out, never silently
            if ($fragment === null || self::check($fragment) === false) {
               $name = get_class($Condition);
               $this->omissions[$field][] = strstr($name, "\0", true) ?: $name;

               continue;
            }

            // ! The `required => true` marker lifts to the parent; any other
            //   `required` is the JSON Schema keyword and stays
            if (($fragment['required'] ?? null) === true) {
               $required = true;
               unset($fragment['required']);
            }

            $keywords = self::merge($keywords, $fragment);
         }

         if ($index) {
            continue;
         }

         $Root = $this->insert($Root, $segments, 0, $keywords, $required);
      }

      // @ Render the tree
      $schema = $this->render($Root, true);

      // :
      $this->document = ['$schema' => self::DIALECT] + (is_array($schema) ? $schema : []);
   }

   /**
    * An empty node of the path tree.
    *
    * @return array{keywords:array<string,mixed>,required:bool,children:array<string,mixed>,wildcard:null|array<string,mixed>}
    */
   private static function branch (): array
   {
      return ['keywords' => [], 'required' => false, 'children' => [], 'wildcard' => null];
   }

   /**
    * Whether a fragment is a keyword map (string keys only).
    *
    * @param array<mixed> $fragment
    */
   private static function check (array $fragment): bool
   {
      foreach ($fragment as $keyword => $value) {
         if (is_int($keyword)) {
            return false;
         }
      }

      return true;
   }

   /**
    * Add one rule key's keywords at its path.
    *
    * @param array<string,mixed> $Node
    * @param array<int,null|string> $segments
    * @param array<string,mixed> $keywords
    *
    * @return array<string,mixed>
    */
   private function insert (array $Node, array $segments, int $index, array $keywords, bool $required): array
   {
      // ?: The rule key's own node
      if ($index === count($segments)) {
         /** @var array<string,mixed> $current */
         $current = $Node['keywords'];
         $Node['keywords'] = self::merge($current, $keywords);
         $Node['required'] = $Node['required'] === true || $required;

         return $Node;
      }

      $segment = $segments[$index];

      // @ Wildcard: one schema for every entry
      if ($segment === null) {
         /** @var array<string,mixed> $Child */
         $Child = $Node['wildcard'] ?? self::branch();
         $Node['wildcard'] = $this->insert($Child, $segments, $index + 1, $keywords, $required);

         return $Node;
      }

      // @ Named segment
      /** @var array<string,array<string,mixed>> $children */
      $children = $Node['children'];
      $children[$segment] = $this->insert($children[$segment] ?? self::branch(), $segments, $index + 1, $keywords, $required);
      $Node['children'] = $children;

      // :
      return $Node;
   }

   /**
    * Merge keywords into a schema; a keyword already set to another value
    * goes into `allOf`, so both still apply.
    *
    * @param array<string,mixed> $schema
    * @param array<string,mixed> $keywords
    *
    * @return array<string,mixed>
    */
   private static function merge (array $schema, array $keywords): array
   {
      $conflicts = [];

      foreach ($keywords as $keyword => $value) {
         if (array_key_exists($keyword, $schema) === false) {
            $schema[$keyword] = $value;
         }
         else if ($schema[$keyword] !== $value) {
            $conflicts[$keyword] = $value;
         }
      }

      if ($conflicts !== []) {
         /** @var array<int,mixed> $all */
         $all = is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [];
         $all[] = $conflicts;
         $schema['allOf'] = $all;
      }

      return $schema;
   }

   /**
    * Whether a node, or a named descendant reached without a wildcard, is
    * required — such a node must be present in its parent.
    *
    * @param array<string,mixed> $Node
    */
   private static function require (array $Node): bool
   {
      if ($Node['required'] === true) {
         return true;
      }

      /** @var array<string,array<string,mixed>> $children */
      $children = $Node['children'];
      foreach ($children as $Child) {
         if (self::require($Child)) {
            return true;
         }
      }

      return false;
   }

   /**
    * Drop the type-specific keywords that cannot apply to `$type`.
    *
    * @param array<string,mixed> $schema
    *
    * @return array<string,mixed>
    */
   private static function prune (array $schema, string $type): array
   {
      foreach (self::KEYWORDS as $keyword => $types) {
         if (array_key_exists($keyword, $schema) === false) {
            continue;
         }

         $applies = false;
         foreach ($types as $kind) {
            if ($kind === $type || ($kind === 'number' && $type === 'integer')) {
               $applies = true;
            }
         }

         if ($applies === false) {
            unset($schema[$keyword]);
         }
      }

      return $schema;
   }

   /**
    * Render one node of the path tree as a schema.
    *
    * @param array<string,mixed> $Node
    *
    * @return array<string,mixed>|stdClass
    */
   private function render (array $Node, bool $root): array|stdClass
   {
      /** @var array<string,array<string,mixed>> $children */
      $children = $Node['children'];
      /** @var null|array<string,mixed> $Wildcard */
      $Wildcard = $Node['wildcard'];
      /** @var array<string,mixed> $keywords */
      $keywords = $Node['keywords'];

      // ! Structure: named segments are object properties, a wildcard is
      //   array items (object values when named segments sit beside it, or
      //   when the node's own rules make it an object — `Keys`)
      $schema = [];

      if ($root || $children !== [] || ($keywords['type'] ?? null) === 'object') {
         $schema['type'] = 'object';
      }
      else if ($Wildcard !== null) {
         $schema['type'] = 'array';
         $schema['items'] = $this->render($Wildcard, false);
      }

      if ($children !== [] || ($Wildcard !== null && ($schema['type'] ?? null) === 'object')) {
         $entries = $Wildcard !== null ? $this->render($Wildcard, false) : null;

         $properties = [];
         $required = [];
         foreach ($children as $name => $Child) {
            $property = $this->render($Child, false);
            $properties[(string) $name] = $entries === null ? $property : ['allOf' => [$property, $entries]];

            if (self::require($Child)) {
               $required[] = (string) $name;
            }
         }

         if ($properties !== []) {
            $schema['properties'] = $properties;
         }
         if ($required !== []) {
            $schema['required'] = $required;
         }
         if ($entries !== null) {
            $schema['additionalProperties'] = $entries;
         }
      }

      // @ The rule key's own keywords
      $schema = self::merge($schema, $keywords);

      // @ Required: present (in the parent) and non-blank
      $type = is_string($schema['type'] ?? null) ? $schema['type'] : null;
      if ($Node['required'] === true) {
         $blank = match ($type) {
            'string' => ['minLength' => 1],
            'array' => ['minItems' => 1],
            'object' => ['minProperties' => 1],
            null => ['minLength' => 1, 'minItems' => 1, 'minProperties' => 1],
            default => [],
         };

         foreach ($blank as $keyword => $bound) {
            $current = $schema[$keyword] ?? null;
            if ((is_int($current) && $current >= $bound) === false) {
               $schema[$keyword] = $bound;
            }
         }

         if ($type === null) {
            $schema = self::merge($schema, ['not' => ['type' => 'null']]);
         }
      }

      // @ Keywords of other instance types never apply
      if ($type !== null) {
         $schema = self::prune($schema, $type);

         if (is_array($schema['allOf'] ?? null)) {
            $all = [];
            foreach ($schema['allOf'] as $entry) {
               /** @var mixed $entry */
               if (is_array($entry)) {
                  /** @var array<string,mixed> $keywords */
                  $keywords = $entry;
                  $entry = self::prune($keywords, $type);
               }
               if ($entry !== []) {
                  $all[] = $entry;
               }
            }

            if ($all === []) {
               unset($schema['allOf']);
            }
            else {
               $schema['allOf'] = $all;
            }
         }
      }

      // :
      return $schema === [] ? new stdClass : $schema;
   }
}
