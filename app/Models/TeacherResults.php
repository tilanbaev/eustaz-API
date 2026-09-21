<?php
class TeacherResults
{
  protected $db;
  protected $table = 'test_results';

  public function __construct()
  {
    $this->db = $GLOBALS['db'];
  }

  /**
   * Получение всех результатов по курсу учителя
   */
  public function getCourseResults($teacherId, $courseId)
  {
    try {
      $sql = "SELECT 
                tr.*,
                lt.title as test_title,
                l.title as lesson_title,
                m.title as module_title,
                u.first_name,
                u.last_name,
                u.email
              FROM {$this->table} tr
              INNER JOIN lesson_tests lt ON tr.test_id = lt.id
              INNER JOIN lessons l ON lt.lesson_id = l.id
              INNER JOIN modules m ON l.module_id = m.id
              INNER JOIN courses c ON m.course_id = c.id
              INNER JOIN users u ON tr.student_id = u.id
              WHERE c.teacher_id = ? AND c.id = ?
              ORDER BY tr.created_at DESC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$teacherId, $courseId]);

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("TeacherResults getCourseResults error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение результатов по конкретному уроку
   */
  public function getLessonResults($teacherId, $lessonId)
  {
    try {
      $sql = "SELECT 
                tr.*,
                lt.title as test_title,
                l.title as lesson_title,
                u.name,
                u.last_name,
                u.email
              FROM {$this->table} tr
              INNER JOIN lesson_tests lt ON tr.test_id = lt.id
              INNER JOIN lessons l ON lt.lesson_id = l.id
              INNER JOIN modules m ON l.module_id = m.id
              INNER JOIN courses c ON m.course_id = c.id
              INNER JOIN users u ON tr.student_id = u.id
              WHERE c.user_id = ? AND l.id = ?
              ORDER BY tr.score DESC, tr.created_at DESC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$teacherId, $lessonId]);

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("TeacherResults getLessonResults error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение статистики по курсу
   */
  public function getCourseStatistics($teacherId, $courseId)
  {
    try {
      $sql = "SELECT 
                COUNT(DISTINCT tr.student_id) as total_students,
                COUNT(tr.id) as total_attempts,
                AVG(tr.score) as average_score,
                MAX(tr.score) as max_score,
                MIN(tr.score) as min_score,
                COUNT(DISTINCT l.id) as total_lessons_with_tests
              FROM {$this->table} tr
              INNER JOIN lesson_tests lt ON tr.test_id = lt.id
              INNER JOIN lessons l ON lt.lesson_id = l.id
              INNER JOIN modules m ON l.module_id = m.id
              INNER JOIN courses c ON m.course_id = c.id
              WHERE c.teacher_id = ? AND c.id = ?";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$teacherId, $courseId]);

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("TeacherResults getCourseStatistics error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение статистики по уроку
   */
  public function getLessonStatistics($teacherId, $lessonId)
  {
    try {
      $sql = "SELECT 
                COUNT(DISTINCT tr.student_id) as total_students,
                COUNT(tr.id) as total_attempts,
                AVG(tr.score) as average_score,
                MAX(tr.score) as max_score,
                MIN(tr.score) as min_score,
                lt.title as test_title
              FROM {$this->table} tr
              INNER JOIN lesson_tests lt ON tr.test_id = lt.id
              INNER JOIN lessons l ON lt.lesson_id = l.id
              INNER JOIN modules m ON l.module_id = m.id
              INNER JOIN courses c ON m.course_id = c.id
              WHERE c.user_id = ? AND l.id = ?
              GROUP BY lt.id";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$teacherId, $lessonId]);

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("TeacherResults getLessonStatistics error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение прогресса студентов по курсу
   */
  public function getStudentsProgress($teacherId, $courseId)
  {
    try {
      $sql = "SELECT 
                u.id as student_id,
                u.name,
                u.last_name,
                u.email,
                COUNT(tr.id) as completed_tests,
                COUNT(DISTINCT l.id) as total_lessons,
                AVG(tr.score) as average_score,
                MAX(tr.created_at) as last_activity
              FROM users u
              INNER JOIN test_results tr ON u.id = tr.student_id
              INNER JOIN lesson_tests lt ON tr.test_id = lt.id
              INNER JOIN lessons l ON lt.lesson_id = l.id
              INNER JOIN modules m ON l.module_id = m.id
              INNER JOIN courses c ON m.course_id = c.id
              WHERE c.user_id = ? AND c.id = ?
              GROUP BY u.id
              ORDER BY average_score DESC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$teacherId, $courseId]);

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("TeacherResults getStudentsProgress error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение детальной информации о попытке
   */
  public function getAttemptDetails($teacherId, $attemptId)
  {
    try {
      $sql = "SELECT 
                  tr.*,
                  lt.title as test_title,
                  l.title as lesson_title,
                  m.title as module_title,
                  c.title as course_title,
                  u.name,
                  u.last_name,
                  u.email,
                  c.user_id as course_teacher_id
                FROM {$this->table} tr
                INNER JOIN lesson_tests lt ON tr.test_id = lt.id
                INNER JOIN lessons l ON lt.lesson_id = l.id
                INNER JOIN modules m ON l.module_id = m.id
                INNER JOIN courses c ON m.course_id = c.id
                INNER JOIN users u ON tr.student_id = u.id
                WHERE c.user_id = ? AND tr.id = ?";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$teacherId, $attemptId]);

      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      // Дополнительная проверка безопасности
      if ($result && $result['course_teacher_id'] == $teacherId) {
        return $result;
      }

      return false; // Если попытка не найдена или нет прав доступа

    } catch (PDOException $e) {
      error_log("TeacherResults getAttemptDetails error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Поиск результатов по студенту
   */
  public function searchStudentResults($teacherId, $studentName, $courseId = null)
  {
    try {
      $sql = "SELECT 
                tr.*,
                lt.title as test_title,
                l.title as lesson_title,
                c.title as course_title,
                u.name,
                u.last_name,
                u.email
              FROM {$this->table} tr
              INNER JOIN lesson_tests lt ON tr.test_id = lt.id
              INNER JOIN lessons l ON lt.lesson_id = l.id
              INNER JOIN modules m ON l.module_id = m.id
              INNER JOIN courses c ON m.course_id = c.id
              INNER JOIN users u ON tr.student_id = u.id
              WHERE c.user_id = ? 
                AND (u.name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";

      $params = [$teacherId, "%$studentName%", "%$studentName%", "%$studentName%"];

      if ($courseId) {
        $sql .= " AND c.id = ?";
        $params[] = $courseId;
      }

      $sql .= " ORDER BY tr.created_at DESC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute($params);

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("TeacherResults searchStudentResults error: " . $e->getMessage());
      return false;
    }
  }
}
