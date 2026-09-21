<?php
class Module
{
  protected $db;
  protected $table = 'modules';

  public function __construct()
  {
    $this->db = $GLOBALS['db'];
  }

  /**
   * Создание нового модуля
   */
  public function create($data)
  {
    try {
      // Автоматически рассчитываем order_index, если он не передан или пустой
      if (isset($data['course_id'])) {
        // Проверяем, нужно ли рассчитывать order_index
        $needsOrderIndex = !isset($data['order_index']) || 
                          $data['order_index'] === null || 
                          $data['order_index'] === '';
        
        if ($needsOrderIndex) {
          $maxOrderIndex = $this->getMaxOrderIndex($data['course_id']);
          // Если модулей нет (maxOrderIndex = -1), первый модуль получает order_index = 0
          // Иначе следующий порядковый номер
          $data['order_index'] = $maxOrderIndex === -1 ? 0 : $maxOrderIndex + 1;
          error_log("Auto-calculated order_index: {$data['order_index']} for course_id: {$data['course_id']}");
        }
      }

      $fields = [];
      $placeholders = [];
      $values = [];

      foreach ($data as $key => $value) {
        $fields[] = $key;
        $placeholders[] = '?';
        $values[] = $value;
      }

      // Добавляем timestamp создания
      $fields[] = 'created_at';
      $placeholders[] = '?';
      $values[] = date('Y-m-d H:i:s');

      // Проверяем, что order_index добавлен в запрос
      if (isset($data['course_id']) && !in_array('order_index', $fields)) {
        error_log("WARNING: order_index was not added to INSERT query! Fields: " . implode(', ', $fields));
        // Принудительно добавляем order_index
        $maxOrderIndex = $this->getMaxOrderIndex($data['course_id']);
        $orderIndex = $maxOrderIndex === -1 ? 0 : $maxOrderIndex + 1;
        $fields[] = 'order_index';
        $placeholders[] = '?';
        $values[] = $orderIndex;
        error_log("Force-added order_index: {$orderIndex} for course_id: {$data['course_id']}");
      }

      $sql = "INSERT INTO {$this->table} (" . implode(', ', $fields) . ") 
                    VALUES (" . implode(', ', $placeholders) . ")";

      error_log("Module create SQL: " . $sql);
      error_log("Module create values: " . json_encode($values));
      error_log("Module create fields: " . json_encode($fields));

      $stmt = $this->db->prepare($sql);

      if ($stmt->execute($values)) {
        return $this->db->lastInsertId();
      }

      return false;
    } catch (PDOException $e) {
      error_log("Module create error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение модуля по ID
   */
  public function findById($id)
  {
    try {
      $sql = "SELECT * FROM {$this->table} 
                    WHERE id = ? AND deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$id]);

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Module findById error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение модуля по ID с уроками и тестами
   */
  public function findByIdWithLessons($id)
  {
    try {
      $sql = "SELECT * FROM {$this->table} 
                    WHERE id = ? AND deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$id]);

      $module = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($module) {
        $module['lessons'] = $this->getLessonsByModuleId($module['id']);
        // Получаем тест модуля
        $testModel = new Test();
        $module['test'] = $testModel->findByModuleId($module['id']);
      }

      return $module;
    } catch (PDOException $e) {
      error_log("Module findByIdWithLessons error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение всех модулей курса с уроками и тестами
   */
  public function findByCourseId($courseId)
  {
    try {
      // Получаем модули
      $sql = "SELECT * FROM {$this->table} 
                    WHERE course_id = ? AND deleted_at IS NULL 
                    ORDER BY order_index ASC, created_at ASC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$courseId]);

      $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Для каждого модуля получаем уроки с тестами и тест модуля
      $testModel = new Test();
      foreach ($modules as &$module) {
        $module['lessons'] = $this->getLessonsByModuleId($module['id']);
        $module['test'] = $testModel->findByModuleId($module['id']);
      }

      return $modules;
    } catch (PDOException $e) {
      error_log("Module findByCourseId error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение уроков модуля с тестами
   */
  public function getLessonsByModuleId($moduleId)
  {
    try {
      $sql = "SELECT l.* FROM lessons l
                    WHERE l.module_id = ? AND l.deleted_at IS NULL 
                    ORDER BY l.order_index ASC, l.created_at ASC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$moduleId]);

      $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Получаем вложения и тесты для каждого урока
      $testModel = new Test();
      foreach ($lessons as &$lesson) {
        $lesson['attachments'] = $this->getLessonAttachments($lesson['id']);
        $lesson['test'] = $testModel->findByLessonId($lesson['id']);
      }

      return $lessons;
    } catch (PDOException $e) {
      error_log("Get lessons error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение вложений урока
   */
  private function getLessonAttachments($lessonId)
  {
    try {
      $sql = "SELECT * FROM lesson_attachments 
                    WHERE lesson_id = ? AND deleted_at IS NULL 
                    ORDER BY created_at ASC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$lessonId]);

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Get lesson attachments error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Обновление модуля
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

      // Добавляем timestamp обновления
      $fields[] = "updated_at = ?";
      $values[] = date('Y-m-d H:i:s');

      $values[] = $id;

      $sql = "UPDATE {$this->table} 
                    SET " . implode(', ', $fields) . " 
                    WHERE id = ? AND deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      return $stmt->execute($values);
    } catch (PDOException $e) {
      error_log("Module update error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Мягкое удаление модуля
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
      error_log("Module delete error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверка принадлежности модуля пользователю
   */
  public function isOwnedByUser($moduleId, $userId)
  {
    try {
      $moduleId = (int)$moduleId;
      $userId = (int)$userId;

      $sql = "SELECT m.* FROM {$this->table} m
                INNER JOIN courses c ON m.course_id = c.id
                WHERE m.id = ? AND c.user_id = ? 
                AND m.deleted_at IS NULL AND c.deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$moduleId, $userId]);

      return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (PDOException $e) {
      error_log("Module isOwnedByUser error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Обновление порядка модулей
   */
  public function updateOrder($courseId, $moduleOrder)
  {
    try {
      $this->db->beginTransaction();

      foreach ($moduleOrder as $index => $moduleId) {
        $sql = "UPDATE {$this->table} 
                        SET order_index = ?, updated_at = ? 
                        WHERE id = ? AND course_id = ? AND deleted_at IS NULL";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$index, date('Y-m-d H:i:s'), $moduleId, $courseId]);
      }

      $this->db->commit();
      return true;
    } catch (PDOException $e) {
      $this->db->rollBack();
      error_log("Module updateOrder error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение максимального order_index для курса
   */
  private function getMaxOrderIndex($courseId)
  {
    try {
      $sql = "SELECT COALESCE(MAX(order_index), -1) as max_order 
              FROM {$this->table} 
              WHERE course_id = ? AND deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$courseId]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      $maxOrder = $result ? (int)$result['max_order'] : -1;
      error_log("Max order_index for course_id {$courseId}: {$maxOrder}");
      
      return $maxOrder;
    } catch (PDOException $e) {
      error_log("Get max order_index error: " . $e->getMessage());
      return -1;
    }
  }
}
