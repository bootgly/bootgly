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
   protected static array $themes = [];
   protected ? string $active;

   // * Meta
   private array $theme;
   private array $options;
   private array $values;


   public function __construct (? string $name = null)
   {
      // * Config
      // ...

      // * Data
      $this->active = $name;

      // * Meta
      $this->theme = [];
      $this->options = [];
      $this->values = [];

      // @
      if ($name) {
         $this->select($name);
      }
   }

   public function apply (string $key, string $content = '') : string
   {
      $options = $this->options;
      $values = $this->values;

      $input = $values[$key];

      $output = '';
      // @ prepending
      $prepending = $options['prepending'];
      if ($prepending) {
         $output .= match ($prepending['type']) {
            'callback' => $prepending['value'](... (array) $input),
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
            'callback' => $appending['value'](... (array) $input),
            'string' => $appending['value'],
            default => ''
         };
      }

      return $output;
   }

   public function add (array $theme)
   {
      if (\count($theme) > 1) {
         throw new \Exception('Invalid theme structure.');
      }

      foreach ($theme as $name => $specifications) {
         if (\is_string($name) === false) {
            throw new \Exception('Invalid theme structure.');
         }

         $specifications['options'] ?? throw new \Exception('Invalid theme structure.');
         $specifications['values'] ?? throw new \Exception('Invalid theme structure.');

         // * Data
         self::$themes[$name] = $specifications;
         $this->active ??= $name;
      }

      return $this;
   }
   public function select (? string $name = null) : bool
   {
      $name ??= $this->active;

      if (isSet(self::$themes[$name])) {
         // * Data
         $this->active = $name;
         // * Meta
         $this->theme = self::$themes[$name];
         $this->options = $this->theme['options'];
         $this->values = $this->theme['values'];
         return true;
      }

      return false;
   }
}
