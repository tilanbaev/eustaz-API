<?php

class Model
{
  protected $db;

  public function __construct()
  {
    $this->db = $GLOBALS['db'];
  }
}
