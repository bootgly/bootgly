<?php
require '@functions/formatters.php';

require '@interfaces/Debuggable.php';
require '@interfaces/Requestable.php';

require '@traits/Set/Set.php';
require '@traits/Sets/Sets.php';

// __Array
require '__Array/__Array.php';
require '__Array/~functions.php';
// __Class
require '__Class/__Class.php';
require '__Class/Adopted/Adopted.php';
require '__Class/Nulled/Nulled.php';
// __Iterable
require '__Iterable/__Iterable.php';
// __String
#require '__String/.functions.php';
require '__String/__String.php';

// > Escapeable
require '__String/Escapeable/Escapeable.php';
// cursor
require '__String/Escapeable/cursor/Positionable.php';
require '__String/Escapeable/cursor/Shapeable.php';
require '__String/Escapeable/cursor/Visualizable.php';
// mouse
require '__String/Escapeable/mouse/Reportable.php';
// text
require '__String/Escapeable/text/Formattable.php';
require '__String/Escapeable/text/Modifiable.php';
// viewport
require '__String/Escapeable/viewport/Scrollable.php';

// > Path
require '__String/Path/Path.php';

require '__String/~functions.php';

// - data
// Dir
require 'data/Dir.php';
// Table
require 'data/Table.php';

// - streams/socket
// File
require 'streams/File.php';
// Pipe
require 'streams/socket/Pipe/Pipe.php';

// - templates
require 'templates/ANSI/Escaped.php';
require 'templates/Template.php';