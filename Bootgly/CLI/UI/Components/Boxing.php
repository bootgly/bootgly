<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components;


use Bootgly\API\Component;


/**
 * Boxing — a rectangular screen region contract: the geometry a layout
 * manager assigns, invalidation for full repaints and in-place rendering
 * (implemented by Frame, Tabs, ...).
 */
interface Boxing
{
   // * Config
   // # Geometry (outer rectangle, 1-based screen coordinates)
   public int $row { get; set; }
   public int $column { get; set; }
   public int $width { get; set; }
   public int $height { get; set; }


   /**
    * Invalidates the blitted state — the next render repaints the full
    * rectangle.
    *
    * @return void
    */
   public function invalidate (): void;

   /**
    * Renders the rectangle in place.
    *
    * @param int $mode Component::WRITE_OUTPUT to write, Component::RETURN_OUTPUT to return the output.
    *
    * @return null|string
    */
   public function render (int $mode = Component::WRITE_OUTPUT): null|string;
}
