<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server\Response\Raw;


/**
 * @property array $preset
 * @property array $prepared
 * 
 * @property array $fields
 * @property string $raw
 * 
 * @property bool $sent
 * @property array $queued
 * @property int $built
 */
abstract class Header
{
   // * Config
   // Fields
   protected array $preset;
   protected array $prepared;

   // * Data
   protected array $fields;
   protected string $raw;

   // * Metadata
   protected bool $sent;
   // Fields
   protected array $queued;
   protected int $built;


   abstract public function clean ();

   abstract public function prepare (array $fields);

   abstract public function get (string $name): string;

   abstract public function set (string $field, string $value): bool;
   abstract public function append (string $field, string $value = '', ?string $separator = ', ');
   abstract public function queue (string $field, string $value = '');

   abstract public function build (): bool;
}
