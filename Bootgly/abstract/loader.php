<?php
require '@functions.php';
require '@traits.php';

// __Array
require '__Array.php';
// __Class
require '__Class.php';
// __String
require '__String.php';

// > Escapeable
require '__String/Escapeable.php';
// > Escapeable/cursor
require '__String/Escapeable/cursor/Positionable.php';
require '__String/Escapeable/cursor/Shapeable.php';
require '__String/Escapeable/cursor/Visualizable.php';
// > Escapeable/mouse
require '__String/Escapeable/mouse/Reportable.php';
// > Escapeable/text
require '__String/Escapeable/text/Formattable.php';
require '__String/Escapeable/text/Modifiable.php';
// > Escapeable/viewport
require '__String/Escapeable/viewport/Scrollable.php';

// > Path
require '__String/Path.php';


// - data
// Dir
require 'data/Dir.php';
// Table
require 'data/Table.php';
// - iterators
require 'iterators/Iterator.php';


// - streams
// File
require 'streams/File.php';


// - socket
require 'sockets/Pipe.php';


// - templates
require 'templates/ANSI/Escaped.php';
require 'templates/Template.php';
