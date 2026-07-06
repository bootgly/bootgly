<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use const Bootgly\CLI;
use Bootgly\API\Projects\Project;


return new Project(
   // # Project Metadata
   name: '__NAME__',
   description: '__DESCRIPTION__',
   version: '__VERSION__',
   author: '__AUTHOR__',
   exportable: true,

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $Output = CLI->Terminal->Output;

      $Output->render('@#green:__NAME__ booted!@;@.;');
      $Output->render('Edit @#cyan:projects/__PATH__/__LEAF__.project.php@; to start building.@.;');
   }
);
