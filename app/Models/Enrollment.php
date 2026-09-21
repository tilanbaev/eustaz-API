<?php
class Enrollment
{
  protected $db;
  protected $table = 'enrollments';

  public function __construct()
  {
    $this->db = $GLOBALS['db'];
  }

  /**
   * Создание новой записи на курс
   */
  public function create($data)
  {
    try {
      $fields = [];
      $placeholders = [];
      $values = [];

      // Подготавливаем данные для вставки
      foreach ($data as $key => $value) {
        $fields[] = $key;
        $placeholders[] = '?';
        $values[] = $value;
      }

      $sql = "INSERT INTO {$this->table} (" . implode(', ', $fields) . ") 
                    VALUES (" . implode(', ', $placeholders) . ")";

      $stmt = $this->db->prepare($sql);

      if ($stmt->execute($values)) {
        return $this->db->lastInsertId();
      }

      return false;
    } catch (PDOException $e) {
      error_log("Enrollment create error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение записи по ID
   */
  public function findById($id)
  {
    try {
      $sql = "SELECT * FROM {$this->table} WHERE id = ?";
      $stmt = $this->db->prepare($sql);
      $stmt->execute([$id]);

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Enrollment findById error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение записи по пользователю и курсу
   */
  public function findByUserAndCourse($userId, $courseId)
  {
    try {
      $sql = "SELECT * FROM {$this->table} 
                    WHERE user_id = ? AND course_id = ? 
                    ORDER BY enrolled_at DESC 
                    LIMIT 1";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$userId, $courseId]);

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Enrollment findByUserAndCourse error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение всех записей пользователя
   */
  public function findByUserId($userId, $limit = null, $offset = 0)
  {
    try {
      $sql = "SELECT e.*, c.title as course_title, c.description as course_description,
                           c.preview_image, c.price, c.is_free, u.name as teacher_name
                    FROM {$this->table} e
                    INNER JOIN courses c ON e.course_id = c.id
                    INNER JOIN users u ON c.user_id = u.id
                    WHERE e.user_id = ? AND c.deleted_at IS NULL
                    ORDER BY e.enrolled_at DESC";

      if ($limit !== null) {
        $sql .= " LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $limit, $offset]);
      } else {
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
      }

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Enrollment findByUserId error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение всех записей на курс
   */
  public function findByCourseId($courseId, $limit = null, $offset = 0)
  {
    try {
      $sql = "SELECT e.*, u.name as student_name, u.email as student_email
                    FROM {$this->table} e
                    INNER JOIN users u ON e.user_id = u.id
                    WHERE e.course_id = ?
                    ORDER BY e.enrolled_at DESC";

      if ($limit !== null) {
        $sql .= " LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$courseId, $limit, $offset]);
      } else {
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$courseId]);
      }

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Enrollment findByCourseId error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение записей по статусу
   */
  public function findByStatus($status, $limit = null, $offset = 0)
  {
    try {
      $sql = "SELECT e.*, c.title as course_title, u.name as student_name
                    FROM {$this->table} e
                    INNER JOIN courses c ON e.course_id = c.id
                    INNER JOIN users u ON e.user_id = u.id
                    WHERE e.status = ? AND c.deleted_at IS NULL
                    ORDER BY e.enrolled_at DESC";

      if ($limit !== null) {
        $sql .= " LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$status, $limit, $offset]);
      } else {
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$status]);
      }

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Enrollment findByStatus error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение ожидающих подтверждения записей для учителя
   */
  public function findPendingByTeacher($teacherId, $limit = null, $offset = 0)
  {
    try {
      $sql = "SELECT e.*, c.title as course_title, c.price, 
                           u.name as student_name, u.email as student_email
                    FROM {$this->table} e
                    INNER JOIN courses c ON e.course_id = c.id
                    INNER JOIN users u ON e.user_id = u.id
                    WHERE c.user_id = ? AND e.status = 'pending' AND c.deleted_at IS NULL
                    ORDER BY e.enrolled_at DESC";

      if ($limit !== null) {
        $sql .= " LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$teacherId, $limit, $offset]);
      } else {
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$teacherId]);
      }

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Enrollment findPendingByTeacher error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Обновление записи
   */
  public function update($id, $data)
  {
    try {
      $fields = [];
      $values = [];

      foreach ($data as $key => $value) {
        $fields[] = "$key = ?";
        $values[] = $value;
      }

      $values[] = $id; // Для WHERE условия

      $sql = "UPDATE {$this->table} 
                    SET " . implode(', ', $fields) . " 
                    WHERE id = ?";

      $stmt = $this->db->prepare($sql);
      return $stmt->execute($values);
    } catch (PDOException $e) {
      error_log("Enrollment update error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Удаление записи
   */
  public function delete($id)
  {
    try {
      $sql = "DELETE FROM {$this->table} WHERE id = ?";
      $stmt = $this->db->prepare($sql);
      return $stmt->execute([$id]);
    } catch (PDOException $e) {
      error_log("Enrollment delete error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Удаление записи по пользователю и курсу
   */
  public function deleteByUserAndCourse($userId, $courseId)
  {
    try {
      $sql = "DELETE FROM {$this->table} 
                    WHERE user_id = ? AND course_id = ?";
      $stmt = $this->db->prepare($sql);
      return $stmt->execute([$userId, $courseId]);
    } catch (PDOException $e) {
      error_log("Enrollment deleteByUserAndCourse error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение количества записей на курс
   */
  public function countByCourse($courseId)
  {
    try {
      $sql = "SELECT COUNT(*) as count 
                    FROM {$this->table} 
                    WHERE course_id = ? AND status = 'approved'";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$courseId]);

      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      return $result['count'] ?? 0;
    } catch (PDOException $e) {
      error_log("Enrollment countByCourse error: " . $e->getMessage());
      return 0;
    }
  }

  /**
   * Совместимый метод: количество студентов на курсе (поддерживает опциональный статус)
   * Если статус не указан, считает записи со статусом 'approved' или 'active'.
   */
  public function countEnrolledStudents($courseId, $status = null)
  {
    try {
      if ($status === null) {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE course_id = ? AND (status = 'approved' OR status = 'active')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$courseId]);
      } else {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE course_id = ? AND status = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$courseId, $status]);
      }

      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      return (int)($result['count'] ?? 0);
    } catch (PDOException $e) {
      error_log("Enrollment countEnrolledStudents error: " . $e->getMessage());
      return 0;
    }
  }

  /**
   * Получение количества записей пользователя
   */
  public function countByUser($userId)
  {
    try {
      $sql = "SELECT COUNT(*) as count 
                    FROM {$this->table} e
                    INNER JOIN courses c ON e.course_id = c.id
                    WHERE e.user_id = ? AND c.deleted_at IS NULL AND e.status = 'approved'";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$userId]);

      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      return $result['count'] ?? 0;
    } catch (PDOException $e) {
      error_log("Enrollment countByUser error: " . $e->getMessage());
      return 0;
    }
  }

  /**
   * Получение количества ожидающих подтверждения записей для учителя
   */
  public function countPendingByTeacher($teacherId)
  {
    try {
      $sql = "SELECT COUNT(*) as count 
                    FROM {$this->table} e
                    INNER JOIN courses c ON e.course_id = c.id
                    WHERE c.user_id = ? AND e.status = 'pending' AND c.deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$teacherId]);

      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      return $result['count'] ?? 0;
    } catch (PDOException $e) {
      error_log("Enrollment countPendingByTeacher error: " . $e->getMessage());
      return 0;
    }
  }

  /**
   * Проверка, имеет ли пользователь доступ к курсу
   */
  public function hasAccess($userId, $courseId)
  {
    try {
      $sql = "SELECT e.* 
                    FROM {$this->table} e
                    INNER JOIN courses c ON e.course_id = c.id
                    WHERE e.user_id = ? AND e.course_id = ? 
                    AND e.status = 'approved' AND c.deleted_at IS NULL
                    LIMIT 1";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$userId, $courseId]);

      return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (PDOException $e) {
      error_log("Enrollment hasAccess error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение статистики по записям
   */
  public function getStats($courseId = null)
  {
    try {
      if ($courseId) {
        $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                        FROM {$this->table} 
                        WHERE course_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$courseId]);
      } else {
        $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                        FROM {$this->table}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
      }

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Enrollment getStats error: " . $e->getMessage());
      return ['total' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0];
    }
  }
}
