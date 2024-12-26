<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\__String;


use function count;
use function is_callable;
use function is_string;
use function is_array;
use Exception;


class Theme
{
   // * Config
   // ...

   // * Data
   /** @var array<string,array<string,array<string,mixed>>> */
   protected static array $themes = [];
   protected ? string $active;

   // * Metadata
   /** @var array<string,array<string,mixed>> */
   private array $theme;
   /** @var array<string,array<string,mixed>> */
   private array $options;
   /** @var array<string,array<string,mixed>> */
   private array $values;


   public function __construct (? string $name = null)
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

   public function apply (string $key, string $content = ''): string
   {
      $options = $this->options;
      $values = $this->values;

      $input = (array) ($values[$key] ?? []);

      $output = '';
      // @ prepending
      $prepending = $options['prepending'];
      if ($prepending) {
         $output .= match ($prepending['type']) {
            'callback' => match (true) {
               is_callable($prepending['value']) => $prepending['value'](...$input),
               default => ''
            },
            'string' => $prepending['value'],
            default => ''
         };
      }
      // @ content
      $output .= $content;
      // @ appending
      $appending = $options['appending'];
      if ($appending) {
         $output .= match ($appending['type']) {
            'callback' => match (true) {
               is_callable($prepending['value']) => $prepending['value'](...$input),
               default => ''
            },
            'string' => $appending['value'],
            default => ''
         };
      }

      return $output;
   }
   /**
    * Add a new theme.
    * 
    * @param array<mixed> $theme
    *
    * @throws Exception
    */
   public function add (array $theme): self
   {
      if (\count($theme) > 1) {
         throw new Exception('Invalid theme structure.');
      }

      foreach ($theme as $name => $specifications) {
         // ? Validate theme structure
         if (
            is_string($name) === false
            || is_array($specifications) === false
         ) {
            throw new Exception('Invalid theme structure.');
         }
         // ? Validate theme options/values structure
         // options
         if (is_array($specifications['options']) === false) {
            throw new Exception('Invalid theme structure: options not defined');
         }
         // values
         $specifications['values']
            ?? throw new Exception('Invalid theme structure: values not defined');

         // !
         /** @var array<string,array<string,mixed>> */
         $options = $specifications['options'];

         foreach (['prepending', 'appending'] as $option) {
            $options[$option]
               ?? throw new Exception("Invalid theme structure: $option options not defined");
            $options[$option]['type']
               ?? throw new Exception("Invalid theme structure: $option options type not defined");
            $options[$option]['value']
               ?? throw new Exception("Invalid theme structure: $option options value not defined");

            $options[$option]['type'] === 'callback' && (
               is_callable($options[$option]['value']) === true ?
               : throw new Exception("Invalid theme structure: $option options value should be callable!")
            );
            $options[$option]['type'] === 'string' && (
               is_string($options[$option]['value']) === true ?
               : throw new Exception("Invalid theme structure: $option options value should be string!")
            );
         }

         // * Data
         self::$themes[$name] = $specifications;
         $this->active ??= $name;
      }

      return $this;
   }
   public function select (?string $name = null): bool
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
}
