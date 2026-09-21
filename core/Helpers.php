<?php

function jsonInput()
{
  return json_decode(file_get_contents('php://input'), true);
}

function generateToken($length = 32)
{
  return bin2hex(random_bytes($length));
}
