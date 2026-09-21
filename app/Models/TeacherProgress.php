<?php

class TeacherProgress extends Model
{
    /**
     * Получить статистику курса для преподавателя
     */
    public function getCourseStatistics($teacherId, $courseId)
    {
        try {
            $logData = [
                'method' => 'getCourseStatistics',
                'teacherId' => $teacherId,
                'courseId' => $courseId,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // Проверяем, является ли пользователь преподавателем этого курса
            if (!$this->isCourseTeacher($teacherId, $courseId)) {
                return $this->jsonResponse(false, 'User is not teacher of this course', null, $logData);
            }

            // Общее количество студентов на курсе
            $sqlTotalStudents = "
                SELECT COUNT(DISTINCT e.user_id) as total_students
                FROM enrollments e
                WHERE e.course_id = ? AND e.status = 'approved'
            ";
            
            $stmtTotal = $this->db->prepare($sqlTotalStudents);
            $stmtTotal->execute([$courseId]);
            $totalStudents = $stmtTotal->fetch(PDO::FETCH_ASSOC);

            // Студенты с прогрессом
            $sqlStudentsWithProgress = "
                SELECT COUNT(DISTINCT up.student_id) as active_students
                FROM user_progress up
                JOIN lessons l ON up.lesson_id = l.id
                JOIN modules m ON l.module_id = m.id
                WHERE m.course_id = ? 
                AND up.completed = 1
                AND up.student_id IN (
                    SELECT user_id 
                    FROM enrollments 
                    WHERE course_id = ? AND status = 'approved'
                )
            ";
            
            $stmtActive = $this->db->prepare($sqlStudentsWithProgress);
            $stmtActive->execute([$courseId, $courseId]);
            $activeStudents = $stmtActive->fetch(PDO::FETCH_ASSOC);

            // Средний прогресс по курсу
            $sqlAvgProgress = "
                SELECT 
                    AVG(progress.progress_percentage) as average_progress,
                    MAX(progress.progress_percentage) as max_progress,
                    MIN(progress.progress_percentage) as min_progress
                FROM (
                    SELECT 
                        up.student_id,
                        COUNT(DISTINCT up.lesson_id) as completed_lessons,
                        COUNT(DISTINCT l.id) as total_lessons,
                        CASE 
                            WHEN COUNT(DISTINCT l.id) > 0 THEN 
                                ROUND((COUNT(DISTINCT up.lesson_id) / COUNT(DISTINCT l.id)) * 100, 2)
                            ELSE 0 
                        END as progress_percentage
                    FROM user_progress up
                    JOIN lessons l ON up.lesson_id = l.id
                    JOIN modules m ON l.module_id = m.id
                    WHERE m.course_id = ? 
                    AND up.completed = 1
                    AND up.student_id IN (
                        SELECT user_id 
                        FROM enrollments 
                        WHERE course_id = ? AND status = 'approved'
                    )
                    GROUP BY up.student_id
                ) as progress
            ";
            
            $stmtAvg = $this->db->prepare($sqlAvgProgress);
            $stmtAvg->execute([$courseId, $courseId]);
            $progressStats = $stmtAvg->fetch(PDO::FETCH_ASSOC);

            // Распределение по прогрессу
            $sqlDistribution = "
                SELECT 
                    CASE 
                        WHEN progress_percentage = 0 THEN '0%'
                        WHEN progress_percentage > 0 AND progress_percentage <= 25 THEN '1-25%'
                        WHEN progress_percentage > 25 AND progress_percentage <= 50 THEN '26-50%'
                        WHEN progress_percentage > 50 AND progress_percentage <= 75 THEN '51-75%'
                        WHEN progress_percentage > 75 AND progress_percentage < 100 THEN '76-99%'
                        WHEN progress_percentage = 100 THEN '100%'
                    END as progress_range,
                    COUNT(*) as student_count
                FROM (
                    SELECT 
                        up.student_id,
                        CASE 
                            WHEN COUNT(DISTINCT l.id) > 0 THEN 
                                ROUND((COUNT(DISTINCT up.lesson_id) / COUNT(DISTINCT l.id)) * 100, 2)
                            ELSE 0 
                        END as progress_percentage
                    FROM user_progress up
                    JOIN lessons l ON up.lesson_id = l.id
                    JOIN modules m ON l.module_id = m.id
                    WHERE m.course_id = ? 
                    AND up.completed = 1
                    AND up.student_id IN (
                        SELECT user_id 
                        FROM enrollments 
                        WHERE course_id = ? AND status = 'approved'
                    )
                    GROUP BY up.student_id
                ) as student_progress
                GROUP BY progress_range
                ORDER BY progress_range
            ";
            
            $stmtDist = $this->db->prepare($sqlDistribution);
            $stmtDist->execute([$courseId, $courseId]);
            $distribution = $stmtDist->fetchAll(PDO::FETCH_ASSOC);

            // Топ студентов  
            $sqlTopStudents = "
                SELECT 
                    u.id as user_id,
                    u.email as student_email,
                    CONCAT(u.name, ' ', u.last_name) as student_name,
                    COUNT(DISTINCT up.lesson_id) as completed_lessons,
                    COUNT(DISTINCT l.id) as total_lessons,
                    CASE 
                        WHEN COUNT(DISTINCT l.id) > 0 THEN 
                            ROUND((COUNT(DISTINCT up.lesson_id) / COUNT(DISTINCT l.id)) * 100, 2)
                        ELSE 0 
                    END as progress_percentage,
                    MAX(up.completed_at) as last_activity
                FROM users u
                JOIN enrollments e ON u.id = e.user_id
                LEFT JOIN user_progress up ON u.id = up.student_id AND up.completed = 1
                LEFT JOIN lessons l ON up.lesson_id = l.id
                LEFT JOIN modules m ON l.module_id = m.id AND m.course_id = ?
                WHERE e.course_id = ? 
                AND e.status = 'approved'
                AND u.is_blocked = '0'
                GROUP BY u.id, u.email, u.name, u.last_name
                ORDER BY progress_percentage DESC, last_activity DESC
                LIMIT 10
            ";
            
            $stmtTop = $this->db->prepare($sqlTopStudents);
            $stmtTop->execute([$courseId, $courseId]);
            $topStudents = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

            // Прогресс по модулям
            $sqlModulesProgress = "
                SELECT 
                    m.id as module_id,
                    m.title as module_title,
                    m.order_index,
                    COUNT(DISTINCT l.id) as total_lessons,
                    COUNT(DISTINCT up.lesson_id) as completed_lessons,
                    CASE 
                        WHEN COUNT(DISTINCT l.id) > 0 THEN 
                            ROUND((COUNT(DISTINCT up.lesson_id) / COUNT(DISTINCT l.id)) * 100, 2)
                        ELSE 0 
                    END as completion_rate
                FROM modules m
                LEFT JOIN lessons l ON m.id = l.module_id
                LEFT JOIN user_progress up ON l.id = up.lesson_id AND up.completed = 1
                WHERE m.course_id = ?
                GROUP BY m.id, m.title, m.order_index
                ORDER BY m.order_index ASC
            ";
            
            $stmtModules = $this->db->prepare($sqlModulesProgress);
            $stmtModules->execute([$courseId]);
            $modulesProgress = $stmtModules->fetchAll(PDO::FETCH_ASSOC);

            // Прогресс по дням (активность)
            $sqlDailyActivity = "
                SELECT 
                    DATE(up.completed_at) as activity_date,
                    COUNT(DISTINCT up.student_id) as active_students,
                    COUNT(up.lesson_id) as completed_lessons
                FROM user_progress up
                JOIN lessons l ON up.lesson_id = l.id
                JOIN modules m ON l.module_id = m.id
                WHERE m.course_id = ? 
                AND up.completed = 1
                AND up.completed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY DATE(up.completed_at)
                ORDER BY activity_date DESC
                LIMIT 30
            ";
            
            $stmtActivity = $this->db->prepare($sqlDailyActivity);
            $stmtActivity->execute([$courseId]);
            $dailyActivity = $stmtActivity->fetchAll(PDO::FETCH_ASSOC);

            $result = [
                'success' => true,
                'message' => 'Course statistics retrieved successfully',
                'data' => [
                    'course_id' => $courseId,
                    'teacher_id' => $teacherId,
                    'total_students' => (int)($totalStudents['total_students'] ?? 0),
                    'active_students' => (int)($activeStudents['active_students'] ?? 0),
                    'progress_statistics' => [
                        'average_progress' => round($progressStats['average_progress'] ?? 0, 2),
                        'max_progress' => round($progressStats['max_progress'] ?? 0, 2),
                        'min_progress' => round($progressStats['min_progress'] ?? 0, 2)
                    ],
                    'progress_distribution' => $distribution,
                    'top_students' => $topStudents,
                    'modules_progress' => $modulesProgress,
                    'daily_activity' => $dailyActivity,
                    'summary' => [
                        'inactive_students' => max(0, 
                            ($totalStudents['total_students'] ?? 0) - 
                            ($activeStudents['active_students'] ?? 0)
                        ),
                        'completion_rate' => ($totalStudents['total_students'] ?? 0) > 0 ? 
                            round(($activeStudents['active_students'] ?? 0) / 
                                  ($totalStudents['total_students'] ?? 0) * 100, 2) : 0
                    ]
                ],
                'log' => array_merge($logData, [
                    'query_execution_time' => microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"],
                    'memory_usage' => memory_get_usage(true)
                ])
            ];

            return $this->jsonResponse(true, 'Course statistics retrieved successfully', $result);

        } catch (PDOException $e) {
            return $this->jsonResponse(false, 'Database error while fetching course statistics', null, [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'method' => 'getCourseStatistics'
            ]);
        } catch (Exception $e) {
            return $this->jsonResponse(false, 'Unexpected error while fetching course statistics', null, [
                'error' => $e->getMessage(),
                'method' => 'getCourseStatistics'
            ]);
        }
    }

    /**
     * Получить прогресс конкретного студента по курсу (для учителя)
     */
    public function getStudentCourseProgress($teacherId, $courseId, $studentId)
    {
        try {
            $logData = [
                'method' => 'getStudentCourseProgress',
                'teacherId' => $teacherId,
                'courseId' => $courseId,
                'studentId' => $studentId,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // Проверяем доступ учителя
            if (!$this->isCourseTeacher($teacherId, $courseId)) {
                return $this->jsonResponse(false, 'User is not teacher of this course', null, $logData);
            }

            // Проверяем, записан ли студент на курс
            if (!$this->isStudentEnrolled($studentId, $courseId)) {
                return $this->jsonResponse(false, 'Student is not enrolled in this course', null, $logData);
            }

            // Информация о студенте
            $sqlStudentInfo = "
                SELECT 
                    u.id,
                    u.email,
                    CONCAT(u.name, ' ', u.last_name) as full_name,
                    u.created_at as registration_date,
                    e.enrolled_at,
                    e.status as enrollment_status
                FROM users u
                JOIN enrollments e ON u.id = e.user_id
                WHERE u.id = ? AND e.course_id = ?
                AND u.is_blocked = '0'
                LIMIT 1
            ";
            
            $stmtStudent = $this->db->prepare($sqlStudentInfo);
            $stmtStudent->execute([$studentId, $courseId]);
            $studentInfo = $stmtStudent->fetch(PDO::FETCH_ASSOC);

            if (!$studentInfo) {
                return $this->jsonResponse(false, 'Student information not found', null, $logData);
            }

            // Общий прогресс по курсу
            $userProgressModel = new UserProgress();
            $courseProgress = $userProgressModel->getUserProgress($studentId, $courseId);

            // Детальный прогресс по модулям
            $detailedProgress = $userProgressModel->getDetailedProgress($studentId, $courseId);

            // Завершенные уроки с датами
            $sqlCompletedLessons = "
                SELECT 
                    up.lesson_id,
                    l.title as lesson_title,
                    m.title as module_title,
                    up.completed_at,
                    TIMESTAMPDIFF(HOUR, e.enrolled_at, up.completed_at) as hours_to_complete
                FROM user_progress up
                JOIN lessons l ON up.lesson_id = l.id
                JOIN modules m ON l.module_id = m.id
                JOIN enrollments e ON up.student_id = e.user_id AND e.course_id = m.course_id
                WHERE up.student_id = ? 
                AND m.course_id = ?
                AND up.completed = 1
                ORDER BY up.completed_at DESC
            ";
            
            $stmtCompleted = $this->db->prepare($sqlCompletedLessons);
            $stmtCompleted->execute([$studentId, $courseId]);
            $completedLessons = $stmtCompleted->fetchAll(PDO::FETCH_ASSOC);

            // Время активности
            $sqlActivityTime = "
                SELECT 
                    MIN(up.created_at) as first_activity,
                    MAX(up.completed_at) as last_activity,
                    TIMESTAMPDIFF(DAY, MIN(up.created_at), MAX(up.completed_at)) as days_active
                FROM user_progress up
                JOIN lessons l ON up.lesson_id = l.id
                JOIN modules m ON l.module_id = m.id
                WHERE up.student_id = ? 
                AND m.course_id = ?
                AND up.completed = 1
            ";
            
            $stmtActivity = $this->db->prepare($sqlActivityTime);
            $stmtActivity->execute([$studentId, $courseId]);
            $activityTime = $stmtActivity->fetch(PDO::FETCH_ASSOC);

            // Среднее время на урок
            $sqlAvgTime = "
                SELECT 
                    AVG(TIMESTAMPDIFF(HOUR, e.enrolled_at, up.completed_at)) as avg_hours_per_lesson
                FROM user_progress up
                JOIN lessons l ON up.lesson_id = l.id
                JOIN modules m ON l.module_id = m.id
                JOIN enrollments e ON up.student_id = e.user_id AND e.course_id = m.course_id
                WHERE up.student_id = ? 
                AND m.course_id = ?
                AND up.completed = 1
            ";
            
            $stmtAvg = $this->db->prepare($sqlAvgTime);
            $stmtAvg->execute([$studentId, $courseId]);
            $avgTime = $stmtAvg->fetch(PDO::FETCH_ASSOC);

            $result = [
                'success' => true,
                'message' => 'Student progress retrieved successfully',
                'data' => [
                    'student_info' => $studentInfo,
                    'course_progress' => $courseProgress,
                    'detailed_progress' => $detailedProgress,
                    'completed_lessons' => [
                        'count' => count($completedLessons),
                        'lessons' => $completedLessons
                    ],
                    'activity_statistics' => [
                        'first_activity' => $activityTime['first_activity'] ?? null,
                        'last_activity' => $activityTime['last_activity'] ?? null,
                        'days_active' => (int)($activityTime['days_active'] ?? 0),
                        'avg_hours_per_lesson' => round($avgTime['avg_hours_per_lesson'] ?? 0, 2)
                    ],
                    'performance_metrics' => [
                        'completion_rate' => $courseProgress['progress_percentage'] ?? 0,
                        'lesson_completion_speed' => $avgTime['avg_hours_per_lesson'] ?? 0,
                        'consistency_score' => $this->calculateConsistencyScore($studentId, $courseId)
                    ]
                ],
                'log' => array_merge($logData, [
                    'query_execution_time' => microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"],
                    'memory_usage' => memory_get_usage(true),
                    'completed_lessons_count' => count($completedLessons)
                ])
            ];

            return $this->jsonResponse(true, 'Student progress retrieved successfully', $result);

        } catch (PDOException $e) {
            return $this->jsonResponse(false, 'Database error while fetching student progress', null, [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'method' => 'getStudentCourseProgress'
            ]);
        } catch (Exception $e) {
            return $this->jsonResponse(false, 'Unexpected error while fetching student progress', null, [
                'error' => $e->getMessage(),
                'method' => 'getStudentCourseProgress'
            ]);
        }
    }

    /**
     * Получить список всех студентов курса с прогрессом
     */
    public function getCourseStudentsWithProgress($teacherId, $courseId, $filters = [])
    {
        try {
            $logData = [
                'method' => 'getCourseStudentsWithProgress',
                'teacherId' => $teacherId,
                'courseId' => $courseId,
                'filters' => $filters,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // Проверяем доступ учителя
            if (!$this->isCourseTeacher($teacherId, $courseId)) {
                return $this->jsonResponse(false, 'User is not teacher of this course', [], $logData);
            }

            $whereClause = "WHERE e.course_id = ? AND e.status = 'approved' AND u.is_blocked = '0'";
            $params = [$courseId];

            // Применяем фильтры
            if (!empty($filters['search'])) {
                $whereClause .= " AND (u.email LIKE ? OR u.name LIKE ? OR u.last_name LIKE ?)";
                $searchTerm = "%" . $filters['search'] . "%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            if (!empty($filters['progress_min'])) {
                $whereClause .= " HAVING progress_percentage >= ?";
            }

            if (!empty($filters['progress_max'])) {
                $whereClause .= " HAVING progress_percentage <= ?";
            }

            $sql = "
                SELECT 
                    u.id as user_id,
                    u.email as student_email,
                    CONCAT(u.name, ' ', u.last_name) as student_name,
                    u.created_at as registration_date,
                    e.enrolled_at,
                    COUNT(DISTINCT up.lesson_id) as completed_lessons,
                    (
                        SELECT COUNT(DISTINCT l.id)
                        FROM lessons l
                        JOIN modules m ON l.module_id = m.id
                        WHERE m.course_id = ?
                    ) as total_lessons,
                    CASE 
                        WHEN (
                            SELECT COUNT(DISTINCT l.id)
                            FROM lessons l
                            JOIN modules m ON l.module_id = m.id
                            WHERE m.course_id = ?
                        ) > 0 THEN 
                            ROUND((COUNT(DISTINCT up.lesson_id) / (
                                SELECT COUNT(DISTINCT l.id)
                                FROM lessons l
                                JOIN modules m ON l.module_id = m.id
                                WHERE m.course_id = ?
                            )) * 100, 2)
                        ELSE 0 
                    END as progress_percentage,
                    MAX(up.completed_at) as last_activity,
                    CASE 
                        WHEN MAX(up.completed_at) IS NULL THEN 'inactive'
                        WHEN MAX(up.completed_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 'active'
                        ELSE 'idle'
                    END as activity_status
                FROM users u
                JOIN enrollments e ON u.id = e.user_id
                LEFT JOIN user_progress up ON u.id = up.student_id AND up.completed = 1
                LEFT JOIN lessons l ON up.lesson_id = l.id
                LEFT JOIN modules m ON l.module_id = m.id AND m.course_id = ?
                $whereClause
                GROUP BY u.id, u.email, u.name, u.last_name, u.created_at, e.enrolled_at
                ORDER BY 
                    " . ($filters['sort_by'] ?? 'progress_percentage') . " " . 
                    ($filters['sort_order'] ?? 'DESC') . ",
                    u.email ASC
                LIMIT " . ($filters['limit'] ?? 50) . "
                OFFSET " . ($filters['offset'] ?? 0) . "
            ";

            // Добавляем дополнительные параметры для фильтров прогресса
            if (!empty($filters['progress_min'])) {
                $params[] = (float)$filters['progress_min'];
            }

            if (!empty($filters['progress_max'])) {
                $params[] = (float)$filters['progress_max'];
            }

            // Добавляем параметры для подзапросов
            $params[] = $courseId;
            $params[] = $courseId;
            $params[] = $courseId;
            $params[] = $courseId;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $logData['query'] = [
                'sql' => $sql,
                'params' => $params,
                'row_count' => count($students)
            ];

            return $this->jsonResponse(true, 'Students with progress retrieved successfully', $students, $logData);

        } catch (PDOException $e) {
            return $this->jsonResponse(false, 'Database error while fetching students with progress', [], [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'method' => 'getCourseStudentsWithProgress',
                'filters' => $filters
            ]);
        } catch (Exception $e) {
            return $this->jsonResponse(false, 'Unexpected error while fetching students with progress', [], [
                'error' => $e->getMessage(),
                'method' => 'getCourseStudentsWithProgress',
                'filters' => $filters
            ]);
        }
    }

    /**
     * Получить сравнение прогресса по модулям
     */
    public function getModuleComparison($teacherId, $courseId)
    {
        try {
            $logData = [
                'method' => 'getModuleComparison',
                'teacherId' => $teacherId,
                'courseId' => $courseId,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // Проверяем доступ учителя
            if (!$this->isCourseTeacher($teacherId, $courseId)) {
                return $this->jsonResponse(false, 'User is not teacher of this course', [], $logData);
            }

            $sql = "
                SELECT 
                    m.id as module_id,
                    m.title as module_title,
                    m.order_index,
                    COUNT(DISTINCT l.id) as total_lessons,
                    COUNT(DISTINCT up.lesson_id) as total_completions,
                    COUNT(DISTINCT up.student_id) as students_completed,
                    (
                        SELECT COUNT(DISTINCT e.user_id)
                        FROM enrollments e
                        WHERE e.course_id = ? AND e.status = 'approved'
                    ) as total_students,
                    CASE 
                        WHEN COUNT(DISTINCT l.id) > 0 THEN 
                            ROUND((COUNT(DISTINCT up.lesson_id) / COUNT(DISTINCT l.id)) * 100, 2)
                        ELSE 0 
                    END as completion_rate,
                    CASE 
                        WHEN (
                            SELECT COUNT(DISTINCT e.user_id)
                            FROM enrollments e
                            WHERE e.course_id = ? AND e.status = 'approved'
                        ) > 0 THEN 
                            ROUND((COUNT(DISTINCT up.student_id) / (
                                SELECT COUNT(DISTINCT e.user_id)
                                FROM enrollments e
                                WHERE e.course_id = ? AND e.status = 'approved'
                            )) * 100, 2)
                        ELSE 0 
                    END as student_completion_rate,
                    AVG(
                        CASE 
                            WHEN up.completed_at IS NOT NULL THEN
                                TIMESTAMPDIFF(HOUR, e.enrolled_at, up.completed_at)
                            ELSE NULL
                        END 
                    ) as avg_completion_hours
                FROM modules m
                LEFT JOIN lessons l ON m.id = l.module_id 
                LEFT JOIN user_progress up ON l.id = up.lesson_id AND up.completed = 1
                LEFT JOIN enrollments e ON up.student_id = e.user_id AND e.course_id = m.course_id
                WHERE m.course_id = ?
                GROUP BY m.id, m.title, m.order_index
                ORDER BY m.order_index ASC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$courseId, $courseId, $courseId, $courseId]);
            $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $logData['query_details'] = [
                'module_count' => count($modules),
                'execution_time' => microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]
            ];

            return $this->jsonResponse(true, 'Module comparison retrieved successfully', $modules, $logData);

        } catch (PDOException $e) {
            return $this->jsonResponse(false, 'Database error while fetching module comparison', [], [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'method' => 'getModuleComparison'
            ]);
        } catch (Exception $e) {
            return $this->jsonResponse(false, 'Unexpected error while fetching module comparison', [], [
                'error' => $e->getMessage(),
                'method' => 'getModuleComparison'
            ]);
        }
    }

    /**
     * Получить временную статистику активности
     */
    public function getTimeBasedStatistics($teacherId, $courseId, $startDate = null, $endDate = null)
    {
        try {
            $logData = [
                'method' => 'getTimeBasedStatistics',
                'teacherId' => $teacherId,
                'courseId' => $courseId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // Проверяем доступ учителя
            if (!$this->isCourseTeacher($teacherId, $courseId)) {
                return $this->jsonResponse(false, 'User is not teacher of this course', [], $logData);
            }

            // Устанавливаем даты по умолчанию (последние 30 дней)
            if (!$startDate) {
                $startDate = date('Y-m-d', strtotime('-30 days'));
            }
            if (!$endDate) {
                $endDate = date('Y-m-d');
            }

            $sql = "
                SELECT 
                    DATE(up.completed_at) as date,
                    COUNT(DISTINCT up.student_id) as active_students,
                    COUNT(up.lesson_id) as completed_lessons,
                    GROUP_CONCAT(DISTINCT CONCAT(u.name, ' ', u.last_name)) as active_student_names
                FROM user_progress up
                JOIN lessons l ON up.lesson_id = l.id
                JOIN modules m ON l.module_id = m.id
                JOIN users u ON up.student_id = u.id
                WHERE m.course_id = ? 
                AND up.completed = 1
                AND DATE(up.completed_at) BETWEEN ? AND ?
                GROUP BY DATE(up.completed_at)
                ORDER BY date DESC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$courseId, $startDate, $endDate]);
            $dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Общая статистика за период
            $sqlTotal = "
                SELECT 
                    COUNT(DISTINCT up.student_id) as total_active_students,
                    COUNT(up.lesson_id) as total_completed_lessons,
                    AVG(
                        SELECT COUNT(DISTINCT up2.lesson_id)
                        FROM user_progress up2
                        JOIN lessons l2 ON up2.lesson_id = l2.id
                        JOIN modules m2 ON l2.module_id = m2.id
                        WHERE m2.course_id = ? 
                        AND up2.completed = 1
                        AND DATE(up2.completed_at) BETWEEN ? AND ?
                        GROUP BY up2.user_id
                    ) as avg_lessons_per_student
                FROM user_progress up
                JOIN lessons l ON up.lesson_id = l.id
                JOIN modules m ON l.module_id = m.id
                WHERE m.course_id = ? 
                AND up.completed = 1
                AND DATE(up.completed_at) BETWEEN ? AND ?
            ";

            $stmtTotal = $this->db->prepare($sqlTotal);
            $stmtTotal->execute([$courseId, $startDate, $endDate, $courseId, $startDate, $endDate]);
            $totalStats = $stmtTotal->fetch(PDO::FETCH_ASSOC);

            $result = [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'days' => count($dailyStats)
                ],
                'daily_statistics' => $dailyStats,
                'summary' => [
                    'total_active_students' => (int)($totalStats['total_active_students'] ?? 0),
                    'total_completed_lessons' => (int)($totalStats['total_completed_lessons'] ?? 0),
                    'avg_lessons_per_student' => round($totalStats['avg_lessons_per_student'] ?? 0, 2)
                ]
            ];

            $logData['statistics'] = [
                'days_count' => count($dailyStats),
                'total_active_students' => $result['summary']['total_active_students'],
                'total_completed_lessons' => $result['summary']['total_completed_lessons'],
                'execution_time' => microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]
            ];

            return $this->jsonResponse(true, 'Time-based statistics retrieved successfully', $result, $logData);

        } catch (PDOException $e) {
            return $this->jsonResponse(false, 'Database error while fetching time-based statistics', [], [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'method' => 'getTimeBasedStatistics',
                'dates' => ['start' => $startDate, 'end' => $endDate]
            ]);
        } catch (Exception $e) {
            return $this->jsonResponse(false, 'Unexpected error while fetching time-based statistics', [], [
                'error' => $e->getMessage(),
                'method' => 'getTimeBasedStatistics',
                'dates' => ['start' => $startDate, 'end' => $endDate]
            ]);
        }
    }

    /**
     * Проверка, является ли пользователь преподавателем курса
     */
    private function isCourseTeacher($userId, $courseId)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id 
                FROM courses 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$courseId, $userId]);
            $result = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
            
            $logData = [
                'method' => 'isCourseTeacher',
                'userId' => $userId,
                'courseId' => $courseId,
                'isTeacher' => $result,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            return $result;
        } catch (PDOException $e) {
            $logData = [
                'method' => 'isCourseTeacher',
                'userId' => $userId,
                'courseId' => $courseId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
            return false;
        }
    }

    /**
     * Проверка, записан ли студент на курс
     */
    private function isStudentEnrolled($studentId, $courseId)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id 
                FROM enrollments 
                WHERE user_id = ? AND course_id = ? AND status = 'approved'
            ");
            $stmt->execute([$studentId, $courseId]);
            $result = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
            
            $logData = [
                'method' => 'isStudentEnrolled',
                'studentId' => $studentId,
                'courseId' => $courseId,
                'isEnrolled' => $result,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            return $result;
        } catch (PDOException $e) {
            $logData = [
                'method' => 'isStudentEnrolled',
                'studentId' => $studentId,
                'courseId' => $courseId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
            return false;
        }
    }

    /**
     * Расчет оценки консистентности студента
     */
    private function calculateConsistencyScore($studentId, $courseId)
    {
        try {
            $logData = [
                'method' => 'calculateConsistencyScore',
                'studentId' => $studentId,
                'courseId' => $courseId,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // Получаем даты завершения уроков
            $sql = "
                SELECT DATE(completed_at) as completion_date
                FROM user_progress up
                JOIN lessons l ON up.lesson_id = l.id
                JOIN modules m ON l.module_id = m.id
                WHERE up.student_id = ? 
                AND m.course_id = ?
                AND up.completed = 1
                ORDER BY completed_at ASC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$studentId, $courseId]);
            $dates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($dates) < 2) {
                $logData['result'] = 0;
                $logData['reason'] = 'Insufficient data (less than 2 completion dates)';
                return 0;
            }

            // Рассчитываем консистентность на основе регулярности
            $uniqueDays = count(array_unique(array_column($dates, 'completion_date')));
            $totalDays = $this->getCourseDurationInDays($studentId, $courseId);

            if ($totalDays <= 0) {
                $logData['result'] = 0;
                $logData['reason'] = 'Invalid course duration';
                return 0;
            }

            // Оценка консистентности (0-100)
            $consistencyScore = min(100, ($uniqueDays / $totalDays) * 100);
            $result = round($consistencyScore, 2);

            $logData['calculation'] = [
                'unique_days' => $uniqueDays,
                'total_days' => $totalDays,
                'consistency_score' => $result,
                'dates_count' => count($dates)
            ];

            return $result;
        } catch (PDOException $e) {
            $logData = [
                'method' => 'calculateConsistencyScore',
                'studentId' => $studentId,
                'courseId' => $courseId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
            return 0;
        }
    }

    /**
     * Получить длительность курса в днях для студента
     */
    private function getCourseDurationInDays($studentId, $courseId)
    {
        try {
            $sql = "
                SELECT 
                    DATEDIFF(
                        COALESCE(MAX(up.completed_at), NOW()),
                        MIN(e.enrolled_at)
                    ) as days_duration
                FROM enrollments e
                LEFT JOIN user_progress up ON e.user_id = up.student_id
                LEFT JOIN lessons l ON up.lesson_id = l.id
                LEFT JOIN modules m ON l.module_id = m.id AND m.course_id = e.course_id
                WHERE e.user_id = ? AND e.course_id = ?
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$studentId, $courseId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $duration = max(1, (int)($result['days_duration'] ?? 1));
            
            $logData = [
                'method' => 'getCourseDurationInDays',
                'studentId' => $studentId,
                'courseId' => $courseId,
                'duration' => $duration,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            return $duration;
        } catch (PDOException $e) {
            $logData = [
                'method' => 'getCourseDurationInDays',
                'studentId' => $studentId,
                'courseId' => $courseId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
            return 1;
        }
    }

    /**
     * Экспорт статистики курса в CSV
     */
    public function exportCourseStatistics($teacherId, $courseId, $format = 'csv')
    {
        try {
            $logData = [
                'method' => 'exportCourseStatistics',
                'teacherId' => $teacherId,
                'courseId' => $courseId,
                'format' => $format,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            if (!$this->isCourseTeacher($teacherId, $courseId)) {
                return $this->jsonResponse(false, 'User is not teacher of this course', null, $logData);
            }

            $statistics = $this->getCourseStatistics($teacherId, $courseId);
            $students = $this->getCourseStudentsWithProgress($teacherId, $courseId);

            if ($format === 'csv') {
                $exportData = $this->generateCSV($statistics, $students);
                return $this->jsonResponse(true, 'Course statistics exported to CSV successfully', $exportData, $logData);
            } elseif ($format === 'json') {
                $exportData = [
                    'statistics' => $statistics,
                    'students' => $students
                ];
                return $this->jsonResponse(true, 'Course statistics exported to JSON successfully', $exportData, $logData);
            }

            return $this->jsonResponse(false, 'Unsupported export format', null, $logData);

        } catch (Exception $e) {
            return $this->jsonResponse(false, 'Error exporting course statistics', null, [
                'error' => $e->getMessage(),
                'method' => 'exportCourseStatistics',
                'format' => $format
            ]);
        }
    }

    /**
     * Генерация CSV файла
     */
    private function generateCSV($statistics, $students)
    {
        try {
            $filename = 'course_statistics_' . date('Y-m-d_H-i-s') . '.csv';
            $output = fopen('php://temp', 'r+');

            // Заголовок статистики
            fputcsv($output, ['Course Statistics']);
            fputcsv($output, ['Total Students', $statistics['total_students']]);
            fputcsv($output, ['Active Students', $statistics['active_students']]);
            fputcsv($output, ['Average Progress', $statistics['progress_statistics']['average_progress']]);
            fputcsv($output, ['Max Progress', $statistics['progress_statistics']['max_progress']]);
            fputcsv($output, ['Min Progress', $statistics['progress_statistics']['min_progress']]);
            fputcsv($output, []);

            // Список студентов
            fputcsv($output, ['Student List']);
            fputcsv($output, ['Student ID', 'Name', 'Email', 'Progress %', 'Completed Lessons', 'Last Activity', 'Status']);

            foreach ($students as $student) {
                fputcsv($output, [
                    $student['user_id'],
                    $student['student_name'],
                    $student['student_email'],
                    $student['progress_percentage'],
                    $student['completed_lessons'],
                    $student['last_activity'],
                    $student['activity_status']
                ]);
            }

            rewind($output);
            $csv = stream_get_contents($output);
            fclose($output);

            $logData = [
                'method' => 'generateCSV',
                'filename' => $filename,
                'student_count' => count($students),
                'timestamp' => date('Y-m-d H:i:s')
            ];

            return [
                'filename' => $filename,
                'content' => base64_encode($csv), // Кодируем для безопасной передачи
                'type' => 'text/csv',
                'size' => strlen($csv),
                'log' => $logData
            ];
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'success' => false
            ];
        }
    }

    /**
     * Универсальный метод для возврата JSON ответов
     */
    private function jsonResponse($success, $message, $data = null, $log = [])
    {
        $response = [
            'success' => $success,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $data
        ];

        
            $response['log'] = $log;
        

        return $response;
    }

    /**
     * Пустая статистика курса
     */
    private function getEmptyCourseStatistics($courseId = null)
    {
        return $this->jsonResponse(true, 'Empty course statistics', [
            'course_id' => $courseId,
            'total_students' => 0,
            'active_students' => 0,
            'progress_statistics' => [
                'average_progress' => 0,
                'max_progress' => 0,
                'min_progress' => 0
            ],
            'progress_distribution' => [],
            'top_students' => [],
            'modules_progress' => [],
            'daily_activity' => [],
            'summary' => [
                'inactive_students' => 0,
                'completion_rate' => 0
            ]
        ]);
    }

    /**
     * Пустой прогресс студента
     */
    private function getEmptyStudentProgress($studentId = null, $courseId = null)
    {
        return $this->jsonResponse(true, 'Empty student progress', [
            'student_info' => null,
            'course_progress' => [
                'total_lessons' => 0,
                'completed_lessons' => 0,
                'progress_percentage' => 0,
                'course_id' => $courseId,
                'user_id' => $studentId
            ],
            'detailed_progress' => [
                'course_progress' => [],
                'modules_progress' => []
            ],
            'completed_lessons' => [
                'count' => 0,
                'lessons' => []
            ],
            'activity_statistics' => [
                'first_activity' => null,
                'last_activity' => null,
                'days_active' => 0,
                'avg_hours_per_lesson' => 0
            ],
            'performance_metrics' => [
                'completion_rate' => 0,
                'lesson_completion_speed' => 0,
                'consistency_score' => 0
            ]
        ]);
    }
}