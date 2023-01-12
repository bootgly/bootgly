<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server\_;


use Bootgly\Web\HTTP\Server\_\Content\Downloader;


class Content
{
   public string $input;
   public string $raw;

   public Downloader $Downloader;


   public function __construct ()
   {
      $this->input = '';
      $this->raw = '';

      if (\PHP_SAPI !== 'cli') {
         $this->input = file_get_contents('php://input');
      }

      $this->Downloader = new Downloader($this);
   }
}
