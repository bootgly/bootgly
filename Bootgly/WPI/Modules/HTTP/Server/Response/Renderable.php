<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server\Response;


use function ob_start;

use Closure;
use Throwable;

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Templates\Template;

use const Bootgly\WPI;


trait Renderable
{
   // * Metadata
   /** @var array<string,mixed> */
   protected array $uses = [];

   /**
    * Renders the specified view with the provided data.
    *
    * @param string $view The view to render.
    * @param array<string,mixed>|null $data The data to provide to the view.
    * @param Closure|null $callback Optional callback.
    *
    * @return self The Response instance, for chaining
    */
   public function render (string $view, ?array $data = null, ?Closure $callback = null): self
   {
      // !
      $this->prepare('view');
      $this->process($view . '.template.php', 'view');

      // ?
      $File = $this->File ?? null;
      if ($File === null || !$File instanceof File || $File->exists === false) {
         // throw new \Exception(message: 'Template file not found!');
         return $this;
      }

      // @ Set variables
      if ($data === null) {
         $data = [];
      }
      $data['Route'] = WPI->Router->Route;

      // @ Extend variables
      $data = $data + $this->uses;

      // @ Output/Buffer start()
      ob_start();
      // @ Render Template
      $Template = new Template($File);
      try {
         $rendered = $Template->render($data);
      }
      catch (Throwable $Throwable) {
         $rendered = '';
         Throwables::report($Throwable);
      }
      // @ Output/Buffer clean()->get()
      $this->content = $rendered;

      // @ Set $Response properties
      $this->source = 'content';
      $this->type = '';

      // @ Call callback
      if ($callback !== null && $callback instanceof Closure) {
         $callback($this->content, $Throwable ?? null);
      }

      return $this;
   }
}
