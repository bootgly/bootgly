<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI;


use Bootgly\ABI\Errors\Handler as ErrorHandler;
use Bootgly\ABI\Exceptions\Handler as ExceptionHandler;


#set_error_handler(callback: ErrorHandler::handle(...), error_levels: E_ALL | E_STRICT);
#set_exception_handler(callback: ExceptionHandler::handle(...));