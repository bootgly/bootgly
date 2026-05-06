<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Projects;


use Bootgly\API\Environment\Configs as EnvironmentConfigs;


/**
 * Project config loader with framework fallback overlay support.
 */
class Configs extends EnvironmentConfigs
{
   /**
    * Create a project config loader for the project's config directory.
    */
   public function __construct (string $basedir)
   {
      parent::__construct($basedir);
   }

   /**
    * Merge a framework scope under the project scope.
    *
    * Project values win and framework values fill missing project nodes. Both
    * loaders keep `.env` values local, so overlay does not mutate process env.
    */
   public function overlay (EnvironmentConfigs $Framework, string $scope): void
   {
      // @ Ensure framework scope is loaded
      if ($Framework->Scopes->check($scope) === false) {
         $Framework->load($scope);
      }

      // @ Ensure project scope is loaded
      if ($this->Scopes->check($scope) === false) {
         $this->load($scope);
      }

      $FrameworkConfig = $Framework->Scopes->get($scope);
      $ProjectConfig = $this->Scopes->get($scope);

      if ($FrameworkConfig !== null && $ProjectConfig !== null) {
         $ProjectConfig->merge($FrameworkConfig);
      }
   }
}
