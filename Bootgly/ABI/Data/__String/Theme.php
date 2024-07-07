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
            'callback' => $prepending['value'](...$input),
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
            'callback' => $appending['value'](...$input),
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
    * @throws \Exception
    */
   public function add (array $theme): self
   {
      if (\count($theme) > 1) {
         throw new \Exception('Invalid theme structure.');
      }

      foreach ($theme as $name => $specifications) {
         if (\is_string($name) === false) {
            throw new \Exception('Invalid theme structure.');
         }

         // @ Validate
         $specifications['options']
            ?? throw new \Exception('Invalid theme structure: options not defined');
         $specifications['values']
            ?? throw new \Exception('Invalid theme structure: values not defined');
         // options
         $options = [
            'prepending',
            'appending'
         ];
         foreach ($options as $option) {
            $specifications['options'][$option]
               ?? throw new \Exception("Invalid theme structure: $option options not defined");
            $specifications['options'][$option]['type']
               ?? throw new \Exception("Invalid theme structure: $option options type not defined");
            $specifications['options'][$option]['value']
               ?? throw new \Exception("Invalid theme structure: $option options value not defined");

            $specifications['options'][$option]['type'] === 'callback' && (
               is_callable($specifications['options'][$option]['value']) === true ?
               : throw new \Exception("Invalid theme structure: $option options value should be callable!")
            );
            $specifications['options'][$option]['type'] === 'string' && (
               is_string($specifications['options'][$option]['value']) === true ?
               : throw new \Exception("Invalid theme structure: $option options value should be string!")
            );
         }

         // * Data
         self::$themes[$name] = $specifications;
         $this->active ??= $name;
      }

      return $this;
   }
   public function select (? string $name = null): bool
   {
      $name ??= $this->active;

      if (isSet(self::$themes[$name])) {
         // * Data
         $this->active = $name;
         // * Metadata
         $this->theme = self::$themes[$name];
         $this->options = $this->theme['options'];
         $this->values = $this->theme['values'];
         return true;
      }

      return false;
   }
}
