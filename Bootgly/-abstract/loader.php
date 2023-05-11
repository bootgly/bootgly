<?php
include '@functions.php';
include '@traits.php';

// __Array
include '__Array.php';
// __Class
include '__Class.php';
// __String
include '__String.php';

// > Escapeable
include '__String/Escapeable.php';
// > Escapeable/cursor
include '__String/Escapeable/cursor/Positionable.php';
include '__String/Escapeable/cursor/Shapeable.php';
include '__String/Escapeable/cursor/Visualizable.php';
// > Escapeable/mouse
include '__String/Escapeable/mouse/Reportable.php';
// > Escapeable/text
include '__String/Escapeable/text/Formattable.php';
include '__String/Escapeable/text/Modifiable.php';
// > Escapeable/viewport
include '__String/Escapeable/viewport/Scrollable.php';

// > Path
include '__String/Path.php';


// - data
// Dir
include 'data/Dir.php';
// Table
include 'data/Table.php';
// - iterators
include 'iterators/Iterator.php';


// - streams
// File
include 'streams/File.php';


// - socket
include 'sockets/Pipe.php';


// - templates
include 'templates/ANSI/Escaped.php';
include 'templates/Template.php';
