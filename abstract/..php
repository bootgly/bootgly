<?php
require '@functions/formatters.php';

require '@interfaces/Requestable.php';

require '@traits/Set/.php';
require '@traits/Sets/.php';

// ! .Types
// ? __Array
require '__array/.php';
require '__array/~functions.php';
// ? __Class
require '__class/adopted/.php';
require '__class/nulled/.php';
// ? __Iterable
require '__iterable/.php';
// ? __String
#require '__String.functions.php';
require '__string/.php';
require '__string/~functions.php';

// *
// ! Autoloader
#require 'Autoloader.php';
// ? __Class
#require 'Autoloader@__Class.php';

// ! Data
// ? Table
require 'data/table/.php';

// ! Streams
// _ socket
// Pipe
require 'streams/socket/pipe/.php';
// _ storage
// Dir
require 'streams/storage/dir/.php';
// File
require 'streams/storage/file/.php';
// Path
require 'streams/storage/path/.php';
