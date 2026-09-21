<?php

class TeacherProgressController extends BaseAuth
{
    protected $teacherProgressModel;
    protected $courseModel;
    protected $moduleModel;
    protected $enrollmentModel;

    public function __construct()
    {
        parent::__construct();
        $this->teacherProgressModel = new TeacherProgress();
        $this->courseModel = new Course();
        $this->moduleModel = new Module();
        $this->enrollmentModel = new Enrollment();

        error_log("TeacherProgressController initialized");
    }

    /**
     * Проверка роли пользователя (должен быть teacher или admin)
     */
    private function checkTeacherRole()
    {
        $user = $this->getUserFromRequest();
        if (!$user) {
            return ['error' => 'Authentication required', 'code' => 401];
        }

        $userRole = $user['role'];
        if ($userRole !== 'teacher' && $userRole !== 'admin') {
            return ['error' => 'Access denied. Teacher role required', 'code' => 403];
        }

        return ['success' => true, 'user' => $user];
    }

    /**
     * Получить общую статистику курса для учителя
     */
    public function getCourseStatistics($courseId)
    {
        error_log("TeacherProgressController::getCourseStatistics called with courseId: " . $courseId);

        try {
            // Проверяем роль пользователя
            $roleCheck = $this->checkTeacherRole();
            if (isset($roleCheck['error'])) {
                return $this->jsonResponse(false, $roleCheck['error'], null, $roleCheck['code']);
            }

            $user = $roleCheck['user'];
            $teacherId = $user['id'];

            // Проверяем существование курса
            $courseId = (int)$courseId;
            if ($courseId <= 0) {
                return $this->jsonResponse(false, 'Invalid course ID', null, 400);
            }

            $course = $this->courseModel->findById($courseId);
            if (!$course) {
                return $this->jsonResponse(false, 'Course not found', null, 404);
            }

            // Для учителей проверяем, что курс принадлежит им
            if ($user['role'] === 'teacher' && $course['user_id'] != $teacherId) {
                return $this->jsonResponse(false, 'Access denied to this course', null, 403);
            }

            // Получаем статистику курса
            $statistics = $this->teacherProgressModel->getCourseStatistics($teacherId, $courseId);

            return $this->jsonResponse(true, 'Course statistics retrieved successfully', [
                'course' => [
                    'id' => $course['id'],
                    'title' => $course['title'],
                    'description' => $course['description'],
                    'teacher_id' => $course['user_id']
                ],
                'statistics' => $statistics,
                'teacher_id' => $teacherId
            ]);
        } catch (Exception $e) {
            error_log("Get course statistics error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return $this->jsonResponse(false, 'Internal server error', null, 500);
        }
    }

    /**
     * Получить прогресс конкретного студента по курсу
     */
    public function getStudentProgress($courseId, $studentId)
    {
        error_log("TeacherProgressController::getStudentProgress called with courseId: $courseId, studentId: $studentId");

        try {
            // Проверяем роль пользователя
            $roleCheck = $this->checkTeacherRole();
            if (isset($roleCheck['error'])) {
                return $this->jsonResponse(false, $roleCheck['error'], null, $roleCheck['code']);
            }

            $user = $roleCheck['user'];
            $teacherId = $user['id'];

            // Проверяем параметры
            $courseId = (int)$courseId;
            $studentId = (int)$studentId;

            if ($courseId <= 0 || $studentId <= 0) {
                return $this->jsonResponse(false, 'Invalid IDs', null, 400);
            }

            // Проверяем существование курса
            $course = $this->courseModel->findById($courseId);
            if (!$course) {
                return $this->jsonResponse(false, 'Course not found', null, 404);
            }

            // Для учителей проверяем, что курс принадлежит им
            if ($user['role'] === 'teacher' && $course['user_id'] != $teacherId) {
                return $this->jsonResponse(false, 'Access denied to this course', null, 403);
            }

            // Получаем прогресс студента
            $studentProgress = $this->teacherProgressModel->getStudentCourseProgress($teacherId, $courseId, $studentId);

            // Проверяем, найден ли студент
            if (empty($studentProgress['student_info'])) {
                return $this->jsonResponse(false, 'Student not found or not enrolled in this course', null, 404);
            }

            return $this->jsonResponse(true, 'Student progress retrieved successfully', [
                'course' => [
                    'id' => $course['id'],
                    'title' => $course['title']
                ],
                'student_progress' => $studentProgress,
                'teacher_id' => $teacherId
            ]);
        } catch (Exception $e) {
            error_log("Get student progress error: " . $e->getMessage());
            return $this->jsonResponse(false, 'Internal server error', null, 500);
        }
    }

    /**
     * Получить список всех студентов курса с прогрессом
     */
    public function getCourseStudents($courseId)
    {
        error_log("TeacherProgressController::getCourseStudents called with courseId: " . $courseId);

        try {
            // Проверяем роль пользователя
            $roleCheck = $this->checkTeacherRole();
            if (isset($roleCheck['error'])) {
                return $this->jsonResponse(false, $roleCheck['error'], null, $roleCheck['code']);
            }

            $user = $roleCheck['user'];
            $teacherId = $user['id'];

            // Проверяем параметры
            $courseId = (int)$courseId;
            if ($courseId <= 0) {
                return $this->jsonResponse(false, 'Invalid course ID', null, 400);
            }

            // Проверяем существование курса
            $course = $this->courseModel->findById($courseId);
            if (!$course) {
                return $this->jsonResponse(false, 'Course not found', null, 404);
            }

            // Для учителей проверяем, что курс принадлежит им
            if ($user['role'] === 'teacher' && $course['user_id'] != $teacherId) {
                return $this->jsonResponse(false, 'Access denied to this course', null, 403);
            }

            // Получаем фильтры из запроса
            $filters = [
                'search' => $_GET['search'] ?? null,
                'progress_min' => $_GET['progress_min'] ?? null,
                'progress_max' => $_GET['progress_max'] ?? null,
                'sort_by' => $_GET['sort_by'] ?? 'progress_percentage',
                'sort_order' => $_GET['sort_order'] ?? 'DESC',
                'limit' => $_GET['limit'] ?? 50,
                'offset' => $_GET['offset'] ?? 0
            ];

            // Получаем список студентов
            $students = $this->teacherProgressModel->getCourseStudentsWithProgress($teacherId, $courseId, $filters);

            // Получаем общую статистику для пагинации
            $totalStudents = $this->enrollmentModel->countEnrolledStudents($courseId);

            return $this->jsonResponse(true, 'Course students retrieved successfully', [
                'course' => [
                    'id' => $course['id'],
                    'title' => $course['title'],
                    'total_students' => $totalStudents
                ],
                'filters_applied' => $filters,
                'pagination' => [
                    'total' => $totalStudents,
                    'limit' => (int)$filters['limit'],
                    'offset' => (int)$filters['offset'],
                    'returned' => count($students)
                ],
                'students' => $students
            ]);
        } catch (Exception $e) {
            error_log("Get course students error: " . $e->getMessage());
            return $this->jsonResponse(false, 'Internal server error', null, 500);
        }
    }

    /**
     * Получить сравнение прогресса по модулям
     */
    public function getModuleComparison($courseId)
    {
        error_log("TeacherProgressController::getModuleComparison called with courseId: " . $courseId);

        try {
            // Проверяем роль пользователя
            $roleCheck = $this->checkTeacherRole();
            if (isset($roleCheck['error'])) {
                return $this->jsonResponse(false, $roleCheck['error'], null, $roleCheck['code']);
            }

            $user = $roleCheck['user'];
            $teacherId = $user['id'];

            // Проверяем параметры
            $courseId = (int)$courseId;
            if ($courseId <= 0) {
                return $this->jsonResponse(false, 'Invalid course ID', null, 400);
            }

            // Проверяем существование курса
            $course = $this->courseModel->findById($courseId);
            if (!$course) {
                return $this->jsonResponse(false, 'Course not found', null, 404);
            }

            // Для учителей проверяем, что курс принадлежит им
            if ($user['role'] === 'teacher' && $course['user_id'] != $teacherId) {
                return $this->jsonResponse(false, 'Access denied to this course', null, 403);
            }

            // Получаем сравнение по модулям
            $moduleComparison = $this->teacherProgressModel->getModuleComparison($teacherId, $courseId);

            return $this->jsonResponse(true, 'Module comparison retrieved successfully', [
                'course' => [
                    'id' => $course['id'],
                    'title' => $course['title']
                ],
                'module_comparison' => $moduleComparison,
                'teacher_id' => $teacherId
            ]);
        } catch (Exception $e) {
            error_log("Get module comparison error: " . $e->getMessage());
            return $this->jsonResponse(false, 'Internal server error', null, 500);
        }
    }

    /**
     * Получить временную статистику активности
     */
    public function getTimeBasedStatistics($courseId)
    {
        error_log("TeacherProgressController::getTimeBasedStatistics called with courseId: " . $courseId);

        try {
            // Проверяем роль пользователя
            $roleCheck = $this->checkTeacherRole();
            if (isset($roleCheck['error'])) {
                return $this->jsonResponse(false, $roleCheck['error'], null, $roleCheck['code']);
            }

            $user = $roleCheck['user'];
            $teacherId = $user['id'];

            // Проверяем параметры
            $courseId = (int)$courseId;
            if ($courseId <= 0) {
                return $this->jsonResponse(false, 'Invalid course ID', null, 400);
            }

            // Проверяем существование курса
            $course = $this->courseModel->findById($courseId);
            if (!$course) {
                return $this->jsonResponse(false, 'Course not found', null, 404);
            }

            // Для учителей проверяем, что курс принадлежит им
            if ($user['role'] === 'teacher' && $course['user_id'] != $teacherId) {
                return $this->jsonResponse(false, 'Access denied to this course', null, 403);
            }

            // Получаем параметры дат из запроса
            $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $endDate = $_GET['end_date'] ?? date('Y-m-d');

            // Валидация дат
            if (!$this->isValidDate($startDate) || !$this->isValidDate($endDate)) {
                return $this->jsonResponse(false, 'Invalid date format. Use YYYY-MM-DD', null, 400);
            }

            if (strtotime($startDate) > strtotime($endDate)) {
                return $this->jsonResponse(false, 'Start date cannot be after end date', null, 400);
            }

            // Получаем временную статистику
            $timeStats = $this->teacherProgressModel->getTimeBasedStatistics($teacherId, $courseId, $startDate, $endDate);

            return $this->jsonResponse(true, 'Time-based statistics retrieved successfully', [
                'course' => [
                    'id' => $course['id'],
                    'title' => $course['title']
                ],
                'time_period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'days' => isset($timeStats['period']) ? $timeStats['period']['days'] : 0
                ],
                'statistics' => $timeStats,
                'teacher_id' => $teacherId
            ]);
        } catch (Exception $e) {
            error_log("Get time-based statistics error: " . $e->getMessage());
            return $this->jsonResponse(false, 'Internal server error', null, 500);
        }
    }

    /**
     * Экспорт статистики курса
     */
    public function exportStatistics($courseId)
    {
        error_log("TeacherProgressController::exportStatistics called with courseId: " . $courseId);

        try {
            // Проверяем роль пользователя
            $roleCheck = $this->checkTeacherRole();
            if (isset($roleCheck['error'])) {
                return $this->jsonResponse(false, $roleCheck['error'], null, $roleCheck['code']);
            }

            $user = $roleCheck['user'];
            $teacherId = $user['id'];

            // Проверяем параметры
            $courseId = (int)$courseId;
            if ($courseId <= 0) {
                return $this->jsonResponse(false, 'Invalid course ID', null, 400);
            }

            // Проверяем существование курса
            $course = $this->courseModel->findById($courseId);
            if (!$course) {
                return $this->jsonResponse(false, 'Course not found', null, 404);
            }

            // Для учителей проверяем, что курс принадлежит им
            if ($user['role'] === 'teacher' && $course['user_id'] != $teacherId) {
                return $this->jsonResponse(false, 'Access denied to this course', null, 403);
            }

            // Получаем формат экспорта
            $format = $_GET['format'] ?? 'json';
            if (!in_array($format, ['json', 'csv'])) {
                $format = 'json';
            }

            // Экспортируем статистику
            $exportData = $this->teacherProgressModel->exportCourseStatistics($teacherId, $courseId, $format);

            if (!$exportData) {
                return $this->jsonResponse(false, 'Failed to export statistics', null, 500);
            }

            if ($format === 'csv') {
                // Отправляем CSV файл
                header('Content-Type: ' . $exportData['type']);
                header('Content-Disposition: attachment; filename="' . $exportData['filename'] . '"');
                header('Content-Length: ' . strlen($exportData['content']));
                echo $exportData['content'];
                exit();
            } else {
                // Возвращаем JSON
                return $this->jsonResponse(true, 'Statistics exported successfully', $exportData);
            }
        } catch (Exception $e) {
            error_log("Export statistics error: " . $e->getMessage());
            return $this->jsonResponse(false, 'Internal server error', null, 500);
        }
    }

    /**
     * Получить статистику по всем курсам учителя
     */
    public function getAllCoursesStatistics()
    {
        error_log("TeacherProgressController::getAllCoursesStatistics called");

        try {
            // Проверяем роль пользователя
            $roleCheck = $this->checkTeacherRole();
            if (isset($roleCheck['error'])) {
                return $this->jsonResponse(false, $roleCheck['error'], null, $roleCheck['code']);
            }

            $user = $roleCheck['user'];
            $teacherId = $user['id'];

            // Получаем все курсы учителя
            $courses = $this->courseModel->findByTeacher($teacherId);
            
            if (empty($courses)) {
                return $this->jsonResponse(true, 'No courses found', [
                    'teacher_id' => $teacherId,
                    'courses' => [],
                    'summary' => [
                        'total_courses' => 0,
                        'total_students' => 0,
                        'total_income' => 0
                    ]
                ]);
            }

            // Собираем статистику по каждому курсу
            $coursesWithStats = [];
            $totalStudents = 0;
            $totalIncome = 0;

            foreach ($courses as $course) {
                $courseId = $course['id'];
                
                // Получаем базовую статистику
                $statistics = $this->teacherProgressModel->getCourseStatistics($teacherId, $courseId);
                
                // Получаем информацию о доходах (если есть)
                $income = $this->calculateCourseIncome($courseId);
                
                $coursesWithStats[] = [
                    'course_info' => $course,
                    'statistics' => $statistics,
                    'income' => $income
                ];

                $totalStudents += $statistics['total_students'] ?? 0;
                $totalIncome += $income;
            }

            // Сортируем курсы по количеству студентов
            usort($coursesWithStats, function ($a, $b) {
                return ($b['statistics']['total_students'] ?? 0) - ($a['statistics']['total_students'] ?? 0);
            });

            return $this->jsonResponse(true, 'All courses statistics retrieved successfully', [
                'teacher_id' => $teacherId,
                'total_courses' => count($courses),
                'courses' => $coursesWithStats,
                'summary' => [
                    'total_courses' => count($courses),
                    'total_students' => $totalStudents,
                    'active_students' => array_sum(array_column(array_column($coursesWithStats, 'statistics'), 'active_students')),
                    'total_income' => $totalIncome,
                    'avg_students_per_course' => count($courses) > 0 ? round($totalStudents / count($courses), 2) : 0,
                    'avg_progress' => $this->calculateAverageProgress($coursesWithStats)
                ]
            ]);
        } catch (Exception $e) {
            error_log("Get all courses statistics error: " . $e->getMessage());
            return $this->jsonResponse(false, 'Internal server error', null, 500);
        }
    }

    /**
     * Вспомогательный метод для расчета дохода курса
     */
    private function calculateCourseIncome($courseId)
    {
        try {
            // Эта реализация зависит от вашей бизнес-логики
            // Пример: сумма всех платежей за курс
            $sql = "
                SELECT SUM(amount) as total_income
                FROM payments
                WHERE course_id = ? AND status = 'completed'
            ";
            $db = $GLOBALS['db'] ?? null;
            if (!$db) {
                error_log("CalculateCourseIncome: global db not available");
                return 0;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute([$courseId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return (float)($result['total_income'] ?? 0);
        } catch (Exception $e) {
            error_log("Calculate course income error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Расчет среднего прогресса по всем курсам
     */
    private function calculateAverageProgress($coursesWithStats)
    {
        $totalProgress = 0;
        $count = 0;

        foreach ($coursesWithStats as $course) {
            if (isset($course['statistics']['progress_statistics']['average_progress'])) {
                $totalProgress += $course['statistics']['progress_statistics']['average_progress'];
                $count++;
            }
        }

        return $count > 0 ? round($totalProgress / $count, 2) : 0;
    }

    /**
     * Валидация даты
     */
    private function isValidDate($date, $format = 'Y-m-d')
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    /**
     * Вспомогательный метод для JSON ответов
     */
    protected function jsonResponse($success, $message, $data = null, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');

        $response = [
            'success' => $success,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        error_log("TeacherProgressController JSON Response - Success: {$success}, Message: {$message}, Status: {$statusCode}");

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Получение пользователя из запроса
     */
    private function getUserFromRequest()
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_replace('Bearer ', '', $authHeader);

        if (empty($token)) {
            error_log("No authorization token found in TeacherProgressController");
            return null;
        }

        return $this->getUserFromToken($token);
    }
}