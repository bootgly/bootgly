<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\__String;


use function array_keys;
use function getenv;
use function is_array;
use function is_callable;
use function is_string;

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Data\__String\Theme\ThemeException;


class Theme
{
   use Formattable;


   // # Builtin theme identifiers
   public const string DARK  = 'dark';
   public const string LIGHT = 'light';
   public const string MONO  = 'mono';
   // # Builtin semantic values (SGR foreground codes fed to wrap())
   /** @var array<string,string> */
   private const array DARK_VALUES = [
      'success' => self::_GREEN_BRIGHT_FOREGROUND,
      'debug'   => self::_GREEN_BRIGHT_FOREGROUND,
      'info'    => self::_CYAN_BRIGHT_FOREGROUND,
      'notice'  => self::_YELLOW_BRIGHT_FOREGROUND,
      'warning' => self::_MAGENTA_BRIGHT_FOREGROUND,
      'error'   => self::_RED_BRIGHT_FOREGROUND
   ];
   /** @var array<string,string> */
   private const array LIGHT_VALUES = [
      'success' => self::_GREEN_FOREGROUND,
      'debug'   => self::_GREEN_FOREGROUND,
      'info'    => self::_CYAN_FOREGROUND,
      'notice'  => self::_YELLOW_FOREGROUND,
      'warning' => self::_MAGENTA_FOREGROUND,
      'error'   => self::_RED_FOREGROUND
   ];

   // * Config
   // ...

   // * Data
   /** @var array<string,array<string,array<string,mixed>>> */
   protected static array $themes = [];
   public private(set) null|string $active;
   // The active UI theme — read by the CLI markup renderer (TemplateEscaped).
   // Swap it wholesale (`Theme::$Current = new Theme('light')`) or in place
   // (`Theme::$Current->select('light')`).
   public static self $Current;

   // * Metadata
   /** @var array<string,array<string,mixed>> */
   private array $theme;
   /** @var array<string,array<string,mixed>> */
   private array $options;
   /** @var array<string,array<string,mixed>> */
   private array $values;


   public function __construct (null|string $name = null)
   {
      // * Config
      // ...

      // * Data
      $this->active = $name;

      // * Metadata
      $this->theme = [];
      $this->options = [];
      $this->values = [];

      // @
      if ($name) {
         $this->select($name);
      }
   }

   /**
    * Register the builtin themes (dark/light/mono) and select the active UI theme.
    *
    * Honors the `NO_COLOR` convention: when the env var is present, the colorless
    * `mono` theme becomes active by default.
    */
   public static function boot (): void
   {
      // ! Shared colorized options (open = wrap SGR codes, close = reset)
      $colorized = [
         'prepending' => ['type' => 'callback', 'value' => self::wrap(...)],
         'appending'  => ['type' => 'string',   'value' => self::_RESET_FORMAT]
      ];

      // @ Register builtins
      self::$themes[self::DARK] = [
         'options' => $colorized,
         'values'  => self::DARK_VALUES
      ];
      self::$themes[self::LIGHT] = [
         'options' => $colorized,
         'values'  => self::LIGHT_VALUES
      ];
      self::$themes[self::MONO] = [
         'options' => [
            'prepending' => ['type' => 'string', 'value' => ''],
            'appending'  => ['type' => 'string', 'value' => '']
         ],
         'values'  => []
      ];

      // @ Select the active UI theme
      $default = getenv('NO_COLOR') !== false ? self::MONO : self::DARK;
      self::$Current = new self($default);
   }

   /**
    * Return the opening decoration for a semantic key (no content, no reset).
    */
   public function open (string $key): string
   {
      $prepending = $this->options['prepending'] ?? null;
      if (! $prepending) {
         return '';
      }

      $input = (array) ($this->values[$key] ?? []);

      // :
      return match ($prepending['type']) {
         'callback' => is_callable($prepending['value']) ? $prepending['value'](...$input) : '',
         'string'   => $prepending['value'],
         default    => ''
      };
   }
   /**
    * Return the closing decoration for a semantic key (the reset, by default).
    */
   public function close (string $key = ''): string
   {
      $appending = $this->options['appending'] ?? null;
      if (! $appending) {
         return '';
      }

      $input = (array) ($this->values[$key] ?? []);

      // :
      return match ($appending['type']) {
         'callback' => is_callable($appending['value']) ? $appending['value'](...$input) : '',
         'string'   => $appending['value'],
         default    => ''
      };
   }
   /**
    * Decorate content with the opening + closing of a semantic key.
    */
   public function apply (string $key, string $content = ''): string
   {
      return $this->open($key) . $content . $this->close($key);
   }

   /**
    * Register one or more themes.
    *
    * @param array<mixed> $themes One or more `name => specifications` entries.
    *
    * @throws ThemeException
    */
   public function add (array $themes): self
   {
      foreach ($themes as $name => $specifications) {
         // ? Validate theme structure
         if (
            is_string($name) === false
            || is_array($specifications) === false
         ) {
            throw new ThemeException('Invalid theme structure.');
         }
         // ? Validate theme options/values structure
         // options
         if (is_array($specifications['options'] ?? null) === false) {
            throw new ThemeException('Invalid theme structure: options not defined');
         }
         // values
         $specifications['values']
            ?? throw new ThemeException('Invalid theme structure: values not defined');

         // !
         /** @var array<string,array<string,mixed>> */
         $options = $specifications['options'];

         foreach (['prepending', 'appending'] as $option) {
            $options[$option]
               ?? throw new ThemeException("Invalid theme structure: $option options not defined");
            $options[$option]['type']
               ?? throw new ThemeException("Invalid theme structure: $option options type not defined");
            $options[$option]['value']
               ?? throw new ThemeException("Invalid theme structure: $option options value not defined");

            $options[$option]['type'] === 'callback' && (
               is_callable($options[$option]['value']) === true ?
               : throw new ThemeException("Invalid theme structure: $option options value should be callable!")
            );
            $options[$option]['type'] === 'string' && (
               is_string($options[$option]['value']) === true ?
               : throw new ThemeException("Invalid theme structure: $option options value should be string!")
            );
         }

         // * Data
         self::$themes[$name] = $specifications;
         $this->active ??= $name;
      }

      return $this;
   }
   /**
    * Activate a registered theme by name (defaults to this instance's active name).
    */
   public function select (null|string $name = null): bool
   {
      $name ??= $this->active;

      if (isSet(self::$themes[$name])) {
         // * Data
         $this->active = $name;
         // * Metadata
         $this->theme = self::$themes[$name];

         /** @var array<string,array<string,mixed>> */
         $options = $this->theme['options'];
         /** @var array<string,array<string,mixed>> */
         $values = $this->theme['values'];

         $this->options = $options;
         $this->values = $values;

         return true;
      }

      return false;
   }
   /**
    * Check whether a theme is registered.
    */
   public static function check (string $name): bool
   {
      return isSet(self::$themes[$name]);
   }
   /**
    * List the names of every registered theme.
    *
    * @return array<int,string>
    */
   public static function list (): array
   {
      return array_keys(self::$themes);
   }
}

Theme::boot();
