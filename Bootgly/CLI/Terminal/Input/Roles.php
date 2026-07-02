<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\Input;


enum Roles : string
{
   // ! Terminal Client/Server API roles
   // Natively both run at once (fork); embedded runtimes run one role per process.
   case Client = 'client';
   case Server = 'server';
}
