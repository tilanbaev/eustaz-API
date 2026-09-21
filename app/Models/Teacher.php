<?php

class Teacher extends Model
{
  // 📌 Основные методы для работы с преподавателями

  public function findByEmail($email)
  {
    try {
      $stmt = $this->db->prepare("
        SELECT t.*, u.email, u.name, u.last_name, u.patronymic, u.birth_date, u.phone 
        FROM teachers t 
        LEFT JOIN users u ON t.user_id = u.id 
        WHERE u.email = ?
      ");
      $stmt->execute([$email]);
      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Teacher findByEmail PDO error: " . $e->getMessage());
      return false;
    }
  }

  public function findByPhone($phone)
  {
    try {
      $stmt = $this->db->prepare("
        SELECT t.*, u.email, u.name, u.last_name, u.patronymic, u.birth_date, u.phone 
        FROM teachers t 
        LEFT JOIN users u ON t.user_id = u.id 
        WHERE u.phone = ?
      ");
      $stmt->execute([$phone]);
      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Teacher findByPhone PDO error: " . $e->getMessage());
      return false;
    }
  }

  public function findById($teacherId)
  {
    try {
      $stmt = $this->db->prepare("
        SELECT t.*, u.email, u.name, u.last_name, u.patronymic, u.birth_date, u.phone 
        FROM teachers t 
        LEFT JOIN users u ON t.user_id = u.id 
        WHERE t.id = ?
      ");
      $stmt->execute([$teacherId]);
      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Teacher findById PDO error: " . $e->getMessage());
      return false;
    }
  }

  // 📌 Создание преподавателя (основной метод)
  public function create(
    $name,
    $email,
    $password,
    $subject,
    $experience,
    $education,
    $last_name = null,
    $patronymic = null,
    $birth_date = null,
    $phone = null,
    $specialization = null,
    $achievements = null
  ) {
    $this->db->beginTransaction();

    try {
      error_log("Creating teacher: $email");

      // 1. Создаем пользователя
      $hash = password_hash($password, PASSWORD_BCRYPT);
      if (!$hash) {
        error_log("Password hash failed");
        $this->db->rollBack();
        return false;
      }

      $token = $this->generateToken();

      // Создаем запись в users
      $userStmt = $this->db->prepare("
        INSERT INTO users (name, email, password_hash, api_token, last_name, patronymic, birth_date, phone, created_at, role) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'teacher')
      ");

      if (!$userStmt->execute([$name, $email, $hash, $token, $last_name, $patronymic, $birth_date, $phone])) {
        $errorInfo = $userStmt->errorInfo();
        error_log("User creation failed: " . print_r($errorInfo, true));
        $this->db->rollBack();
        return false;
      }

      $userId = $this->db->lastInsertId();
      error_log("User created with ID: $userId");

      // 2. Создаем запись преподавателя
      $teacherStmt = $this->db->prepare("
        INSERT INTO teachers (user_id, subject, experience, education, specialization, achievements, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())
      ");

      if (!$teacherStmt->execute([$userId, $subject, $experience, $education, $specialization, $achievements])) {
        $errorInfo = $teacherStmt->errorInfo();
        error_log("Teacher creation failed: " . print_r($errorInfo, true));
        $this->db->rollBack();
        return false;
      }

      $teacherId = $this->db->lastInsertId();
      error_log("Teacher created with ID: $teacherId");

      // 3. Получаем полные данные преподавателя
      $teacher = $this->findById($teacherId);

      if (!$teacher) {
        error_log("Failed to fetch created teacher");
        $this->db->rollBack();
        return false;
      }

      $this->db->commit();
      error_log("Teacher created successfully: " . print_r($teacher, true));

      return $teacher;
    } catch (PDOException $e) {
      $this->db->rollBack();
      error_log("Teacher::create PDOException: " . $e->getMessage());
      error_log("Error code: " . $e->getCode());
      return false;
    } catch (Exception $e) {
      $this->db->rollBack();
      error_log("Teacher::create Exception: " . $e->getMessage());
      return false;
    }
  }

  // 📌 Верификация email (общие методы)
  public function saveVerificationCode($email, $code)
  {
    try {
      $stmt = $this->db->prepare("
        REPLACE INTO verification_codes (email, code, created_at) 
        VALUES (?, ?, NOW())
      ");
      return $stmt->execute([$email, $code]);
    } catch (PDOException $e) {
      error_log("Teacher saveVerificationCode error: " . $e->getMessage());
      return false;
    }
  }

  public function checkVerificationCode($email, $code)
  {
    try {
      $this->logVerificationAttempt($email, false);

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
        $this->logVerificationAttempt($email, true);

        $del = $this->db->prepare("DELETE FROM verification_codes WHERE email = ?");
        $del->execute([$email]);

        $this->markEmailAsVerified($email);
        return true;
      }

      return false;
    } catch (PDOException $e) {
      error_log("Teacher checkVerificationCode error: " . $e->getMessage());
      return false;
    }
  }

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
      error_log("Teacher getLastCodeSentTime error: " . $e->getMessage());
      return null;
    }
  }

  public function markEmailAsVerified($email)
  {
    try {
      $stmt = $this->db->prepare("
        REPLACE INTO verified_emails (email, verified_at) 
        VALUES (?, NOW())
      ");
      return $stmt->execute([$email]);
    } catch (PDOException $e) {
      error_log("Teacher markEmailAsVerified error: " . $e->getMessage());
      return false;
    }
  }

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
      error_log("Teacher isEmailVerified error: " . $e->getMessage());
      return false;
    }
  }

  public function clearVerificationData($email)
  {
    try {
      $stmt1 = $this->db->prepare("DELETE FROM verification_codes WHERE email = ?");
      $stmt1->execute([$email]);

      $stmt2 = $this->db->prepare("DELETE FROM verified_emails WHERE email = ?");
      $stmt2->execute([$email]);

      $stmt3 = $this->db->prepare("DELETE FROM verification_attempts WHERE email = ?");
      $stmt3->execute([$email]);

      return true;
    } catch (PDOException $e) {
      error_log("Teacher clearVerificationData error: " . $e->getMessage());
      return false;
    }
  }

  // 📌 Методы для логирования верификации
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
      error_log("Teacher getRecentAttemptsCount error: " . $e->getMessage());
      return 0;
    }
  }

  public function logVerificationAttempt($email, $success = false)
  {
    try {
      $stmt = $this->db->prepare("
        INSERT INTO verification_attempts (email, success, created_at) 
        VALUES (?, ?, NOW())
      ");
      return $stmt->execute([$email, (int)$success]);
    } catch (PDOException $e) {
      error_log("Teacher logVerificationAttempt error: " . $e->getMessage());
      return false;
    }
  }

  // 🔐 МЕТОДЫ ДЛЯ АВТОРИЗАЦИИ И JWT

  public function updateLastLogin($teacherId)
  {
    try {
      // Получаем user_id из teacher_id
      $userId = $this->getUserIdByTeacherId($teacherId);
      if (!$userId) {
        error_log("Cannot find user_id for teacher_id: $teacherId");
        return false;
      }

      $stmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
      return $stmt->execute([$userId]);
    } catch (PDOException $e) {
      error_log("Teacher updateLastLogin PDO error: " . $e->getMessage());
      return false;
    }
  }

  public function saveRefreshToken($teacherId, $refreshToken, $expiresAt, $ipAddress = null)
  {
    try {
      $userId = $this->getUserIdByTeacherId($teacherId);
      if (!$userId) {
        error_log("Cannot find user_id for teacher_id: $teacherId");
        return false;
      }

      $stmt = $this->db->prepare("
        INSERT INTO user_refresh_tokens (user_id, token, expires_at, ip_address) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), ip_address = VALUES(ip_address), created_at = NOW()
      ");
      return $stmt->execute([$userId, $refreshToken, $expiresAt, $ipAddress]);
    } catch (PDOException $e) {
      error_log("Teacher saveRefreshToken error: " . $e->getMessage());
      return false;
    }
  }

  public function isRefreshTokenValid($teacherId, $refreshToken, $ipAddress = null)
  {
    try {
      $userId = $this->getUserIdByTeacherId($teacherId);
      if (!$userId) {
        return false;
      }

      $sql = "
        SELECT id FROM user_refresh_tokens 
        WHERE user_id = ? AND token = ? AND expires_at > NOW()
      ";

      $params = [$userId, $refreshToken];

      if ($ipAddress) {
        $sql .= " AND ip_address = ?";
        $params[] = $ipAddress;
      }

      $stmt = $this->db->prepare($sql);
      $stmt->execute($params);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      return !empty($result);
    } catch (PDOException $e) {
      error_log("Teacher isRefreshTokenValid error: " . $e->getMessage());
      return false;
    }
  }

  public function updateRefreshToken($teacherId, $oldToken, $newToken, $expiresAt, $ipAddress = null)
  {
    try {
      $userId = $this->getUserIdByTeacherId($teacherId);
      if (!$userId) {
        return false;
      }

      $stmt = $this->db->prepare("
        UPDATE user_refresh_tokens 
        SET token = ?, expires_at = ?, ip_address = ?
        WHERE user_id = ? AND token = ?
      ");
      return $stmt->execute([$newToken, $expiresAt, $ipAddress, $userId, $oldToken]);
    } catch (PDOException $e) {
      error_log("Teacher updateRefreshToken error: " . $e->getMessage());
      return false;
    }
  }

  public function revokeRefreshToken($teacherId, $refreshToken)
  {
    try {
      $userId = $this->getUserIdByTeacherId($teacherId);
      if (!$userId) {
        return false;
      }

      $stmt = $this->db->prepare("
        DELETE FROM user_refresh_tokens 
        WHERE user_id = ? AND token = ?
      ");
      return $stmt->execute([$userId, $refreshToken]);
    } catch (PDOException $e) {
      error_log("Teacher revokeRefreshToken error: " . $e->getMessage());
      return false;
    }
  }

  public function revokeAllTeacherTokens($teacherId)
  {
    try {
      $userId = $this->getUserIdByTeacherId($teacherId);
      if (!$userId) {
        return false;
      }

      $stmt = $this->db->prepare("
        DELETE FROM user_refresh_tokens 
        WHERE user_id = ?
      ");
      return $stmt->execute([$userId]);
    } catch (PDOException $e) {
      error_log("Teacher revokeAllTeacherTokens error: " . $e->getMessage());
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
      error_log("Teacher logLoginAttempt error: " . $e->getMessage());
      return false;
    }
  }

  // 🔒 МЕТОДЫ ДЛЯ RATE LIMITING И БЕЗОПАСНОСТИ

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
      error_log("Teacher getRateLimitCount error: " . $e->getMessage());
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
      error_log("Teacher incrementRateLimit error: " . $e->getMessage());
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
      error_log("Teacher clearRateLimit error: " . $e->getMessage());
      return false;
    }
  }

  public function getRecentLoginCount($teacherId, $timeWindow)
  {
    try {
      $userId = $this->getUserIdByTeacherId($teacherId);
      if (!$userId) {
        return 0;
      }

      $stmt = $this->db->prepare("
        SELECT COUNT(*) as login_count 
        FROM login_attempts 
        WHERE user_id = ? AND success = 1 AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
      ");
      $stmt->execute([$userId, $timeWindow]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      return $result['login_count'] ?? 0;
    } catch (PDOException $e) {
      error_log("Teacher getRecentLoginCount error: " . $e->getMessage());
      return 0;
    }
  }

  // 📌 Вспомогательные методы

  private function generateToken()
  {
    return bin2hex(random_bytes(32));
  }

  private function getUserIdByTeacherId($teacherId)
  {
    try {
      $stmt = $this->db->prepare("SELECT user_id FROM teachers WHERE id = ?");
      $stmt->execute([$teacherId]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      return $result ? $result['user_id'] : null;
    } catch (PDOException $e) {
      error_log("Teacher getUserIdByTeacherId error: " . $e->getMessage());
      return null;
    }
  }

  public function updateToken($id, $token)
  {
    try {
      $stmt = $this->db->prepare("UPDATE users SET api_token = ? WHERE id = ?");
      return $stmt->execute([$token, $id]);
    } catch (PDOException $e) {
      error_log("Teacher updateToken PDO error: " . $e->getMessage());
      return false;
    }
  }

  // 📌 Методы для работы с данными преподавателя

  public function updateTeacherProfile($teacherId, $data)
  {
    try {
      $allowedFields = ['subject', 'experience', 'education', 'specialization', 'achievements'];
      $updates = [];
      $params = [];

      foreach ($data as $field => $value) {
        if (in_array($field, $allowedFields)) {
          $updates[] = "$field = ?";
          $params[] = $value;
        }
      }

      if (empty($updates)) {
        return true; // Нет полей для обновления
      }

      $params[] = $teacherId;

      $sql = "UPDATE teachers SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?";
      $stmt = $this->db->prepare($sql);

      return $stmt->execute($params);
    } catch (PDOException $e) {
      error_log("Teacher updateTeacherProfile error: " . $e->getMessage());
      return false;
    }
  }

  public function getAllTeachers($limit = 50, $offset = 0)
  {
    try {
      $stmt = $this->db->prepare("
        SELECT t.*, u.email, u.name, u.last_name, u.patronymic, u.phone 
        FROM teachers t 
        LEFT JOIN users u ON t.user_id = u.id 
        WHERE t.status = 'active'
        ORDER BY t.created_at DESC 
        LIMIT ? OFFSET ?
      ");
      $stmt->bindValue(1, $limit, PDO::PARAM_INT);
      $stmt->bindValue(2, $offset, PDO::PARAM_INT);
      $stmt->execute();

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Teacher getAllTeachers error: " . $e->getMessage());
      return [];
    }
  }
  // 📧 МЕТОДЫ ДЛЯ ВОССТАНОВЛЕНИЯ ПАРОЛЯ

  /**
   * Сохраняет код сброса пароля
   */
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
      error_log("Teacher savePasswordResetCode error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверяет код сброса пароля
   */
  public function checkPasswordResetCode($email, $code, $resetToken)
  {
    try {
      // Логируем попытку проверки
      $this->logPasswordResetAttempt($email, false);

      // Проверяем количество недавних попыток
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
        // Помечаем код как использованный
        $updateStmt = $this->db->prepare("
                UPDATE password_reset_codes SET used = 1 WHERE id = ?
            ");
        $updateStmt->execute([$row['id']]);

        $this->logPasswordResetAttempt($email, true);
        return true;
      }

      return false;
    } catch (PDOException $e) {
      error_log("Teacher checkPasswordResetCode error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Создает токен для сброса пароля
   */
  public function createPasswordResetToken($email, $token)
  {
    try {
      $expiresAt = time() + 1800; // 30 минут

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
      error_log("Teacher createPasswordResetToken error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверяет токен сброса пароля
   */
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
        // Помечаем токен как использованный
        $updateStmt = $this->db->prepare("
                UPDATE password_reset_tokens SET used = 1 WHERE id = ?
            ");
        $updateStmt->execute([$row['id']]);
        return true;
      }

      return false;
    } catch (PDOException $e) {
      error_log("Teacher verifyPasswordResetToken error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Обновляет пароль преподавателя
   */
  public function updatePassword($email, $newPassword)
  {
    try {
      $hash = password_hash($newPassword, PASSWORD_BCRYPT);
      if (!$hash) {
        error_log("Password hash failed for email: $email");
        return false;
      }

      // Находим user_id по email
      $userStmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
      $userStmt->execute([$email]);
      $user = $userStmt->fetch(PDO::FETCH_ASSOC);

      if (!$user) {
        error_log("User not found for email: $email");
        return false;
      }

      $stmt = $this->db->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
      return $stmt->execute([$hash, $email]);
    } catch (PDOException $e) {
      error_log("Teacher updatePassword error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Очищает данные сброса пароля
   */
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
      error_log("Teacher clearPasswordResetData error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получает время последней отправки сброса пароля
   */
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
      error_log("Teacher getLastPasswordResetSentTime error: " . $e->getMessage());
      return null;
    }
  }

  /**
   * Логирует попытку сброса пароля
   */
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
      error_log("Teacher logPasswordResetAttempt error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получает количество недавних попыток сброса пароля
   */
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
      error_log("Teacher getRecentPasswordResetAttemptsCount error: " . $e->getMessage());
      return 0;
    }
  }
  /**
   * Создание профиля преподавателя (для использования с User)
   */
  public function createTeacherProfile($userId, $subject, $experience, $education, $specialization = null, $achievements = null)
  {
    try {
      $stmt = $this->db->prepare("
      INSERT INTO teachers (user_id, subject, experience, education, specialization, achievements, created_at) 
      VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

      $result = $stmt->execute([$userId, $subject, $experience, $education, $specialization, $achievements]);

      if (!$result) {
        $errorInfo = $stmt->errorInfo();
        error_log("Teacher profile creation failed: " . print_r($errorInfo, true));
        return false;
      }

      $teacherId = $this->db->lastInsertId();
      return $this->findByUserId($userId);
    } catch (PDOException $e) {
      error_log("createTeacherProfile error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Поиск преподавателя по user_id
   */
  public function findByUserId($userId)
  {
    try {
      $stmt = $this->db->prepare("
      SELECT t.*, u.email, u.name, u.last_name, u.patronymic, u.birth_date, u.phone 
      FROM teachers t 
      LEFT JOIN users u ON t.user_id = u.id 
      WHERE t.user_id = ?
    ");
      $stmt->execute([$userId]);
      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("findByUserId error: " . $e->getMessage());
      return false;
    }
  }
}
