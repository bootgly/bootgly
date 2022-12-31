<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


class Path
{
	const DIR_ = DIRECTORY_SEPARATOR;
	const PATH_ = PATH_SEPARATOR;
	//?
	const DOCUMENTATION_CURRENT = '!';
	const EXCLUDED_CURRENT = '&';
	//? I/O
		// Input («)
	const INPUT_CURRENT = '«';
	const INPUT_DIR_RELATIVE = self::INPUT_CURRENT.self::DIR_;
		// Output (»)
	const OUTPUT_CURRENT = '»';
	const OUTPUT_DIR_RELATIVE = self::OUTPUT_CURRENT.self::DIR_;
	//? Test (✓)
	const TEST_CURRENT = '✓';
	const TEST_DIR_RELATIVE = self::TEST_CURRENT.self::DIR_;
	const TEST_DIR = HOME_DIR.self::TEST_DIR_RELATIVE;


	public $Path = '';
	//@Config
	public bool $convert = false;
		public bool $lowercase = false;
	public bool $fix = true;
		public bool $dir_ = true;
		public bool $real = false;
		public bool $utf8 = false;
	public bool $match = true;
		public string $pattern = '';
  //@Data
	//private $root;     // /var/www/sys/index.php => /var
	//private $parent;   // /var/www/sys/index.php => /var/www/sys/
	//private $current;  // /var/www/sys/index.php => index.php
	//private $paths;    // /var/www/sys/ => [0 => 'var', 1 => 'www', 2 => 'sys']
	//private string $type;     // -> 'dir' or 'file'
	//@Meta
	//private $relative; 


  public function __construct (string $path = '') {
		if ($path) {
			$this->Path = $this->Path($path);
		}
	}
	public function __get (string $key) {
		switch ($key) {
			case '_':
				return $this->Path;

  		//@Data
			case 'root':
				$root = strstr($this->Path, self::DIR_, true);
				if ($root) {
					$root = $root.self::DIR_;
				}
				return $root;
			case 'parent':
				$parent = '';
				if ($this->Path) {
					$parent = dirname($this->Path);
					if ($parent[-1] !== self::DIR_) {
						$parent .= self::DIR_;
					}
				}
				return $parent;
			case 'current':
				$current = '';
				if ($this->Path) {
					$current = substr(strrchr( (new self)->Path($this->Path), self::DIR_ ), 1);
					if ($current == '') {
						$current = basename($this->Path);
					}
				}
				return $current;
			case 'paths':
				$this->paths = self::split($this->Path); break; // TODO dynamically ???
			case 'type':
				$this->type = ( new \SplFileInfo($this->Path) )->getType(); break;

			case 'Index':
				return new class ($this->paths) {
					public $last;

					public function __construct ($paths) {
						$this->last = (new __Array($paths))->Last->key;
					}
				};
		}

		return @$this->$key;
	}
	public function __set (string $key, $value) {
		switch($key){
			case 'root':
			case 'parent':
			case 'current':
			case 'paths':
			case 'relative':
				$this->$key = $value;
				break;

			default:
				$this->$key = new Path($value);
		}
	}
	public function __call (string $name, array $arguments) {
		switch($name){
			case 'cut':
				return $this->relative = self::cut($this->Path, ...$arguments);
			case 'split':
				return self::split($this->Path); break;
			case 'join':
				return self::join($this->paths, ...$arguments); break;
			case 'search':
				return self::search($this->paths, ...$arguments); break;
			case 'concatenate':
				return self::concatenate($this->paths, ...$arguments); break;
		}
	}
	public static function __callStatic (string $name, $arguments) {
		return self::$name(...$arguments);
	}
	public function __invoke (string $path) {
		$this->Path = '';

		$this->__construct($path);

		return $this;
	}
	public function __toString (): string
	{
		return $this->Path;
	}

	public function Path (string $path): string
	{
		$Path = '';

		if ($path) {
			$Path = $path;

			// @ Convert
			if ($this->convert) {
				if ($this->lowercase) { // ? (Convert) - Convert path string to lowercase
					$Path = strtolower($Path);
				}
			}
			// @ Fix
			if ($this->fix) {
				if ($this->dir_) { // ? (Fix) - Overwrites all directory separators with the standard separator
					if (self::DIR_ === '/') {
						$Path = str_replace('\\', '/', $Path);
					} elseif (self::DIR_ === '\\') {
						$Path = str_replace('/', '\\', $Path);
					}
				}

				if ($this->real) { // ? (Fix) - RealPath (temp)
					$Path = realpath($Path);
				}

				if ($this->utf8) { // ? (Fix) - UTF8 Decode: Makes safe paths with utf-8 characters - ONLY WINDOWS?
					if ( preg_match('!!u', $Path) ) {
						// $Path = utf8_decode($Path);
						// $Path = iconv('utf-8', 'cp1252', $Path);
					}
				}
			}
			// @ Match
			// TODO
		}

		return $Path;
	}
	public function match (string $path, bool $relative = false) {
		if ($this->pattern) {
			$Path = Path::join(Path::split($path), $this->pattern);

			if ($this->root) {
				$Path = $this->root.$Path;
			}

			$paths = glob($Path);

			if (isSet($paths[0])) {
				if ($relative) {
					$Path = self::cut($paths[0], $this->root, -1);
				} else {
					$Path = $paths[0];
				}
			} else {
				$Path = $path;
			}

			$this->Path = $Path;
		}
	}

	public static function cut ($path, $path2, int $direction, string $current = ''): string
	{
		$Path = '';

		if ($path and $path2) {
			switch ($direction) {
				case -1:
					$path2Len = strlen($path2);
					for ($i = 0; $i < $path2Len; $i++) {
						if (@$path[$i] !== @$path2[$i]) {
							return $Path;
						}
					}

					$Path = substr($path, $path2Len); break;
				case 1:
					$Path = rtrim($path, $path2); break;
				case 0:
					$Path = substr($path, strlen($path2), strlen($current) * -1); break;
			}
		}

		return $Path;
	}
	public static function split (string $path): array
	{
		// $path = '/var/www/sys/';
		$paths = [];

		if($path){
			$path = trim($path, "\x2F"); // /
			$path = trim($path, "\x5C"); // \
			$path = str_replace("\\", "/", $path);

			$paths = explode("/", $path);
		}

		return $paths;
		// return [0 => 'var', 1 => 'www', 2 => 'sys'];
	}
	private static function join (array $paths, string $format = '%'): string
	{
		$path = '';

		foreach ($paths as $current) {
			$path .= __String::replace('%', $current, $format).self::DIR_;
		}

		$path = __String::trim($path, self::DIR_, 1);

		return $path;
	}
	private static function concatenate (array $paths, int $from = 0): string
	{
		$Path = '';
		foreach ($paths as $index => $path) {
			if ($index >= $from) {
				$Path .= $path;

				// ? Concat with "/" if the node is not file
				$Result = __String::search($path, '.');
				if ($Result->position === false)
					$Path .= self::DIR_;
			}
		}
		return $Path;
	}
	private static function search (array $paths, $needle) {
		return __Array::search($paths, $needle);
	}
}
