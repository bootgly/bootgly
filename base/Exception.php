<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (Slowaways)
 * Copyright 2016-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


class Exception
{
	private $code;
	private $message;
	private $trace;

	public $exceptions = [];


	public function __construct()
	{
	}
}
