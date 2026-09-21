<?php
class Lesson
{
  protected $db;
  protected $table = 'lessons';

  public function __construct()
  {
    $this->db = $GLOBALS['db'];
  }

  /**
   * Создание нового урока
   */
  public function create($data)
  {
    try {
      // Автоматически рассчитываем order_index, если он не передан или пустой
      if (isset($data['module_id'])) {
        // Проверяем, нужно ли рассчитывать order_index
        $needsOrderIndex = !isset($data['order_index']) || 
                          $data['order_index'] === null || 
                          $data['order_index'] === '';
        
        if ($needsOrderIndex) {
          $maxOrderIndex = $this->getMaxOrderIndex($data['module_id']);
          // Если уроков нет (maxOrderIndex = -1), первый урок получает order_index = 0
          // Иначе следующий порядковый номер
          $data['order_index'] = $maxOrderIndex === -1 ? 0 : $maxOrderIndex + 1;
          error_log("Auto-calculated order_index: {$data['order_index']} for module_id: {$data['module_id']}");
        }
      }

      $fields = [];
      $placeholders = [];
      $values = [];

      foreach ($data as $key => $value) {
        // Пропускаем attachments, они обрабатываются отдельно
        if ($key === 'attachments') continue;
        $fields[] = $key;
        $placeholders[] = '?';
        $values[] = $value;
      }

      // Добавляем timestamp создания
      $fields[] = 'created_at';
      $placeholders[] = '?';
      $values[] = date('Y-m-d H:i:s');

      // Проверяем, что order_index добавлен в запрос
      if (isset($data['module_id']) && !in_array('order_index', $fields)) {
        error_log("WARNING: order_index was not added to INSERT query! Fields: " . implode(', ', $fields));
        // Принудительно добавляем order_index
        $maxOrderIndex = $this->getMaxOrderIndex($data['module_id']);
        $orderIndex = $maxOrderIndex === -1 ? 0 : $maxOrderIndex + 1;
        $fields[] = 'order_index';
        $placeholders[] = '?';
        $values[] = $orderIndex;
        error_log("Force-added order_index: {$orderIndex} for module_id: {$data['module_id']}");
      }

      $sql = "INSERT INTO {$this->table} (" . implode(', ', $fields) . ") 
                    VALUES (" . implode(', ', $placeholders) . ")";

      error_log("Lesson create SQL: " . $sql);
      error_log("Lesson create values: " . json_encode($values));
      error_log("Lesson create fields: " . json_encode($fields));

      $stmt = $this->db->prepare($sql);

      if ($stmt->execute($values)) {
        $lessonId = $this->db->lastInsertId();

        // Сохраняем вложения если есть
        if (!empty($data['attachments'])) {
          $this->saveAttachments($lessonId, $data['attachments']);
        }

        return $lessonId;
      }

      return false;
    } catch (PDOException $e) {
      error_log("Lesson create error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение урока по ID
   */
  public function findById($id)
  {
    try {
      $sql = "SELECT * FROM {$this->table} 
                    WHERE id = ? AND deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$id]);

      $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($lesson) {
        $lesson['attachments'] = $this->getAttachments($lesson['id']);
      }

      return $lesson;
    } catch (PDOException $e) {
      error_log("Lesson findById error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение урока по ID с тестом
   */
  public function findByIdWithTest($id)
  {
    try {
      $sql = "SELECT * FROM {$this->table} 
                    WHERE id = ? AND deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$id]);

      $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($lesson) {
        $lesson['attachments'] = $this->getAttachments($lesson['id']);

        // Получаем тест
        $testModel = new Test();
        $lesson['test'] = $testModel->findByLessonId($lesson['id']);
      }

      return $lesson;
    } catch (PDOException $e) {
      error_log("Lesson findByIdWithTest error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Обновление урока
   */
  public function update($id, $data)
  {
    try {
      $fields = [];
      $values = [];

      foreach ($data as $key => $value) {
        // Пропускаем attachments, они обрабатываются отдельно
        if ($key === 'attachments') continue;
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
      $result = $stmt->execute($values);

      // Обновляем вложения если есть
      if ($result && !empty($data['attachments'])) {
        $this->updateAttachments($id, $data['attachments']);
      }

      return $result;
    } catch (PDOException $e) {
      error_log("Lesson update error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Мягкое удаление урока
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
      error_log("Lesson delete error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверка принадлежности урока пользователю
   */
  public function isOwnedByUser($lessonId, $userId)
  {
    try {
      $lessonId = (int)$lessonId;
      $userId = (int)$userId;

      $sql = "SELECT l.* FROM {$this->table} l
                INNER JOIN modules m ON l.module_id = m.id
                INNER JOIN courses c ON m.course_id = c.id
                WHERE l.id = ? AND c.user_id = ? 
                AND l.deleted_at IS NULL AND m.deleted_at IS NULL AND c.deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$lessonId, $userId]);

      return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (PDOException $e) {
      error_log("Lesson isOwnedByUser error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение вложений урока
   */
  private function getAttachments($lessonId)
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
   * Сохранение вложений
   */
  private function saveAttachments($lessonId, $attachments)
  {
    try {
      $sql = "INSERT INTO lesson_attachments (lesson_id, name, path, size, type, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?)";

      $stmt = $this->db->prepare($sql);

      foreach ($attachments as $attachment) {
        $stmt->execute([
          $lessonId,
          $attachment['name'],
          $attachment['path'],
          $attachment['size'],
          $attachment['type'],
          date('Y-m-d H:i:s')
        ]);
      }

      return true;
    } catch (PDOException $e) {
      error_log("Save lesson attachments error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Обновление вложений
   */
  private function updateAttachments($lessonId, $attachments)
  {
    try {
      // Сначала удаляем старые вложения
      $sql = "UPDATE lesson_attachments SET deleted_at = ? WHERE lesson_id = ?";
      $stmt = $this->db->prepare($sql);
      $stmt->execute([date('Y-m-d H:i:s'), $lessonId]);

      // Сохраняем новые вложения
      return $this->saveAttachments($lessonId, $attachments);
    } catch (PDOException $e) {
      error_log("Update lesson attachments error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Обновление порядка уроков
   */
  public function updateOrder($moduleId, $lessonOrder)
  {
    try {
      $this->db->beginTransaction();

      foreach ($lessonOrder as $index => $lessonId) {
        $sql = "UPDATE {$this->table} 
                        SET order_index = ?, updated_at = ? 
                        WHERE id = ? AND module_id = ? AND deleted_at IS NULL";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$index, date('Y-m-d H:i:s'), $lessonId, $moduleId]);
      }

      $this->db->commit();
      return true;
    } catch (PDOException $e) {
      $this->db->rollBack();
      error_log("Lesson updateOrder error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение максимального order_index для модуля
   */
  private function getMaxOrderIndex($moduleId)
  {
    try {
      $sql = "SELECT COALESCE(MAX(order_index), -1) as max_order 
              FROM {$this->table} 
              WHERE module_id = ? AND deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$moduleId]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      $maxOrder = $result ? (int)$result['max_order'] : -1;
      error_log("Max order_index for module_id {$moduleId}: {$maxOrder}");
      
      return $maxOrder;
    } catch (PDOException $e) {
      error_log("Get max order_index error: " . $e->getMessage());
      return -1;
    }
  }
}
