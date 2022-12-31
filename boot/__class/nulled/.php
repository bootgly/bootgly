<?php
namespace Bootgly\__Class;


class Nulled
{
  public function __construct()
  {

  }

  public function __get($name)
  {
    return $this;
  }
  public function __set($name, $value)
  {
     $this->$name = $value;
  }

  public function __call($name, $arguments)
  {
     return null;
  }
}
