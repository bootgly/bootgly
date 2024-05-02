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


use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Templates\Template;


trait Renderable
{
   // \
   private static $Server;

   // * Metadata
   protected array $uses = [];

   /**
    * Renders the specified view with the provided data.
    *
    * @param string $view The view to render.
    * @param array|null $data The data to provide to the view.
    * @param Closure|null $callback Optional callback.
    *
    * @return Response The Response instance, for chaining
    */
   public function render (string $view, ? array $data = null, ? \Closure $callback = null) : self
   {
      // !
      $this->prepare('view');
      $this->process($view . '.template.php', 'view');

      // ?
      $File = $this->body ?? null; // TODO change to $this->Body->File?
      if ($File === null || !$File instanceof File || $File->exists === false) {
         throw new \Exception(message: 'Template file not found!');
         return $this;
      }

      // @ Set variables
      /**
       * @var \Bootgly\WPI $WPI
       */
      $Route = &self::$Server::$Router->Route;

      if ($data === null) {
         $data = [];
      }

      $data['Route'] = $Route;

      // @ Extend variables
      $data = $data + $this->uses;

      // @ Output/Buffer start()
      \ob_start();
      // @ Render Template
      $Template = new Template($File);
      try {
         $rendered = $Template->render($data);
      }
      catch (\Throwable $Throwable) {
         $rendered = '';
         Throwables::report($Throwable);
      }
      // @ Output/Buffer clean()->get()
      $this->body = $rendered;

      // @ Set $Response properties
      $this->source = 'content';
      $this->type = '';

      // @ Call callback
      if ($callback !== null && $callback instanceof \Closure) {
         $callback($this->body, $Throwable ?? null);
      }

      return $this;
   }
}
