<?php
class Course
{
  protected $db;
  protected $table = 'courses';

  public function __construct()
  {
    $this->db = $GLOBALS['db'];
  }

  /**
   * Создание нового курса
   */
  public function create($data)
  {
    try {
      $fields = [];
      $placeholders = [];
      $values = [];

      // Подготавливаем данные для вставки
      foreach ($data as $key => $value) {
        if ($key === 'is_free') {
          // Конвертируем boolean в integer для MySQL
          $fields[] = $key;
          $placeholders[] = '?';
          $values[] = $value ? 1 : 0;
        } else {
          $fields[] = $key;
          $placeholders[] = '?';
          $values[] = $value;
        }
      }

      // Добавляем timestamp создания
      $fields[] = 'created_at';
      $placeholders[] = '?';
      $values[] = date('Y-m-d H:i:s');

      $sql = "INSERT INTO {$this->table} (" . implode(', ', $fields) . ") 
                    VALUES (" . implode(', ', $placeholders) . ")";

      $stmt = $this->db->prepare($sql);

      if ($stmt->execute($values)) {
        return $this->db->lastInsertId();
      }

      return false;
    } catch (PDOException $e) {
      error_log("Course create error: " . $e->getMessage());
      return false;
    }
  }
/**
     * Find all courses by teacher ID
     */
    public function findByTeacher($teacherId)
    {
        $sql = "SELECT * FROM courses WHERE user_id = ? ORDER BY created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

  /**
   * Получение курса по ID
   */
  public function findById($id)
  {
    try {
      $sql = "SELECT *, 
                    (price = 0 OR is_free = 1) as is_free 
                    FROM {$this->table} 
                    WHERE id = ? AND deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$id]);

      $course = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($course) {
        // Конвертируем is_free в boolean
        $course['is_free'] = (bool)$course['is_free'];
      }

      return $course;
    } catch (PDOException $e) {
      error_log("Course findById error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение всех курсов
   */
  public function findAll($limit = null, $offset = 0)
  {
    try {
      $sql = "SELECT *, 
                    (price = 0 OR is_free = 1) as is_free 
                    FROM {$this->table} 
                    WHERE deleted_at IS NULL 
                    ORDER BY created_at DESC";

      if ($limit !== null) {
        $sql .= " LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit, $offset]);
      } else {
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
      }

      $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Конвертируем is_free в boolean для всех курсов
      foreach ($courses as &$course) {
        $course['is_free'] = (bool)$course['is_free'];
      }

      return $courses;
    } catch (PDOException $e) {
      error_log("Course findAll error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение курсов по user_id
   */
  public function findByUserId($userId, $limit = null, $offset = 0)
  {
    try {
      $sql = "SELECT *, 
                    (price = 0 OR is_free = 1) as is_free 
                    FROM {$this->table} 
                    WHERE user_id = ? AND deleted_at IS NULL 
                    ORDER BY created_at DESC";

      if ($limit !== null) {
        $sql .= " LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $limit, $offset]);
      } else {
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
      }

      $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

      foreach ($courses as &$course) {
        $course['is_free'] = (bool)$course['is_free'];
      }

      return $courses;
    } catch (PDOException $e) {
      error_log("Course findByUserId error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение бесплатных курсов
   */
  public function findFreeCourses($limit = null, $offset = 0)
  {
    try {
      $sql = "SELECT *, 
                    (price = 0 OR is_free = 1) as is_free 
                    FROM {$this->table} 
                    WHERE (price = 0 OR is_free = 1) AND deleted_at IS NULL 
                    ORDER BY created_at DESC";

      if ($limit !== null) {
        $sql .= " LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit, $offset]);
      } else {
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
      }

      $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

      foreach ($courses as &$course) {
        $course['is_free'] = (bool)$course['is_free'];
      }

      return $courses;
    } catch (PDOException $e) {
      error_log("Course findFreeCourses error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение платных курсов
   */
  public function findPaidCourses($limit = null, $offset = 0)
  {
    try {
      $sql = "SELECT *, 
                    (price = 0 OR is_free = 1) as is_free 
                    FROM {$this->table} 
                    WHERE price > 0 AND is_free = 0 AND deleted_at IS NULL 
                    ORDER BY created_at DESC";

      if ($limit !== null) {
        $sql .= " LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit, $offset]);
      } else {
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
      }

      $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

      foreach ($courses as &$course) {
        $course['is_free'] = (bool)$course['is_free'];
      }

      return $courses;
    } catch (PDOException $e) {
      error_log("Course findPaidCourses error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Обновление курса
   */
  public function update($id, $data)
  {
    try {
      $fields = [];
      $values = [];

      foreach ($data as $key => $value) {
        if ($key === 'is_free') {
          $fields[] = "$key = ?";
          $values[] = $value ? 1 : 0;
        } else {
          $fields[] = "$key = ?";
          $values[] = $value;
        }
      }

      // Добавляем timestamp обновления
      $fields[] = "updated_at = ?";
      $values[] = date('Y-m-d H:i:s');

      $values[] = $id; // Для WHERE условия

      $sql = "UPDATE {$this->table} 
                    SET " . implode(', ', $fields) . " 
                    WHERE id = ? AND deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      return $stmt->execute($values);
    } catch (PDOException $e) {
      error_log("Course update error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Мягкое удаление курса
   */
  public function delete($id)
  {
    try {
      $sql = "UPDATE {$this->table} 
                    SET deleted_at = ? 
                    WHERE id = ? AND deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      return $stmt->execute([date('Y-m-d H:i:s'), $id]);
    } catch (PDOException $e) {
      error_log("Course delete error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Полное удаление курса (из базы)
   */
  public function forceDelete($id)
  {
    try {
      $sql = "DELETE FROM {$this->table} WHERE id = ?";
      $stmt = $this->db->prepare($sql);
      return $stmt->execute([$id]);
    } catch (PDOException $e) {
      error_log("Course forceDelete error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Восстановление курса
   */
  public function restore($id)
  {
    try {
      $sql = "UPDATE {$this->table} 
                    SET deleted_at = NULL 
                    WHERE id = ?";

      $stmt = $this->db->prepare($sql);
      return $stmt->execute([$id]);
    } catch (PDOException $e) {
      error_log("Course restore error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Поиск курсов по названию
   */
  public function search($query, $limit = null, $offset = 0)
  {
    try {
      $sql = "SELECT *, 
                    (price = 0 OR is_free = 1) as is_free 
                    FROM {$this->table} 
                    WHERE (title LIKE ? OR description LIKE ?) 
                    AND deleted_at IS NULL 
                    ORDER BY created_at DESC";

      if ($limit !== null) {
        $sql .= " LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $searchTerm = "%$query%";
        $stmt->execute([$searchTerm, $searchTerm, $limit, $offset]);
      } else {
        $stmt = $this->db->prepare($sql);
        $searchTerm = "%$query%";
        $stmt->execute([$searchTerm, $searchTerm]);
      }

      $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

      foreach ($courses as &$course) {
        $course['is_free'] = (bool)$course['is_free'];
      }

      return $courses;
    } catch (PDOException $e) {
      error_log("Course search error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение курсов по категории
   */
  public function findByCategory($categoryId, $limit = null, $offset = 0)
  {
    try {
      $sql = "SELECT c.*, 
                    (c.price = 0 OR c.is_free = 1) as is_free 
                    FROM {$this->table} c
                    INNER JOIN course_categories cc ON c.id = cc.course_id
                    WHERE cc.category_id = ? AND c.deleted_at IS NULL 
                    ORDER BY c.created_at DESC";

      if ($limit !== null) {
        $sql .= " LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$categoryId, $limit, $offset]);
      } else {
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$categoryId]);
      }

      $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

      foreach ($courses as &$course) {
        $course['is_free'] = (bool)$course['is_free'];
      }

      return $courses;
    } catch (PDOException $e) {
      error_log("Course findByCategory error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение популярных курсов
   */
  public function findPopular($limit = 10)
  {
    try {
      $sql = "SELECT c.*, 
                    (c.price = 0 OR c.is_free = 1) as is_free,
                    COUNT(e.id) as enrollment_count
                    FROM {$this->table} c
                    LEFT JOIN enrollments e ON c.id = e.course_id
                    WHERE c.deleted_at IS NULL 
                    GROUP BY c.id
                    ORDER BY enrollment_count DESC, c.created_at DESC
                    LIMIT ?";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$limit]);

      $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

      foreach ($courses as &$course) {
        $course['is_free'] = (bool)$course['is_free'];
        $course['enrollment_count'] = (int)$course['enrollment_count'];
      }

      return $courses;
    } catch (PDOException $e) {
      error_log("Course findPopular error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение количества курсов пользователя
   */
  public function countByUser($userId)
  {
    try {
      $sql = "SELECT COUNT(*) as count 
                    FROM {$this->table} 
                    WHERE user_id = ? AND deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$userId]);

      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      return $result['count'] ?? 0;
    } catch (PDOException $e) {
      error_log("Course countByUser error: " . $e->getMessage());
      return 0;
    }
  }

  /**
   * Получение общего количества курсов
   */
  public function countAll()
  {
    try {
      $sql = "SELECT COUNT(*) as count 
                    FROM {$this->table} 
                    WHERE deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute();

      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      return $result['count'] ?? 0;
    } catch (PDOException $e) {
      error_log("Course countAll error: " . $e->getMessage());
      return 0;
    }
  }

  /**
   * Универсальный метод поиска по полю
   */
  public function findAllBy($field, $value, $limit = null, $offset = 0)
  {
    try {
      $sql = "SELECT *, 
                    (price = 0 OR is_free = 1) as is_free 
                    FROM {$this->table} 
                    WHERE {$field} = ? AND deleted_at IS NULL 
                    ORDER BY created_at DESC";

      if ($limit !== null) {
        $sql .= " LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$value, $limit, $offset]);
      } else {
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$value]);
      }

      $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

      foreach ($courses as &$course) {
        $course['is_free'] = (bool)$course['is_free'];
      }

      return $courses;
    } catch (PDOException $e) {
      error_log("Course findAllBy error: " . $e->getMessage());
      return [];
    }
  }
}
