<?php

class BaseAuth extends Controller
{
  private string $jwtSecret;
  private int $jwtExpire;
  private int $refreshExpire;
  private int $MaxLoginAttempts;
  private int $LoginTimeout;
  private array $trustedProxies;

  public function __construct()
  {
    $this->jwtSecret = $_ENV['JWT_SECRET'] ?? '';
    $this->jwtExpire = (int) ($_ENV['JWT_EXPIRE'] ?? 60 * 60 * 24);
    $this->refreshExpire = (int) ($_ENV['JWT_REFRESH_EXPIRE'] ?? 60 * 60 * 24 * 30);
    $this->MaxLoginAttempts = (int) ($_ENV['MAX_LOGIN_ATTEMPTS'] ?? 5);
    $this->LoginTimeout = (int) ($_ENV['LOGIN_TIMEOUT'] ?? 300);

    $proxies = $_ENV['TRUSTED_PROXIES'] ?? '';
    $this->trustedProxies = array_filter(array_map('trim', explode(',', $proxies)));
  }

  // 📧 ОТПРАВКА ВЕРИФИКАЦИОННОГО КОДА
  public function sendVerificationCode($userType = 'user')
  {
    try {
      $data = jsonInput();
      if (!isset($data['email'])) {
        return $this->json(['error' => 'Email обязателен'], 400);
      }

      $email = trim($data['email']);

      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $this->json(['error' => 'Неверный формат email'], 400);
      }

      // Rate limiting по IP
      $clientIp = $this->getClientIp();
      if (!$this->checkRateLimit($clientIp, 'email_code', 3, 60)) {
        return $this->json(['error' => 'Слишком много запросов. Попробуйте позже.'], 429);
      }

      $userModel = new User();

      // Для учителей проверяем существование email
      if ($userType === 'teacher') {
        $existing = $userModel->findByEmailAndRole($email, 'teacher');
        if ($existing) {
          return $this->json(['error' => 'Email уже зарегистрирован'], 400);
        }
      } else {
        // Для учеников проверяем существование
        $existing = $userModel->findByEmailAndRole($email, 'user');
        if ($existing) {
          return $this->json(['error' => 'Email уже зарегистрирован'], 400);
        }
      }

      // Защита от спама
      $lastSent = $userModel->getLastCodeSentTime($email);
      if ($lastSent && (time() - $lastSent) < 60) {
        return $this->json(['error' => 'Пожалуйста, подождите перед запросом нового кода'], 429);
      }

      $code = random_int(100000, 999999);

      // Сохраняем код
      $result = $userModel->saveVerificationCode($email, $code);
      if (!$result) {
        return $this->json(['error' => 'Не удалось сохранить код подтверждения'], 500);
      }

      // Отправляем email
      $subject = $userType === 'teacher'
        ? "Ваш код подтверждения для регистрации преподавателя"
        : "Ваш код подтверждения для регистрации";

      $message = "Ваш код подтверждения: $code\nКод действителен 10 минут.";
      $headers = "From: no-reply@eustaz.kz\r\n";
      $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

      if (!mail($email, $subject, $message, $headers)) {
        error_log("Failed to send email to: $email");
        return $this->json(['error' => 'Не удалось отправить код подтверждения'], 500);
      }

      $this->updateRateLimit($clientIp, 'email_code');

      return $this->json(['message' => 'Код подтверждения отправлен']);
    } catch (Exception $e) {
      error_log("SendCode error: " . $e->getMessage());
      return $this->json(['error' => 'Внутренняя ошибка сервера'], 500);
    }
  }

  // ✅ ПРОВЕРКА ВЕРИФИКАЦИОННОГО КОДА
  public function verifyCode($userType = 'user')
  {
    try {
      $data = jsonInput();
      if (!isset($data['email']) || !isset($data['code'])) {
        return $this->json(['error' => 'Email и код обязательны'], 400);
      }

      $email = trim($data['email']);
      $code = trim($data['code']);

      $clientIp = $this->getClientIp();
      if (!$this->checkRateLimit($clientIp, 'verify_code', 5, 60)) {
        return $this->json(['error' => 'Слишком много попыток. Попробуйте позже.'], 429);
      }

      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $this->json(['error' => 'Неверный формат email'], 400);
      }

      if (!preg_match('/^\d{6}$/', $code)) {
        return $this->json(['error' => 'Неверный формат кода'], 400);
      }

      $userModel = new User();
      $isValid = $userModel->checkVerificationCode($email, $code);

      if (!$isValid) {
        $this->updateRateLimit($clientIp, 'verify_code');
        return $this->json(['error' => 'Неверный или просроченный код'], 400);
      }

      return $this->json(['message' => 'Код успешно подтвержден']);
    } catch (Exception $e) {
      error_log("VerifyCode error: " . $e->getMessage());
      return $this->json(['error' => 'Внутренняя ошибка сервера'], 500);
    }
  }

  // 📝 РЕГИСТРАЦИЯ ПОЛЬЗОВАТЕЛЯ
  public function register($userType = 'user', $additionalData = [])
  {
    try {
      $data = jsonInput();

      // Базовые обязательные поля для всех
      $requiredFields = ['email', 'name', 'password'];
      foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
          return $this->json(['error' => "Поле '$field' обязательно для заполнения"], 400);
        }
      }

      $email = trim($data['email']);
      $name = trim($data['name']);
      $password = $data['password'];

      // Дополнительные поля
      $last_name = isset($data['last_name']) ? trim($data['last_name']) : null;
      $patronymic = isset($data['patronymic']) ? trim($data['patronymic']) : null;
      $birth_date = isset($data['birth_date']) ? trim($data['birth_date']) : null;
      $phone = isset($data['phone']) ? trim($data['phone']) : null;

      $clientIp = $this->getClientIp();
      if (!$this->checkRateLimit($clientIp, 'registration', 3, 300)) {
        return $this->json(['error' => 'Слишком много попыток регистрации'], 429);
      }

      // Валидация базовых полей
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $this->json(['error' => 'Неверный формат email'], 400);
      }

      if (empty($name) || strlen($name) < 2) {
        return $this->json(['error' => 'Имя должно содержать минимум 2 символа'], 400);
      }

      if (!$this->isPasswordStrong($password)) {
        return $this->json(['error' => 'Пароль должен содержать минимум 8 символов, включая буквы и цифры'], 400);
      }

      $userModel = new User();

      // Проверка верификации email
      if (!$userModel->isEmailVerified($email)) {
        return $this->json(['error' => 'Email не подтвержден'], 400);
      }

      // Проверка существующего email
      $existing = $userModel->findByEmail($email);
      if ($existing) {
        return $this->json(['error' => 'Email уже зарегистрирован'], 400);
      }

      // Проверка телефона
      if ($phone !== null) {
        $existing_phone = $userModel->findByPhone($phone);
        if ($existing_phone) {
          return $this->json(['error' => 'Номер телефона уже зарегистрирован'], 400);
        }
      }

      // Создаем пользователя
      $user = $userModel->create(
        $name,
        $email,
        $password,
        $last_name,
        $patronymic,
        $birth_date,
        $phone,
        $userType
      );

      if (!$user) {
        return $this->json(['error' => 'Не удалось создать аккаунт'], 500);
      }

      // Дополнительная логика для учителей
      if ($userType === 'teacher' && !empty($additionalData)) {
        $teacherModel = new Teacher();
        $teacher = $teacherModel->createTeacherProfile(
          $user['id'],
          $additionalData['subject'],
          $additionalData['experience'],
          $additionalData['education'],
          $additionalData['specialization'] ?? null,
          $additionalData['achievements'] ?? null
        );

        if (!$teacher) {
          // Откатываем создание пользователя
          $userModel->deleteUser($user['id']);
          return $this->json(['error' => 'Не удалось создать профиль преподавателя'], 500);
        }
      }

      // Автоматическая авторизация после регистрации
      $tokens = $this->generateJWTTokens($user['id'], $user['email'], $userType);
      if (!$tokens) {
        return $this->json(['error' => 'Ошибка создания токена авторизации'], 500);
      }

      $userModel->updateLastLogin($user['id']);

      if (method_exists($userModel, 'logLoginAttempt')) {
        $userModel->logLoginAttempt($email, true, $clientIp);
      }

      if (method_exists($userModel, 'saveRefreshToken')) {
        $userModel->saveRefreshToken($user['id'], $tokens['refresh_token'], date('Y-m-d H:i:s', time() + $this->refreshExpire), $clientIp);
      }

      // Очищаем верификационные данные
      $userModel->clearVerificationData($email);

      $this->resetRateLimit($clientIp, 'registration');

      // Подготавливаем данные пользователя
      $userData = $this->prepareUserData($user, $userType);

      return $this->json([
        'user' => $userData,
        'access_token' => $tokens['access_token'],
        'refresh_token' => $tokens['refresh_token'],
        'expires_in' => $this->jwtExpire,
        'token_type' => 'Bearer',
        'message' => 'Пользователь успешно зарегистрирован и авторизован'
      ]);
    } catch (Exception $e) {
      error_log("Registration error: " . $e->getMessage());
      return $this->json(['error' => 'Внутренняя ошибка сервера'], 500);
    }
  }

  // 🔐 АВТОРИЗАЦИЯ
  public function login($userType = 'user')
  {
    try {
      $data = jsonInput();

      if (!isset($data['email']) || !isset($data['password'])) {
        return $this->json(['error' => 'Email и пароль обязательны'], 400);
      }

      $email = trim($data['email']);
      $password = $data['password'];

      $clientIp = $this->getClientIp();
      if (!$this->checkRateLimit($clientIp, 'login', $this->MaxLoginAttempts, $this->LoginTimeout)) {
        return $this->json(['error' => 'Слишком много попыток входа. Попробуйте через 5 минут.'], 429);
      }

      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->updateRateLimit($clientIp, 'login');
        return $this->json(['error' => 'Неверный формат email'], 400);
      }

      $userModel = new User();

      // Ищем пользователя по email и роли
      $user = $userModel->findByEmailAndRole($email, $userType);
      $hash = $user && isset($user['password_hash']) ? $user['password_hash'] : password_hash('dummy_password', PASSWORD_DEFAULT);

      // Защита от timing-атак
      $start = microtime(true);
      $passwordValid = password_verify($password, $hash);
      $end = microtime(true);

      $executionTime = $end - $start;
      if ($executionTime < 0.1) {
        $sleepMicroseconds = (0.1 - $executionTime) * 1000000;
        usleep((int)round($sleepMicroseconds));
      }

      if (!$user || !$passwordValid) {
        $this->updateRateLimit($clientIp, 'login');
        if (method_exists($userModel, 'logLoginAttempt')) {
          $userModel->logLoginAttempt($email, false, $clientIp);
        }
        return $this->json(['error' => 'Неверный email или пароль'], 401);
      }

      if (isset($user['is_blocked']) && $user['is_blocked']) {
        return $this->json(['error' => 'Аккаунт заблокирован'], 403);
      }

      // Для учителей проверяем статус
      if ($userType === 'teacher') {
        $teacherModel = new Teacher();
        $teacherProfile = $teacherModel->findByUserId($user['id']);
        if ($teacherProfile && isset($teacherProfile['status']) && $teacherProfile['status'] !== 'active') {
          return $this->json(['error' => 'Аккаунт преподавателя не активирован'], 403);
        }
      }

      // Проверка частоты логинов
      if (!$this->checkLoginFrequency($user['id'])) {
        return $this->json(['error' => 'Подозрительная активность. Попробуйте позже.'], 429);
      }

      $tokens = $this->generateJWTTokens($user['id'], $user['email'], $userType);
      if (!$tokens) {
        return $this->json(['error' => 'Ошибка создания токена'], 500);
      }

      $userModel->updateLastLogin($user['id']);
      if (method_exists($userModel, 'logLoginAttempt')) {
        $userModel->logLoginAttempt($email, true, $clientIp);
      }

      if (method_exists($userModel, 'saveRefreshToken')) {
        $userModel->saveRefreshToken($user['id'], $tokens['refresh_token'], date('Y-m-d H:i:s', time() + $this->refreshExpire), $clientIp);
      }

      $this->resetRateLimit($clientIp, 'login');

      $userData = $this->prepareUserData($user, $userType);

      return $this->json([
        'user' => $userData,
        'access_token' => $tokens['access_token'],
        'refresh_token' => $tokens['refresh_token'],
        'expires_in' => $this->jwtExpire,
        'token_type' => 'Bearer',
        'message' => 'Успешная авторизация'
      ]);
    } catch (Exception $e) {
      error_log("Login error: " . $e->getMessage());
      return $this->json(['error' => 'Внутренняя ошибка сервера'], 500);
    }
  }

  // 🔄 ОБНОВЛЕНИЕ ТОКЕНА
  public function refreshToken()
  {
    try {
      $data = jsonInput();
      $refreshToken = $data['refresh_token'] ?? null;

      if (!$refreshToken) {
        return $this->json(['error' => 'Refresh token обязателен'], 400);
      }

      $clientIp = $this->getClientIp();
      if (!$this->checkRateLimit($clientIp, 'refresh_token', 10, 60)) {
        return $this->json(['error' => 'Слишком много запросов'], 429);
      }

      $payload = $this->decodeJWT($refreshToken);
      if (!$payload || ($payload['type'] ?? '') !== 'refresh') {
        $this->updateRateLimit($clientIp, 'refresh_token');
        return $this->json(['error' => 'Невалидный refresh token'], 401);
      }

      $userId = $payload['user_id'] ?? null;
      $role = $payload['role'] ?? 'user';

      if (!$userId) {
        $this->updateRateLimit($clientIp, 'refresh_token');
        return $this->json(['error' => 'Невалидный refresh token'], 401);
      }

      $userModel = new User();

      if (method_exists($userModel, 'isRefreshTokenValid')) {
        $tokenValid = $userModel->isRefreshTokenValid($userId, $refreshToken, $clientIp);
        if (!$tokenValid) {
          $this->updateRateLimit($clientIp, 'refresh_token');
          return $this->json(['error' => 'Refresh token истек или отозван'], 401);
        }
      }

      $user = $userModel->findById($userId);
      if (!$user) {
        return $this->json(['error' => 'Пользователь не найден'], 404);
      }

      // Отзываем старый токен
      if (method_exists($userModel, 'revokeRefreshToken')) {
        $userModel->revokeRefreshToken($userId, $refreshToken);
      }

      $newTokens = $this->generateJWTTokens($userId, $user['email'], $role);

      if (!$newTokens) {
        return $this->json(['error' => 'Ошибка создания токена'], 500);
      }

      if (method_exists($userModel, 'saveRefreshToken')) {
        $userModel->saveRefreshToken($userId, $newTokens['refresh_token'], date('Y-m-d H:i:s', time() + $this->refreshExpire), $clientIp);
      }

      return $this->json([
        'access_token' => $newTokens['access_token'],
        'refresh_token' => $newTokens['refresh_token'],
        'expires_in' => $this->jwtExpire,
        'token_type' => 'Bearer'
      ]);
    } catch (Exception $e) {
      error_log("Refresh token error: " . $e->getMessage());
      return $this->json(['error' => 'Внутренняя ошибка сервера'], 500);
    }
  }

  // 🚪 ВЫХОД
  public function logout()
  {
    try {
      $data = jsonInput();
      $refreshToken = $data['refresh_token'] ?? null;

      $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
      $accessToken = str_replace('Bearer ', '', $authHeader);

      if ($refreshToken) {
        $payload = $this->decodeJWT($refreshToken);
        if ($payload && isset($payload['user_id'])) {
          $userModel = new User();
          if (method_exists($userModel, 'revokeRefreshToken')) {
            $userModel->revokeRefreshToken($payload['user_id'], $refreshToken);
          }
        }
      }

      return $this->json(['message' => 'Успешный выход']);
    } catch (Exception $e) {
      error_log("Logout error: " . $e->getMessage());
      return $this->json(['error' => 'Внутренняя ошибка сервера'], 500);
    }
  }

  // 🔍 ПРОВЕРКА АВТОРИЗАЦИИ
  public function checkAuth()
  {
    try {
      $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
      $token = str_replace('Bearer ', '', $authHeader);

      if (empty($token)) {
        return $this->json(['error' => 'Токен доступа обязателен'], 401);
      }

      $user = $this->getUserFromToken($token);
      if (!$user) {
        return $this->json(['error' => 'Невалидный или просроченный токен'], 401);
      }

      return $this->json([
        'user' => $user,
        'message' => 'Токен валиден'
      ]);
    } catch (Exception $e) {
      error_log("CheckAuth error: " . $e->getMessage());
      return $this->json(['error' => 'Внутренняя ошибка сервера'], 500);
    }
  }

  // 🔐 MIDDLEWARE ДЛЯ ПРОВЕРКИ ТОКЕНА
  public function verifyToken($token)
  {
    if (empty($token)) {
      return false;
    }

    if (strpos($token, 'Bearer ') === 0) {
      $token = substr($token, 7);
    }

    $payload = $this->decodeJWT($token);

    if (!$payload || ($payload['type'] ?? '') !== 'access') {
      return false;
    }

    return $payload['user_id'] ?? false;
  }

  // 👤 ПОЛУЧЕНИЕ ПОЛЬЗОВАТЕЛЯ ПО ТОКЕНУ
  public function getUserFromToken($token)
  {
    $userId = $this->verifyToken($token);
    if (!$userId) {
      return null;
    }

    $userModel = new User();
    $user = $userModel->findById($userId);

    if (!$user) {
      return null;
    }

    return $this->prepareUserData($user, $user['role']);
  }

  // 📊 ПОДГОТОВКА ДАННЫХ ПОЛЬЗОВАТЕЛЯ
  private function prepareUserData($user, $userType)
  {
    $userData = [
      'id' => $user['id'],
      'email' => $user['email'],
      'name' => $user['name'],
      'last_name' => $user['last_name'] ?? null,
      'patronymic' => $user['patronymic'] ?? null,
      'birth_date' => $user['birth_date'] ?? null,
      'phone' => $user['phone'] ?? null,
      'role' => $userType,
      'is_blocked' => $user['is_blocked'] ?? false,
      'last_login' => $user['last_login'] ?? null,
      'created_at' => $user['created_at']
    ];

    // Добавляем дополнительные данные для учителей
    if ($userType === 'teacher') {
      $teacherModel = new Teacher();
      $teacherProfile = $teacherModel->findByUserId($user['id']);

      if ($teacherProfile) {
        $userData['teacher_profile'] = [
          'subject' => $teacherProfile['subject'],
          'experience' => $teacherProfile['experience'],
          'education' => $teacherProfile['education'],
          'specialization' => $teacherProfile['specialization'],
          'achievements' => $teacherProfile['achievements'],
          'status' => $teacherProfile['status'],
          'created_at' => $teacherProfile['created_at']
        ];
      }
    }

    return $userData;
  }

  // 🔑 ГЕНЕРАЦИЯ JWT ТОКЕНОВ
  private function generateJWTTokens($userId, $email, $role = 'user')
  {
    try {
      $time = time();

      $accessTokenPayload = [
        'user_id' => $userId,
        'email' => $email,
        'role' => $role,
        'type' => 'access',
        'iat' => $time,
        'exp' => $time + $this->jwtExpire
      ];

      $refreshTokenPayload = [
        'user_id' => $userId,
        'role' => $role,
        'type' => 'refresh',
        'iat' => $time,
        'exp' => $time + $this->refreshExpire
      ];

      $accessToken = $this->encodeJWT($accessTokenPayload);
      $refreshToken = $this->encodeJWT($refreshTokenPayload);

      return [
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken
      ];
    } catch (Exception $e) {
      error_log("JWT generation error: " . $e->getMessage());
      return null;
    }
  }

  // 🔒 КОДИРОВАНИЕ JWT
  private function encodeJWT($payload)
  {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payloadJson = json_encode($payload);

    $base64UrlHeader = $this->base64UrlEncode($header);
    $base64UrlPayload = $this->base64UrlEncode($payloadJson);

    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $this->jwtSecret, true);
    $base64UrlSignature = $this->base64UrlEncode($signature);

    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
  }

  // 🔓 ДЕКОДИРОВАНИЕ JWT
  private function decodeJWT($token)
  {
    try {
      $parts = explode('.', $token);
      if (count($parts) !== 3) {
        return null;
      }

      list($headerB64, $payloadB64, $signatureB64) = $parts;

      $headerJson = $this->base64UrlDecode($headerB64);
      $payloadJson = $this->base64UrlDecode($payloadB64);
      if ($headerJson === false || $payloadJson === false) {
        return null;
      }

      $header = json_decode($headerJson, true);
      $payload = json_decode($payloadJson, true);

      if (!is_array($header) || !is_array($payload)) {
        return null;
      }

      if (!isset($header['alg']) || strtoupper($header['alg']) !== 'HS256') {
        return null;
      }

      $validSignatureRaw = hash_hmac('sha256', $headerB64 . "." . $payloadB64, $this->jwtSecret, true);
      $validSignatureB64 = $this->base64UrlEncode($validSignatureRaw);

      if (!hash_equals($validSignatureB64, $signatureB64)) {
        return null;
      }

      if (isset($payload['exp']) && $payload['exp'] < time()) {
        return null;
      }

      return $payload;
    } catch (Exception $e) {
      error_log("JWT decode error: " . $e->getMessage());
      return null;
    }
  }

  // 📈 RATE LIMITING МЕТОДЫ
  private function checkRateLimit($identifier, $action, $maxAttempts, $timeWindow)
  {
    $userModel = new User();

    if (method_exists($userModel, 'getRateLimitCount')) {
      $currentAttempts = $userModel->getRateLimitCount($identifier, $action, $timeWindow);
      return $currentAttempts < $maxAttempts;
    }

    return true;
  }

  private function updateRateLimit($identifier, $action)
  {
    $userModel = new User();

    if (method_exists($userModel, 'incrementRateLimit')) {
      $userModel->incrementRateLimit($identifier, $action);
    }
  }

  private function resetRateLimit($identifier, $action)
  {
    $userModel = new User();

    if (method_exists($userModel, 'clearRateLimit')) {
      $userModel->clearRateLimit($identifier, $action);
    }
  }

  // 🔐 ПРОВЕРКА ЧАСТОТЫ ЛОГИНОВ
  private function checkLoginFrequency($userId)
  {
    $userModel = new User();

    if (method_exists($userModel, 'getRecentLoginCount')) {
      $recentLogins = $userModel->getRecentLoginCount($userId, 600);
      return $recentLogins < 5;
    }

    return true;
  }

  // 🌐 ПОЛУЧЕНИЕ IP КЛИЕНТА
  private function getClientIp()
  {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    if ($remoteAddr && in_array($remoteAddr, $this->trustedProxies) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $xff = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
      $xff = array_map('trim', $xff);
      foreach ($xff as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
          return $ip;
        }
      }
    }

    if (filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
      return $remoteAddr;
    }

    return 'unknown';
  }

  // 🛡️ ВАЛИДАЦИЯ ПАРОЛЯ
  private function isPasswordStrong($password)
  {
    if (strlen($password) < 8) return false;
    if (!preg_match('/[A-Za-z]/', $password)) return false;
    if (!preg_match('/[0-9]/', $password)) return false;

    return true;
  }

  // 🔄 BASE64 URL ENCODE/DECODE
  private function base64UrlEncode($data)
  {
    $b64 = base64_encode($data);
    $b64 = str_replace(['+', '/', '='], ['-', '_', ''], $b64);
    return $b64;
  }

  private function base64UrlDecode($data)
  {
    $b64 = str_replace(['-', '_'], ['+', '/'], $data);
    $pad = strlen($b64) % 4;
    if ($pad > 0) {
      $b64 .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($b64, true);
    return $decoded === false ? false : $decoded;
  }

  // 🔑 ВОССТАНОВЛЕНИЕ ПАРОЛЯ
  public function forgotPassword($userType = 'user')
  {
    try {
      $data = jsonInput();
      if (!isset($data['email'])) {
        return $this->json(['error' => 'Email обязателен'], 400);
      }

      $email = trim($data['email']);

      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $this->json(['error' => 'Неверный формат email'], 400);
      }

      $clientIp = $this->getClientIp();
      if (!$this->checkRateLimit($clientIp, 'forgot_password', 3, 300)) {
        return $this->json(['error' => 'Слишком много запросов. Попробуйте позже.'], 429);
      }

      $userModel = new User();
      $user = $userModel->findByEmailAndRole($email, $userType);

      // Для безопасности не сообщаем, существует ли email
      if (!$user) {
        $this->updateRateLimit($clientIp, 'forgot_password');
        return $this->json(['message' => 'Если email зарегистрирован, на него отправлена инструкция по сбросу пароля']);
      }

      if (isset($user['is_blocked']) && $user['is_blocked']) {
        return $this->json(['error' => 'Аккаунт заблокирован'], 403);
      }

      // Защита от спама
      $lastSent = $userModel->getLastPasswordResetSentTime($email);
      if ($lastSent && (time() - $lastSent) < 60) {
        return $this->json(['error' => 'Пожалуйста, подождите перед запросом нового кода'], 429);
      }

      $resetCode = random_int(100000, 999999);
      $resetToken = bin2hex(random_bytes(32));
      $expiresAt = time() + 3600;

      // Сохраняем код сброса
      $result = $userModel->savePasswordResetCode($email, $resetCode, $resetToken, $expiresAt);
      if (!$result) {
        return $this->json(['error' => 'Не удалось создать код сброса'], 500);
      }

      // Отправляем email
      $subject = "Сброс пароля для аккаунта";
      $message = "Для сброса пароля используйте код: $resetCode\n\nКод действителен 1 час.";
      $headers = "From: no-reply@gyper.kz\r\n";
      $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

      if (!mail($email, $subject, $message, $headers)) {
        error_log("Failed to send password reset email to: $email");
        return $this->json(['error' => 'Не удалось отправить код сброса'], 500);
      }

      $this->updateRateLimit($clientIp, 'forgot_password');

      return $this->json([
        'message' => 'Если email зарегистрирован, на него отправлена инструкция по сбросу пароля',
        'reset_token' => $resetToken
      ]);
    } catch (Exception $e) {
      error_log("ForgotPassword error: " . $e->getMessage());
      return $this->json(['error' => 'Внутренняя ошибка сервера'], 500);
    }
  }

  public function verifyResetCode($userType = 'user')
  {
    try {
      $data = jsonInput();
      if (!isset($data['email']) || !isset($data['code']) || !isset($data['reset_token'])) {
        return $this->json(['error' => 'Email, код и токен сброса обязательны'], 400);
      }

      $email = trim($data['email']);
      $code = trim($data['code']);
      $resetToken = trim($data['reset_token']);

      $clientIp = $this->getClientIp();
      if (!$this->checkRateLimit($clientIp, 'verify_reset_code', 5, 300)) {
        return $this->json(['error' => 'Слишком много попыток. Попробуйте позже.'], 429);
      }

      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $this->json(['error' => 'Неверный формат email'], 400);
      }

      if (!preg_match('/^\d{6}$/', $code)) {
        return $this->json(['error' => 'Неверный формат кода'], 400);
      }

      $userModel = new User();
      $isValid = $userModel->checkPasswordResetCode($email, $code, $resetToken);

      if (!$isValid) {
        $this->updateRateLimit($clientIp, 'verify_reset_code');
        return $this->json(['error' => 'Неверный, просроченный или уже использованный код'], 400);
      }

      $passwordResetToken = bin2hex(random_bytes(32));
      $result = $userModel->createPasswordResetToken($email, $passwordResetToken);

      if (!$result) {
        return $this->json(['error' => 'Ошибка создания токена сброса'], 500);
      }

      return $this->json([
        'message' => 'Код успешно подтвержден',
        'password_reset_token' => $passwordResetToken
      ]);
    } catch (Exception $e) {
      error_log("VerifyResetCode error: " . $e->getMessage());
      return $this->json(['error' => 'Внутренняя ошибка сервера'], 500);
    }
  }

  public function resetPassword($userType = 'user')
  {
    try {
      $data = jsonInput();

      $requiredFields = ['email', 'password', 'password_reset_token'];
      foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
          return $this->json(['error' => "Поле '$field' обязательно"], 400);
        }
      }

      $email = trim($data['email']);
      $password = $data['password'];
      $passwordResetToken = trim($data['password_reset_token']);

      $clientIp = $this->getClientIp();
      if (!$this->checkRateLimit($clientIp, 'reset_password', 3, 300)) {
        return $this->json(['error' => 'Слишком много попыток. Попробуйте позже.'], 429);
      }

      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $this->json(['error' => 'Неверный формат email'], 400);
      }

      if (!$this->isPasswordStrong($password)) {
        return $this->json(['error' => 'Пароль должен содержать минимум 8 символов, включая буквы и цифры'], 400);
      }

      $userModel = new User();

      $isTokenValid = $userModel->verifyPasswordResetToken($email, $passwordResetToken);
      if (!$isTokenValid) {
        $this->updateRateLimit($clientIp, 'reset_password');
        return $this->json(['error' => 'Невалидный или просроченный токен сброса'], 400);
      }

      $success = $userModel->updatePassword($email, $password);
      if (!$success) {
        return $this->json(['error' => 'Не удалось обновить пароль'], 500);
      }

      // Отзываем все активные сессии
      $user = $userModel->findByEmail($email);
      if ($user && method_exists($userModel, 'revokeAllUserTokens')) {
        $userModel->revokeAllUserTokens($user['id']);
      }

      // Очищаем данные сброса
      $userModel->clearPasswordResetData($email);

      $this->resetRateLimit($clientIp, 'reset_password');

      return $this->json(['message' => 'Пароль успешно изменен']);
    } catch (Exception $e) {
      error_log("ResetPassword error: " . $e->getMessage());
      return $this->json(['error' => 'Внутренняя ошибка сервера'], 500);
    }
  }

  // 👤 ПОЛУЧЕНИЕ ПРОФИЛЯ ПОЛЬЗОВАТЕЛЯ
  public function getProfile()
  {
    try {
      $userId = $this->getUserIdFromToken();
      if (!$userId) {
        return $this->json(['error' => 'Неавторизован'], 401);
      }

      $userModel = new User();
      $user = $userModel->findById($userId);

      if (!$user) {
        return $this->json(['error' => 'Пользователь не найден'], 404);
      }

      $userData = $this->prepareUserData($user, $user['role']);

      return $this->json([
        'success' => true,
        'data' => $userData
      ]);
    } catch (Exception $e) {
      error_log("GetProfile error: " . $e->getMessage());
      return $this->json(['error' => 'Внутренняя ошибка сервера'], 500);
    }
  }

  // ✏️ ОБНОВЛЕНИЕ ПРОФИЛЯ ПОЛЬЗОВАТЕЛЯ
  public function updateProfile()
  {
    try {
      $userId = $this->getUserIdFromToken();
      if (!$userId) {
        return $this->json(['error' => 'Неавторизован'], 401);
      }

      $data = jsonInput();
      $userModel = new User();

      // Валидация данных
      $updateData = [];

      if (isset($data['name'])) {
        $name = trim($data['name']);
        if (empty($name)) {
          return $this->json(['error' => 'Имя не может быть пустым'], 400);
        }
        if (strlen($name) < 2) {
          return $this->json(['error' => 'Имя должно содержать минимум 2 символа'], 400);
        }
        $updateData['name'] = $name;
      }

      if (isset($data['last_name'])) {
        $lastName = trim($data['last_name']);
        if (!empty($lastName) && strlen($lastName) < 2) {
          return $this->json(['error' => 'Фамилия должна содержать минимум 2 символа'], 400);
        }
        $updateData['last_name'] = $lastName;
      }

      if (isset($data['email'])) {
        $email = trim($data['email']);
        if (empty($email)) {
          return $this->json(['error' => 'Email не может быть пустым'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
          return $this->json(['error' => 'Неверный формат email'], 400);
        }
        $updateData['email'] = $email;
      }

      if (isset($data['patronymic'])) {
        $patronymic = trim($data['patronymic']);
        $updateData['patronymic'] = !empty($patronymic) ? $patronymic : null;
      }

      if (isset($data['phone'])) {
        $phone = trim($data['phone']);
        if (!empty($phone) && strlen($phone) < 10) {
          return $this->json(['error' => 'Номер телефона должен содержать минимум 10 символов'], 400);
        }
        $updateData['phone'] = !empty($phone) ? $phone : null;
      }

      if (isset($data['birth_date'])) {
        $birthDate = trim($data['birth_date']);
        if (!empty($birthDate)) {
          // Проверка формата даты
          $date = DateTime::createFromFormat('Y-m-d', $birthDate);
          if (!$date || $date->format('Y-m-d') !== $birthDate) {
            return $this->json(['error' => 'Неверный формат даты рождения (ожидается YYYY-MM-DD)'], 400);
          }
        }
        $updateData['birth_date'] = !empty($birthDate) ? $birthDate : null;
      }

      if (empty($updateData)) {
        return $this->json(['error' => 'Нет данных для обновления'], 400);
      }

      $result = $userModel->updateProfile($userId, $updateData);

      if (!$result) {
        return $this->json(['error' => 'Не удалось обновить профиль'], 500);
      }

      if (is_array($result) && isset($result['error'])) {
        return $this->json(['error' => $result['error']], 400);
      }

      $userData = $this->prepareUserData($result, $result['role']);

      return $this->json([
        'success' => true,
        'message' => 'Профиль успешно обновлен',
        'data' => $userData
      ]);
    } catch (Exception $e) {
      error_log("UpdateProfile error: " . $e->getMessage());
      return $this->json(['error' => 'Внутренняя ошибка сервера'], 500);
    }
  }

  // 🔐 ОБНОВЛЕНИЕ ПАРОЛЯ ПОЛЬЗОВАТЕЛЯ
  public function updatePassword()
  {
    try {
      $userId = $this->getUserIdFromToken();
      if (!$userId) {
        return $this->json(['error' => 'Неавторизован'], 401);
      }

      $data = jsonInput();

      if (!isset($data['current_password']) || !isset($data['new_password'])) {
        return $this->json(['error' => 'Текущий и новый пароль обязательны'], 400);
      }

      $currentPassword = $data['current_password'];
      $newPassword = $data['new_password'];

      // Валидация нового пароля
      if (strlen($newPassword) < 6) {
        return $this->json(['error' => 'Пароль должен содержать минимум 6 символов'], 400);
      }

      $userModel = new User();

      // Проверка текущего пароля
      if (!$userModel->verifyPassword($userId, $currentPassword)) {
        return $this->json(['error' => 'Неверный текущий пароль'], 400);
      }

      // Обновление пароля
      $result = $userModel->updatePasswordById($userId, $newPassword);

      if (!$result) {
        return $this->json(['error' => 'Не удалось обновить пароль'], 500);
      }

      return $this->json([
        'success' => true,
        'message' => 'Пароль успешно изменен'
      ]);
    } catch (Exception $e) {
      error_log("UpdatePassword error: " . $e->getMessage());
      return $this->json(['error' => 'Внутренняя ошибка сервера'], 500);
    }
  }

  // 🔍 ПОЛУЧЕНИЕ ID ПОЛЬЗОВАТЕЛЯ ИЗ ТОКЕНА
  private function getUserIdFromToken()
  {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);

    if (empty($token)) {
      return false;
    }

    return $this->verifyToken($token);
  }
}
