<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;


use const BOOTGLY_PROJECT;
use function defined;
use function is_string;
use function ob_start;
use function preg_match;
use function str_contains;
use Closure;
use Error;
use Throwable;

use const Bootgly\WPI;
use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Templates\Template;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;


/**
 * Built-in view response renderer.
 */
class View extends Resource
{
   // * Config
   // ...

   // * Data
   protected Response $Response;
   /** @var array<string,mixed> */
   protected array $uses = [];

   // * Metadata
   // ...


   public function __construct (Response $Response)
   {
      parent::__construct(persistent: true);

      // * Data
      $this->Response = $Response;
      $this->uses = [];
   }

   /**
    * Export variables to rendered views.
    *
    * @param array<string,mixed> ...$variables
    */
   public function export (array ...$variables): static
   {
      foreach ($variables as $var) {
         foreach ($var as $key => $value) {
            $this->uses[$key] = $value;
         }
      }

      return $this;
   }

   /**
    * Render one project view without sending it yet.
    *
    * @param array<string,mixed>|null $data
    */
   public function render (string $view, null|array $data = null, null|Closure $callback = null): Response
   {
      if ( !defined('BOOTGLY_PROJECT') ) {
         throw new Error('HTTP_Server_CLI must be started through a Project. BOOTGLY_PROJECT is not defined.');
      }

      $Response = $this->Response;

      // ? Normalize + whitelist the view name locally (audit F-12). `render()`
      //   is include-based, so a traversal here is RCE, not mere LFI. The
      //   downstream `File::guard()` (realpath + base containment) blocks it
      //   today, but that is one default flip away from arbitrary inclusion —
      //   keep the guard explicit at the sink, mirroring `Response::upload()`.
      //   Reject empty, null bytes, absolute paths, `..`, and any character
      //   outside `[A-Za-z0-9_/-]`; then collapse the path. Rejection precedes
      //   normalization so a traversal attempt is denied, never resolved into
      //   a different in-jail path.
      if (
         $view === ''
         || str_contains($view, "\0")
         || $view[0] === '/'
         || preg_match('#^[\w/-]+$#', $view) !== 1
      ) {
         return $Response->code(403);
      }
      $view = Path::normalize($view);

      $File = new File(BOOTGLY_PROJECT->path . 'views/' . $view . '.template.php', base: BOOTGLY_PROJECT->path . 'views/');

      if ($File->exists === false) {
         return $Response->code(403);
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
      $content = is_string($rendered) ? $rendered : '';
      $Response->Body->raw = $content;

      // @ Set $Response properties
      $Response->source = 'content';
      $Response->type = '';

      // @ Call callback
      if ($callback !== null) {
         $callback($content, $Throwable ?? null);
      }

      return $Response;
   }

   /**
    * Render and send one project view.
    *
    * @param array<string,mixed>|null $data
    */
   public function send (mixed $view = null, null|array $data = null, null|Closure $callback = null): Response
   {
      if (is_string($view) === false) {
         return $this->Response->code(403)->send('');
      }

      return $this->render($view, $data, $callback)->send();
   }
}
