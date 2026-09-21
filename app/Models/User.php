<?php

class User extends Model
{
  public function findByEmail($email)
  {
    try {
      $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
      $stmt->execute([$email]);
      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("findByEmail PDO error: " . $e->getMessage());
      return false;
    }
  }

  public function findByPhone($phone)
  { 
    try {
      $stmt = $this->db->prepare("SELECT * FROM users WHERE phone = ?");
      $stmt->execute([$phone]);
      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("findByPhone PDO error: " . $e->getMessage());
      return false;
    }
  }

  public function create($name, $email, $password, $last_name = null, $patronymic = null, $birth_date = null, $phone = null, $role = 'user')
  {
    try {
      error_log("Creating user: $email");

      $hash = password_hash($password, PASSWORD_BCRYPT);
      if (!$hash) {
        error_log("Password hash failed");
        return false;
      }

      $token = $this->generateToken();
      error_log("Generated token: $token");

      // Обновленный запрос с новыми полями
      $stmt = $this->db->prepare("
                INSERT INTO users (name, email, password_hash, api_token, last_name, patronymic, birth_date, phone, created_at, role) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ");

      if (!$stmt) {
        error_log("Prepare failed: " . print_r($this->db->errorInfo(), true));
        return false;
      }

      $result = $stmt->execute([
        $name,
        $email,
        $hash,
        $token,
        $last_name,
        $patronymic,
        $birth_date,
        $phone,
        $role
      ]);

      if (!$result) {
        $errorInfo = $stmt->errorInfo();
        error_log("Execute failed: " . print_r($errorInfo, true));
        return false;
      }

      error_log("User inserted successfully, fetching created user...");
      $createdUser = $this->findByEmail($email);
      error_log("Created user: " . print_r($createdUser, true));

      return $createdUser;
    } catch (PDOException $e) {
      error_log("User::create PDOException: " . $e->getMessage());
      error_log("Error code: " . $e->getCode());
      return false;
    } catch (Exception $e) {
      error_log("User::create Exception: " . $e->getMessage());
      return false;
    }
  }

  // Исправленная функция генерации токена
  private function generateToken()
  {
    return bin2hex(random_bytes(32));
  }

  public function updateToken($id, $token)
  {
    try {
      $stmt = $this->db->prepare("UPDATE users SET api_token = ? WHERE id = ?");
      return $stmt->execute([$token, $id]);
    } catch (PDOException $e) {
      error_log("updateToken PDO error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * 📌 Сохраняет 6-значный код подтверждения email
   */
  public function saveVerificationCode($email, $code)
  {
    try {
      // REPLACE обновит код, если email уже есть
      $stmt = $this->db->prepare("
                REPLACE INTO verification_codes (email, code, created_at) 
                VALUES (?, ?, NOW())
            ");
      return $stmt->execute([$email, $code]);
    } catch (PDOException $e) {
      error_log("saveVerificationCode error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * 📌 Проверяет код (валидный 10 минут)
   */
  public function checkVerificationCode($email, $code)
  {
    try {
      // Логируем попытку
      $this->logVerificationAttempt($email, false);

      // Проверяем количество попыток
      $recentAttempts = $this->getRecentAttemptsCount($email, 10);
      if ($recentAttempts > 5) {
        error_log("Too many verification attempts for: $email");
        return false;
      }

      $stmt = $this->db->prepare("
                SELECT * FROM verification_codes 
                WHERE email = ? AND code = ? 
                AND created_at >= NOW() - INTERVAL 10 MINUTE
            ");
      $stmt->execute([$email, $code]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($row) {
        // ✅ Успешная проверка
        $this->logVerificationAttempt($email, true);

        // Удаляем код и помечаем email как верифицированный
        $del = $this->db->prepare("DELETE FROM verification_codes WHERE email = ?");
        $del->execute([$email]);

        $this->markEmailAsVerified($email);
        return true;
      }

      return false;
    } catch (PDOException $e) {
      error_log("checkVerificationCode error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * 📌 Получить время последней отправки кода
   */
  public function getLastCodeSentTime($email)
  {
    try {
      $stmt = $this->db->prepare("
                SELECT created_at FROM verification_codes 
                WHERE email = ? 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
      $stmt->execute([$email]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      return $row ? strtotime($row['created_at']) : null;
    } catch (PDOException $e) {
      error_log("getLastCodeSentTime error: " . $e->getMessage());
      return null;
    }
  }

  /**
   * 📌 Пометить email как верифицированный
   */
  public function markEmailAsVerified($email)
  {
    try {
      $stmt = $this->db->prepare("
                REPLACE INTO verified_emails (email, verified_at) 
                VALUES (?, NOW())
            ");
      return $stmt->execute([$email]);
    } catch (PDOException $e) {
      error_log("markEmailAsVerified error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * 📌 Проверить, верифицирован ли email
   */
  public function isEmailVerified($email)
  {
    try {
      $stmt = $this->db->prepare("
                SELECT * FROM verified_emails 
                WHERE email = ? 
                AND verified_at >= NOW() - INTERVAL 1 HOUR
            ");
      $stmt->execute([$email]);
      return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("isEmailVerified error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * 📌 Очистить верификационные данные
   */
  public function clearVerificationData($email)
  {
    try {
      // Удаляем коды верификации
      $stmt1 = $this->db->prepare("DELETE FROM verification_codes WHERE email = ?");
      $stmt1->execute([$email]);

      // Удаляем запись о верификации
      $stmt2 = $this->db->prepare("DELETE FROM verified_emails WHERE email = ?");
      $stmt2->execute([$email]);

      // Очищаем логи попыток
      $stmt3 = $this->db->prepare("DELETE FROM verification_attempts WHERE email = ?");
      $stmt3->execute([$email]);

      return true;
    } catch (PDOException $e) {
      error_log("clearVerificationData error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * 📌 Получить количество попыток ввода кода за последние минуты
   */
  public function getRecentAttemptsCount($email, $minutes = 10)
  {
    try {
      $stmt = $this->db->prepare("
                SELECT COUNT(*) as attempts FROM verification_attempts 
                WHERE email = ? AND created_at >= NOW() - INTERVAL ? MINUTE
            ");
      $stmt->execute([$email, $minutes]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      return $row ? (int)$row['attempts'] : 0;
    } catch (PDOException $e) {
      error_log("getRecentAttemptsCount error: " . $e->getMessage());
      return 0;
    }
  }

  /**
   * 📌 Записать попытку ввода кода
   */
  public function logVerificationAttempt($email, $success = false)
  {
    try {
      $stmt = $this->db->prepare("
                INSERT INTO verification_attempts (email, success, created_at) 
                VALUES (?, ?, NOW())
            ");
      return $stmt->execute([$email, (int)$success]);
    } catch (PDOException $e) {
      error_log("logVerificationAttempt error: " . $e->getMessage());
      return false;
    }
  }

  // 🔐 МЕТОДЫ ДЛЯ АВТОРИЗАЦИИ И JWT

  public function updateLastLogin($userId)
  {
    try {
      $stmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
      return $stmt->execute([$userId]);
    } catch (PDOException $e) {
      error_log("updateLastLogin PDO error: " . $e->getMessage());
      return false;
    }
  }

  public function saveRefreshToken($userId, $refreshToken, $expiresAt, $ipAddress = null)
  {
    try {
      $stmt = $this->db->prepare("
                INSERT INTO user_refresh_tokens (user_id, token, expires_at, ip_address) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), ip_address = VALUES(ip_address), created_at = NOW()
            ");
      return $stmt->execute([$userId, $refreshToken, $expiresAt, $ipAddress]);
    } catch (PDOException $e) {
      error_log("saveRefreshToken error: " . $e->getMessage());
      return false;
    }
  }

  public function isRefreshTokenValid($userId, $refreshToken, $ipAddress = null)
  {
    try {
      $sql = "
                SELECT id FROM user_refresh_tokens 
                WHERE user_id = ? AND token = ? AND expires_at > NOW()
            ";

      $params = [$userId, $refreshToken];

      // Проверка привязки к IP если указан
      if ($ipAddress) {
        $sql .= " AND ip_address = ?";
        $params[] = $ipAddress;
      }

      $stmt = $this->db->prepare($sql);
      $stmt->execute($params);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      return !empty($result);
    } catch (PDOException $e) {
      error_log("isRefreshTokenValid error: " . $e->getMessage());
      return false;
    }
  }

  public function updateRefreshToken($userId, $oldToken, $newToken, $expiresAt, $ipAddress = null)
  {
    try {
      $stmt = $this->db->prepare("
                UPDATE user_refresh_tokens 
                SET token = ?, expires_at = ?, ip_address = ?
                WHERE user_id = ? AND token = ?
            ");
      return $stmt->execute([$newToken, $expiresAt, $ipAddress, $userId, $oldToken]);
    } catch (PDOException $e) {
      error_log("updateRefreshToken error: " . $e->getMessage());
      return false;
    }
  }

  public function revokeRefreshToken($userId, $refreshToken)
  {
    try {
      $stmt = $this->db->prepare("
                DELETE FROM user_refresh_tokens 
                WHERE user_id = ? AND token = ?
            ");
      return $stmt->execute([$userId, $refreshToken]);
    } catch (PDOException $e) {
      error_log("revokeRefreshToken error: " . $e->getMessage());
      return false;
    }
  }

  public function revokeAllUserTokens($userId)
  {
    try {
      $stmt = $this->db->prepare("
                DELETE FROM user_refresh_tokens 
                WHERE user_id = ?
            ");
      return $stmt->execute([$userId]);
    } catch (PDOException $e) {
      error_log("revokeAllUserTokens error: " . $e->getMessage());
      return false;
    }
  }

  public function logLoginAttempt($email, $success, $ipAddress = null)
  {
    try {
      $stmt = $this->db->prepare("
                INSERT INTO login_attempts (email, success, ip_address, user_agent) 
                VALUES (?, ?, ?, ?)
            ");
      $ip = $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
      $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
      return $stmt->execute([$email, $success ? 1 : 0, $ip, $userAgent]);
    } catch (PDOException $e) {
      error_log("logLoginAttempt error: " . $e->getMessage());
      return false;
    }
  }

  public function findById($userId)
  {
    try {
      $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
      $stmt->execute([$userId]);
      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("findById PDO error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * 📌 Получить пользователя по email с проверкой верификации
   */
  public function findVerifiedByEmail($email)
  {
    try {
      $stmt = $this->db->prepare("
                SELECT u.* FROM users u
                WHERE u.email = ? 
            ");
      $stmt->execute([$email]);
      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("findVerifiedByEmail PDO error: " . $e->getMessage());
      return false;
    }
  }

  // 🔒 НОВЫЕ МЕТОДЫ ДЛЯ RATE LIMITING И БЕЗОПАСНОСТИ

  /**
   * Rate limiting через базу данных
   */
  public function getRateLimitCount($identifier, $action, $timeWindow)
  {
    try {
      $stmt = $this->db->prepare("
                SELECT COUNT(*) as attempts 
                FROM rate_limits 
                WHERE identifier = ? AND action = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
      $stmt->execute([$identifier, $action, $timeWindow]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      return $result['attempts'] ?? 0;
    } catch (PDOException $e) {
      error_log("getRateLimitCount error: " . $e->getMessage());
      return 0;
    }
  }

  public function incrementRateLimit($identifier, $action)
  {
    try {
      $stmt = $this->db->prepare("
                INSERT INTO rate_limits (identifier, action, created_at) 
                VALUES (?, ?, NOW())
            ");
      return $stmt->execute([$identifier, $action]);
    } catch (PDOException $e) {
      error_log("incrementRateLimit error: " . $e->getMessage());
      return false;
    }
  }

  public function clearRateLimit($identifier, $action)
  {
    try {
      $stmt = $this->db->prepare("
                DELETE FROM rate_limits 
                WHERE identifier = ? AND action = ?
            ");
      return $stmt->execute([$identifier, $action]);
    } catch (PDOException $e) {
      error_log("clearRateLimit error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверка частоты успешных логинов
   */
  public function getRecentLoginCount($userId, $timeWindow)
  {
    try {
      $stmt = $this->db->prepare("
                SELECT COUNT(*) as login_count 
                FROM login_attempts 
                WHERE user_id = ? AND success = 1 AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
      $stmt->execute([$userId, $timeWindow]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      return $result['login_count'] ?? 0;
    } catch (PDOException $e) {
      error_log("getRecentLoginCount error: " . $e->getMessage());
      return 0;
    }
  }

  /**
   * Очистка старых записей rate limiting
   */
  public function cleanupRateLimits($olderThanHours = 24)
  {
    try {
      $stmt = $this->db->prepare("
                DELETE FROM rate_limits 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
      return $stmt->execute([$olderThanHours]);
    } catch (PDOException $e) {
      error_log("cleanupRateLimits error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Очистка старых логов
   */
  public function cleanupOldLogs($olderThanDays = 30)
  {
    try {
      // Очистка логов верификации
      $stmt1 = $this->db->prepare("
                DELETE FROM verification_attempts 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
      $stmt1->execute([$olderThanDays]);

      // Очистка логов логинов
      $stmt2 = $this->db->prepare("
                DELETE FROM login_attempts 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
      $stmt2->execute([$olderThanDays]);

      // Очистка старых кодов верификации
      $stmt3 = $this->db->prepare("
                DELETE FROM verification_codes 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
            ");
      $stmt3->execute();

      return true;
    } catch (PDOException $e) {
      error_log("cleanupOldLogs error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Блокировка пользователя при подозрительной активности
   */
  public function blockUser($userId, $reason = 'Suspicious activity')
  {
    try {
      $stmt = $this->db->prepare("
                UPDATE users 
                SET is_blocked = 1, blocked_at = NOW(), block_reason = ?
                WHERE id = ?
            ");
      return $stmt->execute([$reason, $userId]);
    } catch (PDOException $e) {
      error_log("blockUser error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверка количества неудачных попыток входа
   */
  public function getFailedLoginCount($email, $timeWindow = 900) // 15 минут
  {
    try {
      $stmt = $this->db->prepare("
                SELECT COUNT(*) as failed_count 
                FROM login_attempts 
                WHERE email = ? AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
      $stmt->execute([$email, $timeWindow]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      return $result['failed_count'] ?? 0;
    } catch (PDOException $e) {
      error_log("getFailedLoginCount error: " . $e->getMessage());
      return 0;
    }
  }
  public function findByEmailAndRole($email, $role)
  {
    try {
      $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ? AND role = ?");
      $stmt->execute([$email, $role]);
      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("findByEmailAndRole PDO error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Удаление пользователя (для отката регистрации)
   */
  public function deleteUser($userId)
  {
    try {
      $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
      return $stmt->execute([$userId]);
    } catch (PDOException $e) {
      error_log("deleteUser error: " . $e->getMessage());
      return false;
    }
  }

  // Методы для восстановления пароля (аналогичные Teacher)
  public function savePasswordResetCode($email, $code, $token, $expiresAt)
  {
    try {
      $stmt = $this->db->prepare("
      INSERT INTO password_reset_codes (email, code, reset_token, expires_at, created_at) 
      VALUES (?, ?, ?, FROM_UNIXTIME(?), NOW())
      ON DUPLICATE KEY UPDATE 
          code = VALUES(code), 
          reset_token = VALUES(reset_token), 
          expires_at = VALUES(expires_at),
          created_at = NOW()
    ");
      return $stmt->execute([$email, $code, $token, $expiresAt]);
    } catch (PDOException $e) {
      error_log("savePasswordResetCode error: " . $e->getMessage());
      return false;
    }
  }

  public function checkPasswordResetCode($email, $code, $resetToken)
  {
    try {
      $this->logPasswordResetAttempt($email, false);

      $recentAttempts = $this->getRecentPasswordResetAttemptsCount($email, 10);
      if ($recentAttempts > 5) {
        error_log("Too many password reset attempts for: $email");
        return false;
      }

      $stmt = $this->db->prepare("
      SELECT id FROM password_reset_codes 
      WHERE email = ? 
      AND code = ? 
      AND reset_token = ?
      AND expires_at > NOW()
      AND used = 0
    ");
      $stmt->execute([$email, $code, $resetToken]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($row) {
        $updateStmt = $this->db->prepare("UPDATE password_reset_codes SET used = 1 WHERE id = ?");
        $updateStmt->execute([$row['id']]);

        $this->logPasswordResetAttempt($email, true);
        return true;
      }

      return false;
    } catch (PDOException $e) {
      error_log("checkPasswordResetCode error: " . $e->getMessage());
      return false;
    }
  }

  public function createPasswordResetToken($email, $token)
  {
    try {
      $expiresAt = time() + 1800;

      $stmt = $this->db->prepare("
      INSERT INTO password_reset_tokens (email, token, expires_at, created_at) 
      VALUES (?, ?, FROM_UNIXTIME(?), NOW())
      ON DUPLICATE KEY UPDATE 
          token = VALUES(token), 
          expires_at = VALUES(expires_at),
          created_at = NOW()
    ");
      return $stmt->execute([$email, $token, $expiresAt]);
    } catch (PDOException $e) {
      error_log("createPasswordResetToken error: " . $e->getMessage());
      return false;
    }
  }

  public function verifyPasswordResetToken($email, $token)
  {
    try {
      $stmt = $this->db->prepare("
      SELECT id FROM password_reset_tokens 
      WHERE email = ? 
      AND token = ?
      AND expires_at > NOW()
      AND used = 0
    ");
      $stmt->execute([$email, $token]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($row) {
        $updateStmt = $this->db->prepare("UPDATE password_reset_tokens SET used = 1 WHERE id = ?");
        $updateStmt->execute([$row['id']]);
        return true;
      }

      return false;
    } catch (PDOException $e) {
      error_log("verifyPasswordResetToken error: " . $e->getMessage());
      return false;
    }
  }

  public function updatePassword($email, $newPassword)
  {
    try {
      $hash = password_hash($newPassword, PASSWORD_BCRYPT);
      if (!$hash) {
        error_log("Password hash failed for email: $email");
        return false;
      }

      $stmt = $this->db->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
      return $stmt->execute([$hash, $email]);
    } catch (PDOException $e) {
      error_log("updatePassword error: " . $e->getMessage());
      return false;
    }
  }

  public function clearPasswordResetData($email)
  {
    try {
      $stmt1 = $this->db->prepare("DELETE FROM password_reset_codes WHERE email = ?");
      $stmt1->execute([$email]);

      $stmt2 = $this->db->prepare("DELETE FROM password_reset_tokens WHERE email = ?");
      $stmt2->execute([$email]);

      $stmt3 = $this->db->prepare("DELETE FROM password_reset_attempts WHERE email = ?");
      $stmt3->execute([$email]);

      return true;
    } catch (PDOException $e) {
      error_log("clearPasswordResetData error: " . $e->getMessage());
      return false;
    }
  }

  public function getLastPasswordResetSentTime($email)
  {
    try {
      $stmt = $this->db->prepare("
      SELECT created_at FROM password_reset_codes 
      WHERE email = ? 
      ORDER BY created_at DESC 
      LIMIT 1
    ");
      $stmt->execute([$email]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      return $row ? strtotime($row['created_at']) : null;
    } catch (PDOException $e) {
      error_log("getLastPasswordResetSentTime error: " . $e->getMessage());
      return null;
    }
  }

  public function logPasswordResetAttempt($email, $success = false, $ipAddress = null)
  {
    try {
      $stmt = $this->db->prepare("
      INSERT INTO password_reset_attempts (email, success, ip_address, user_agent) 
      VALUES (?, ?, ?, ?)
    ");
      $ip = $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
      $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
      return $stmt->execute([$email, $success ? 1 : 0, $ip, $userAgent]);
    } catch (PDOException $e) {
      error_log("logPasswordResetAttempt error: " . $e->getMessage());
      return false;
    }
  }

  public function getRecentPasswordResetAttemptsCount($email, $minutes = 10)
  {
    try {
      $stmt = $this->db->prepare("
      SELECT COUNT(*) as attempts FROM password_reset_attempts 
      WHERE email = ? AND created_at >= NOW() - INTERVAL ? MINUTE
    ");
      $stmt->execute([$email, $minutes]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      return $row ? (int)$row['attempts'] : 0;
    } catch (PDOException $e) {
      error_log("getRecentPasswordResetAttemptsCount error: " . $e->getMessage());
      return 0;
    }
  }

  /**
   * Обновление профиля пользователя
   */
  public function updateProfile($userId, $data)
  {
    try {
      $allowedFields = ['name', 'last_name', 'patronymic', 'email', 'phone', 'birth_date'];
      $updateFields = [];
      $values = [];

      foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
          $updateFields[] = "$field = ?";
          // Для birth_date и phone не обрезаем пробелы, для остальных - обрезаем
          if ($field === 'birth_date' || $field === 'phone') {
            $values[] = $data[$field] !== null ? trim($data[$field]) : null;
          } else {
            $values[] = trim($data[$field]);
          }
        }
      }

      if (empty($updateFields)) {
        return false;
      }

      // Проверка уникальности email, если он обновляется
      if (isset($data['email'])) {
        $existing = $this->findByEmail($data['email']);
        if ($existing && $existing['id'] != $userId) {
          return ['error' => 'Email уже используется другим пользователем'];
        }
      }

      // Проверка уникальности телефона, если он обновляется
      if (isset($data['phone']) && !empty($data['phone'])) {
        $existing = $this->findByPhone($data['phone']);
        if ($existing && $existing['id'] != $userId) {
          return ['error' => 'Номер телефона уже используется другим пользователем'];
        }
      }

      $values[] = $userId;
      $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
      
      $stmt = $this->db->prepare($sql);
      $result = $stmt->execute($values);

      if ($result) {
        return $this->findById($userId);
      }

      return false;
    } catch (PDOException $e) {
      error_log("updateProfile error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Обновление пароля пользователя по ID
   */
  public function updatePasswordById($userId, $newPassword)
  {
    try {
      $hash = password_hash($newPassword, PASSWORD_BCRYPT);
      if (!$hash) {
        error_log("Password hash failed for user ID: $userId");
        return false;
      }

      $stmt = $this->db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
      return $stmt->execute([$hash, $userId]);
    } catch (PDOException $e) {
      error_log("updatePasswordById error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверка текущего пароля пользователя
   */
  public function verifyPassword($userId, $password)
  {
    try {
      $user = $this->findById($userId);
      if (!$user || !isset($user['password_hash'])) {
        return false;
      }

      return password_verify($password, $user['password_hash']);
    } catch (PDOException $e) {
      error_log("verifyPassword error: " . $e->getMessage());
      return false;
    }
  }
}
