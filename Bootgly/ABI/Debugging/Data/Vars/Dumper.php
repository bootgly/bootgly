<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging\Data\Vars;


use function addcslashes;
use function count;
use function get_resource_type;
use function gettype;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function method_exists;
use function spl_object_id;
use function str_repeat;
use function var_export;
use BackedEnum;
use Closure;
use ReflectionFunction;
use ReflectionObject;
use ReflectionProperty;
use UnitEnum;
use ValueError;

use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Code\__String\Theme;


class Dumper
{
   use Formattable;


   public Theme $Theme;
   // # Theme groups
   public const string TYPE_NULL   = 'null';
   public const string TYPE_BOOL   = 'bool';
   public const string TYPE_INT    = 'int';
   public const string TYPE_FLOAT  = 'float';
   public const string TYPE_STRING = 'string';
   public const string CLASSNAME   = 'classname';
   public const string PROPERTY    = 'property';
   public const string MODIFIER    = 'modifier';
   public const string PONTUATION  = 'pontuation';
   public const string NOTE        = 'note';

   public const array DEFAULT_THEME = [
      'CLI' => [
         'values' => [
            self::TYPE_NULL   => self::_MAGENTA_BRIGHT_FOREGROUND,
            self::TYPE_BOOL   => self::_MAGENTA_BRIGHT_FOREGROUND,
            self::TYPE_INT    => self::_ORANGE_FOREGROUND,
            self::TYPE_FLOAT  => self::_ORANGE_FOREGROUND,
            self::TYPE_STRING => self::_GREEN_BRIGHT_FOREGROUND,
            self::CLASSNAME   => self::_PINK_FOREGROUND,
            self::PROPERTY    => self::_CYAN_BRIGHT_FOREGROUND,
            self::MODIFIER    => self::_BLACK_SOFT_FOREGROUND,
            self::PONTUATION  => self::_WHITE_FOREGROUND,
            self::NOTE        => self::_BLACK_SOFT_FOREGROUND
         ]
      ]
   ];

   // * Config
   private const int INDENTATION = 3;
   /**
    * Named dump themes — theme group => SGR code(s).
    * Register a new palette here, then construct with its name.
    *
    * @var array<string,array<string,string|array<int,string>>>
    */
   public static array $Themes = [
      'bootgly' => self::DEFAULT_THEME['CLI']['values'],
      'plain'   => []
   ];
   /** Max nesting level — deeper containers render as `…` */
   public int $depth;
   /** Max string chars — longer strings truncate with a `(+N)` note */
   public int $strings;
   /** Max entries per container — extra entries collapse into `… +N more` */
   public int $items;

   // * Data
   // ...
   // * Metadata
   /** @var array<int,true> Ancestor object path (spl_object_id) — circular reference guard */
   private array $visited;


   /** @param array<mixed>|string $theme A named dump theme or a full Theme specification */
   public function __construct (array|string $theme = 'bootgly')
   {
      // !
      if (is_string($theme) === true) {
         // ? Named themes resolve from the registry — empty values render colorless
         $values = self::$Themes[$theme]
            ?? throw new ValueError("Unknown dump theme: `{$theme}`");

         $theme = [
            "dumper.{$theme}" => [
               'options' => ($values === []
                  ? [
                     'prepending' => ['type' => 'string', 'value' => ''],
                     'appending'  => ['type' => 'string', 'value' => '']
                  ]
                  : [
                     'prepending' => ['type' => 'callback', 'value' => self::wrap(...)],
                     'appending'  => ['type' => 'string', 'value' => self::_RESET_FORMAT]
                  ]
               ),
               'values' => $values
            ]
         ];
      }
      else if ($theme === self::DEFAULT_THEME) {
         $theme['CLI']['options'] = [
            'prepending' => [
               'type'  => 'callback',
               'value' => self::wrap(...)
            ],
            'appending' => [
               'type' => 'string',
               'value' => self::_RESET_FORMAT
            ]
         ];
      }
      // ---
      $Theme = new Theme;
      $Theme->add($theme);
      $Theme->select();

      $this->Theme = $Theme;

      // * Config
      $this->depth = 8;
      $this->strings = 150;
      $this->items = 100;
      // * Data
      // ...
      // * Metadata
      $this->visited = [];
   }

   /**
    * Dump any PHP value as a colorized, structured string.
    *
    * @param mixed $value The value to dump.
    *
    * @return string The rendered dump — no trailing newline.
    */
   public function dump (mixed $value): string
   {
      // !
      $this->visited = [];

      // :
      return $this->walk($value, 0);
   }

   /**
    * Walk a value recursively, dispatching by type.
    */
   private function walk (mixed $value, int $level): string
   {
      // ?: Scalars
      if ($value === null) {
         return $this->Theme->apply(self::TYPE_NULL, 'null');
      }
      if (is_bool($value) === true) {
         return $this->Theme->apply(self::TYPE_BOOL, $value === true ? 'true' : 'false');
      }
      if (is_int($value) === true) {
         return $this->Theme->apply(self::TYPE_INT, (string) $value);
      }
      if (is_float($value) === true) {
         // var_export uses serialize_precision: whole floats keep `.0`, INF/NAN stay bare
         return $this->Theme->apply(self::TYPE_FLOAT, var_export($value, true));
      }
      if (is_string($value) === true) {
         return $this->quote($value);
      }
      // ?: Arrays
      if (is_array($value) === true) {
         return $this->traverse($value, $level);
      }
      // ?: Enums — before the generic object walk (enums are objects)
      if ($value instanceof UnitEnum) {
         $enum = $this->Theme->apply(self::CLASSNAME, $value::class)
            . $this->Theme->apply(self::PONTUATION, '::')
            . $this->Theme->apply(self::PROPERTY, $value->name);
         if ($value instanceof BackedEnum) {
            $enum .= ' ' . $this->Theme->apply(self::PONTUATION, '=')
               . ' ' . $this->walk($value->value, $level);
         }
         return $enum;
      }
      // ?: Closures — never expanded, only located
      if ($value instanceof Closure) {
         $Reflection = new ReflectionFunction($value);
         $file = $Reflection->getFileName();
         $location = $file === false
            ? 'internal'
            : "{$file}:{$Reflection->getStartLine()}";

         return $this->Theme->apply(self::CLASSNAME, 'Closure')
            . ' ' . $this->Theme->apply(self::NOTE, "({$location})");
      }
      // ?: Objects
      if (is_object($value) === true) {
         // ? Circular reference — ancestor path only
         $id = spl_object_id($value);
         if (isSet($this->visited[$id]) === true) {
            return $this->Theme->apply(self::CLASSNAME, $value::class)
               . ' ' . $this->Theme->apply(self::NOTE, '*RECURSION*');
         }

         // @
         $this->visited[$id] = true;
         $output = $this->expand($value, $level);
         unset($this->visited[$id]);

         return $output;
      }
      // ?: Resources
      if (is_resource($value) === true) {
         $type = get_resource_type($value);
         return $this->Theme->apply(self::NOTE, "resource ({$type})");
      }

      // : Closed resources and unknown types degrade to their type name
      return $this->Theme->apply(self::NOTE, gettype($value));
   }

   /**
    * Quote a string — truncate the raw string first, then escape control chars.
    */
   private function quote (string $string): string
   {
      // ? Truncation — raw chars first, so the `(+N)` note counts real chars
      $length = mb_strlen($string);
      $truncated = $length > $this->strings;
      if ($truncated === true) {
         $string = mb_substr($string, 0, $this->strings);
      }

      // @ Escape control chars — C mnemonics + octal (SGR-injection-proof)
      $string = addcslashes($string, "\0..\37\177\\'");

      // :
      if ($truncated === true) {
         $remaining = $length - $this->strings;
         return $this->Theme->apply(self::TYPE_STRING, "'{$string}…'")
            . ' ' . $this->Theme->apply(self::NOTE, "(+{$remaining})");
      }
      return $this->Theme->apply(self::TYPE_STRING, "'{$string}'");
   }

   /**
    * Traverse an array — `array:N [` header, one entry per line.
    *
    * @param array<mixed> $array
    */
   private function traverse (array $array, int $level): string
   {
      // ? Empty
      if ($array === []) {
         return $this->Theme->apply(self::PONTUATION, '[]');
      }
      // ? Depth cap
      if ($level >= $this->depth) {
         return $this->Theme->apply(self::PONTUATION, '[')
            . ' ' . $this->Theme->apply(self::NOTE, '…')
            . ' ' . $this->Theme->apply(self::PONTUATION, ']');
      }

      // !
      $count = count($array);
      $closing = str_repeat(' ', $level * self::INDENTATION);

      // :
      return $this->Theme->apply(self::NOTE, "array:{$count}")
         . ' ' . $this->Theme->apply(self::PONTUATION, '[')
         . $this->enumerate($array, $level)
         . "\n{$closing}" . $this->Theme->apply(self::PONTUATION, ']');
   }

   /**
    * Enumerate container entries — shared by arrays and `__debugInfo` bodies.
    *
    * @param array<mixed> $array
    */
   private function enumerate (array $array, int $level): string
   {
      // !
      $count = count($array);
      $indentation = str_repeat(' ', ($level + 1) * self::INDENTATION);

      // @@ Entries
      $entries = '';
      $rendered = 0;
      foreach ($array as $key => $value) {
         // ? Items cap
         if ($rendered >= $this->items) {
            $remaining = $count - $rendered;
            $entries .= "\n{$indentation}"
               . $this->Theme->apply(self::NOTE, "… +{$remaining} more");
            break;
         }

         // key
         $keyed = is_string($key) === true
            ? $this->quote($key)
            : $this->Theme->apply(self::TYPE_INT, (string) $key);
         // value
         $entries .= "\n{$indentation}{$keyed} "
            . $this->Theme->apply(self::PONTUATION, '=>')
            . ' ' . $this->walk($value, $level + 1);

         $rendered++;
      }

      // :
      return $entries;
   }

   /**
    * Expand an object — class name header + property walk with visibility sigils.
    */
   private function expand (object $Object, int $level): string
   {
      // !
      $classname = $this->Theme->apply(self::CLASSNAME, $Object::class);

      // ? Depth cap
      if ($level >= $this->depth) {
         return "{$classname} " . $this->Theme->apply(self::PONTUATION, '{')
            . ' ' . $this->Theme->apply(self::NOTE, '…')
            . ' ' . $this->Theme->apply(self::PONTUATION, '}');
      }

      $closing = str_repeat(' ', $level * self::INDENTATION);

      // ? `__debugInfo` overrides the property walk — author-defined body
      if (method_exists($Object, '__debugInfo') === true) {
         /** @var array<mixed> $info */
         $info = $Object->__debugInfo();
         if ($info === []) {
            return "{$classname} " . $this->Theme->apply(self::PONTUATION, '{}');
         }

         return "{$classname} " . $this->Theme->apply(self::PONTUATION, '{')
            . $this->enumerate($info, $level)
            . "\n{$closing}" . $this->Theme->apply(self::PONTUATION, '}');
      }

      // ! Properties — own pass, then ancestor privates (ReflectionObject misses them)
      $Reflection = new ReflectionObject($Object);
      /** @var array<int,array{0:ReflectionProperty,1:null|string}> $Properties */
      $Properties = [];
      foreach ($Reflection->getProperties() as $Property) {
         if ($Property->isStatic() === true) {
            continue;
         }
         $Properties[] = [$Property, null];
      }
      $Ancestor = $Reflection->getParentClass();
      while ($Ancestor !== false) {
         foreach ($Ancestor->getProperties(ReflectionProperty::IS_PRIVATE) as $Property) {
            if ($Property->isStatic() === true) {
               continue;
            }
            $Properties[] = [$Property, $Ancestor->getShortName()];
         }
         $Ancestor = $Ancestor->getParentClass();
      }

      // ? Empty body
      if ($Properties === []) {
         return "{$classname} " . $this->Theme->apply(self::PONTUATION, '{}');
      }

      // @@ Properties
      $count = count($Properties);
      $indentation = str_repeat(' ', ($level + 1) * self::INDENTATION);
      $entries = '';
      $rendered = 0;
      foreach ($Properties as [$Property, $origin]) {
         // ? Items cap
         if ($rendered >= $this->items) {
            $remaining = $count - $rendered;
            $entries .= "\n{$indentation}"
               . $this->Theme->apply(self::NOTE, "… +{$remaining} more");
            break;
         }

         // # Modifiers
         $sigil = match (true) {
            $Property->isPublic() === true    => '+',
            $Property->isProtected() === true => '#',
            default                           => '-'
         };
         $modifiers = $Property->isReadOnly() === true ? 'readonly ' : '';
         // # Value — guard order is load-bearing: virtual props report initialized
         $rendering = match (true) {
            $Property->isVirtual() === true             => $this->Theme->apply(self::NOTE, 'virtual'),
            $Property->isInitialized($Object) === false => $this->Theme->apply(self::NOTE, 'uninitialized'),
            default => $this->walk($Property->getRawValue($Object), $level + 1)
         };

         $entries .= "\n{$indentation}"
            . $this->Theme->apply(self::MODIFIER, "{$modifiers}{$sigil}")
            . $this->Theme->apply(self::PROPERTY, $Property->getName())
            . $this->Theme->apply(self::PONTUATION, ':')
            . " {$rendering}";
         // shadowed ancestor privates carry their declaring class
         if ($origin !== null) {
            $entries .= ' ' . $this->Theme->apply(self::NOTE, "({$origin})");
         }

         $rendered++;
      }

      // :
      return "{$classname} " . $this->Theme->apply(self::PONTUATION, '{')
         . $entries
         . "\n{$closing}" . $this->Theme->apply(self::PONTUATION, '}');
   }
}
