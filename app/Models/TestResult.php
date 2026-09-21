<?php
class TestResult
{
  protected $db;
  protected $table = 'test_results';

  public function __construct()
  {
    $this->db = $GLOBALS['db'];
  }

  /**
   * Получение результатов теста студента
   */
  public function findByStudentAndTest($studentId, $testId)
  {
    try {
      $sql = "SELECT * FROM {$this->table} 
              WHERE student_id = ? AND test_id = ? 
              ORDER BY attempt_number DESC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$studentId, $testId]);

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("TestResult findByStudentAndTest error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение следующего номера попытки
   */
  public function getNextAttemptNumber($studentId, $testId)
  {
    try {
      $sql = "SELECT MAX(attempt_number) as max_attempt 
              FROM {$this->table} 
              WHERE student_id = ? AND test_id = ?";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$studentId, $testId]);

      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      return ($result && $result['max_attempt']) ? $result['max_attempt'] + 1 : 1;
    } catch (PDOException $e) {
      error_log("TestResult getNextAttemptNumber error: " . $e->getMessage());
      return 1;
    }
  }

  /**
   * Сохранение результата теста
   */
  public function saveResult($data)
  {
    try {
      $sql = "INSERT INTO {$this->table} 
              (test_id, student_id, attempt_number, score, total_questions, 
               correct_answers, time_spent, started_at, completed_at, status, 
               created_at, updated_at) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

      $stmt = $this->db->prepare($sql);

      $currentTime = date('Y-m-d H:i:s');
      $startedAt = $data['started_at'] ?? $currentTime;
      $completedAt = $data['completed_at'] ?? $currentTime;
      $timeSpent = $data['time_spent'] ?? 0;
      $status = $data['status'] ?? 'completed';

      $result = $stmt->execute([
        $data['test_id'],
        $data['student_id'],
        $data['attempt_number'],
        $data['score'],
        $data['total_questions'],
        $data['correct_answers'],
        $timeSpent,
        $startedAt,
        $completedAt,
        $status,
        $currentTime,
        $currentTime
      ]);

      if ($result) {
        return $this->db->lastInsertId();
      }

      return false;
    } catch (PDOException $e) {
      error_log("TestResult saveResult error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение лучшего результата студента
   */
  public function getBestResult($studentId, $testId)
  {
    try {
      $sql = "SELECT * FROM {$this->table} 
              WHERE student_id = ? AND test_id = ? 
              ORDER BY score DESC, time_spent ASC 
              LIMIT 1";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$studentId, $testId]);

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("TestResult getBestResult error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение статистики по тесту
   */
  public function getTestStatistics($testId)
  {
    try {
      $sql = "SELECT 
                COUNT(*) as total_attempts,
                COUNT(DISTINCT student_id) as unique_students,
                AVG(score) as average_score,
                MAX(score) as max_score,
                MIN(score) as min_score
              FROM {$this->table} 
              WHERE test_id = ?";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$testId]);

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("TestResult getTestStatistics error: " . $e->getMessage());
      return false;
    }
  }
}
