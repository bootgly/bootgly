<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Alert;


use Bootgly\ABI\Configs\Set;


/**
 * Message severity — shared by the components that classify messages
 * (Alert badges, Toasts boxes, ...).
 *
 * @method self get()
 * @method self set()
 */
enum Type
{
   use Set;


   case Default;
   case Success;
   case Attention;
   case Failure;
}
