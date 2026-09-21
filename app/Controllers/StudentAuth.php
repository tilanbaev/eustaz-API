<?php

class StudentAuth extends BaseAuth
{
  public function sendVerificationCode($userType = 'user')
  {
    return parent::sendVerificationCode($userType);
  }

  public function verifyCode($userType = 'user')
  {
    return parent::verifyCode($userType);
  }

  public function register($userType = 'user', $additionalData = [])
  {
    return parent::register($userType, $additionalData);
  }

  public function login($userType = 'user')
  {
    return parent::login($userType);
  }

  public function forgotPassword($userType = 'user')
  {
    return parent::forgotPassword($userType);
  }

  public function verifyResetCode($userType = 'user')
  {
    return parent::verifyResetCode($userType);
  }

  public function resetPassword($userType = 'user')
  {
    return parent::resetPassword($userType);
  }
}
