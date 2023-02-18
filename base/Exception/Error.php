<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (Slowaways)
 * Copyright 2016-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Exception;


use Bootgly\Exception;


class Error extends Exception
{
	//@ Config
	public bool $fatal = false;
	//@ Data
	public string $title;
	public $code;
	public $message;
	public $solution;

	private $trace;

	// Templates
	private $errors = [
		'E_TEST_1' => [
			'fatal' => false,
			'message' => '...',
			'solution' => ''
		],
		'E_TEST_2' => [
			'fatal' => false,
			'message' => "This is a %s and it's %s!",
			'solution' => ''
		]
	];

	// Output
	private $thrown = [];
	public static $output = [];


	public function __construct(string $message = '')
	{
		if ($message) {
			// Trace
			$trace = debug_backtrace()[0];
			$this->trace = $trace;
			// Message
			$this->message = $message;

			$this->throw();
		}
	}
	public function __get(string $index)
	{
		switch ($index) {
			case 'output':
				return self::$output;
				break;
			case 'thrown':
				return $this->thrown;
		}
	}


	public function throw()
	{
		// Title
		$title = $this->title;
		// Code
		$code = $this->code;
		// Trace
		$trace = $this->trace;
		if (!$trace) {
			$trace = debug_backtrace()[0];
			$this->trace = $trace;
		}

		$error = @$this->errors[$code];
		if ($error) {
			$message = sprintf($error['message'], ...$this->message);
			$solution = $error['solution'];
			$fatal = $error['fatal'];
		} else {
			$message = $this->message;
			$solution = $this->solution;
			$fatal = $this->fatal;
		}

		// Fatal
		if ($fatal) {
			if (!$solution)
				$solution = '-- Not defined --';
			if (!$code)
				$code = 'error';

			$message = <<<ERROR
<div style="margin-left: 10px;">
	<h1>$title</h1>
	<h3>Code:</h3>
	<p>$code</p>
	<h3>Context:</h3>
	<h3>Type:</h3>
	<p>Fatal error</p>
	<h3>Message:</h3>
	<p>$message</p>
	<h3>Solution:</h3>
	<p>$solution</p>
</div>
ERROR;

			exit($message);
		}

		// Thrown
		$thrown = [
			'code' => $code,
			'message' => $message,
			'trace' => $trace,

			'solution' => $solution,
			'fatal' => $fatal
		];
		$this->thrown[] = $thrown;

		// Output
		self::$output[] = $thrown;
	}

	public function __destruct()
	{
	}
}
