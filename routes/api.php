<?php
// Главный вход и проверка всех функций
$router->add('GET', '/', function () {
  header('Content-Type: application/json');
  echo json_encode(['status' => 'ok']);
});

// 📚 КОНТРОЛЛЕРЫ
$authController = new BaseAuth(); // Для учеников
$teachAuth = new TeachAuth(); // Для преподавателей

// 👨‍🎓 МАРШРУТЫ ДЛЯ УЧЕНИКОВ (STUDENTS)
$router->add('POST', '/api/student/send-verification-code', [$authController, 'sendVerificationCode']);
$router->add('POST', '/api/student/verify-code', [$authController, 'verifyCode']);
$router->add('POST', '/api/student/register', [$authController, 'register']);
$router->add('POST', '/api/student/login', [$authController, 'login']);

// 🔑 АУТЕНТИФИКАЦИЯ УЧЕНИКОВ
$router->add('POST', '/api/student/refresh-token', [$authController, 'refreshToken']);
$router->add('POST', '/api/student/logout', [$authController, 'logout']);
$router->add('GET', '/api/student/check-auth', [$authController, 'checkAuth']);

// 👤 ПРОФИЛЬ УЧЕНИКОВ
$router->add('GET', '/api/student/profile', [$authController, 'getProfile']);
$router->add('PUT', '/api/student/profile', [$authController, 'updateProfile']);
$router->add('PUT', '/api/student/password', [$authController, 'updatePassword']);

// 🔐 ВОССТАНОВЛЕНИЕ ПАРОЛЯ УЧЕНИКОВ
$router->add('POST', '/api/student/forgot-password', [$authController, 'forgotPassword']);
$router->add('POST', '/api/student/verify-reset-code', [$authController, 'verifyResetCode']);
$router->add('POST', '/api/student/reset-password', [$authController, 'resetPassword']);

// 👨‍🏫 МАРШРУТЫ ДЛЯ ПРЕПОДАВАТЕЛЕЙ (TEACHERS)
$router->add('POST', '/api/teacher/send-verification-code', [$teachAuth, 'sendVerificationCode']);
$router->add('POST', '/api/teacher/verify-code', [$teachAuth, 'verifyCode']);
$router->add('POST', '/api/teacher/complete-registration', [$teachAuth, 'completeRegistration']);
$router->add('POST', '/api/teacher/login', [$teachAuth, 'login']);

// 🔑 АУТЕНТИФИКАЦИЯ ПРЕПОДАВАТЕЛЕЙ
$router->add('POST', '/api/teacher/refresh-token', [$teachAuth, 'refreshToken']);
$router->add('POST', '/api/teacher/logout', [$teachAuth, 'logout']);
$router->add('GET', '/api/teacher/check-auth', [$teachAuth, 'checkAuth']);

// 🔐 ВОССТАНОВЛЕНИЕ ПАРОЛЯ ПРЕПОДАВАТЕЛЕЙ
$router->add('POST', '/api/teacher/forgot-password', [$teachAuth, 'forgotPassword']);
$router->add('POST', '/api/teacher/verify-reset-code', [$teachAuth, 'verifyResetCode']);
$router->add('POST', '/api/teacher/reset-password', [$teachAuth, 'resetPassword']);

// 🛡️ ЗАЩИЩЕННЫЕ МАРШРУТЫ ПРЕПОДАВАТЕЛЕЙ
$router->add('GET', '/api/teacher/profile', [$teachAuth, 'getProfile']);
$router->add('PUT', '/api/teacher/profile', [$teachAuth, 'updateProfile']);
$router->add('GET', '/api/teacher/dashboard', [$teachAuth, 'getDashboard']);

// 📚 МАРШРУТЫ ДЛЯ РАБОТЫ С КУРСАМИ (ПРЕПОДАВАТЕЛИ)
$courseController = new CourseController();
$router->add('POST', '/api/teacher/create', [$courseController, 'create']);
$router->add('GET', '/api/teacher/courses', [$courseController, 'teachercourse']);
$router->add('GET', '/api/teacher/courses/{id}', [$courseController, 'show']);
$router->add('PUT', '/api/teacher/courses/{id}', [$courseController, 'update']);
$router->add('DELETE', '/api/teacher/courses/{id}', [$courseController, 'delete']);

// 📚 МАРШРУТЫ ДЛЯ РАБОТЫ С КУРСАМИ (ОБЩИЕ ДЛЯ ВСЕХ ПОЛЬЗОВАТЕЛЕЙ)
$router->add('GET', '/api/courses', [$courseController, 'index']);
$router->add('GET', '/api/courses/{id}', [$courseController, 'show']);
$router->add('GET', '/api/courses/free', [$courseController, 'freeCourses']);

// 🎯 МАРШРУТЫ ДЛЯ РАБОТЫ С МОДУЛЯМИ (ПРЕПОДАВАТЕЛИ)
$moduleController = new ModuleController();

// Проверка существования методов для отладки
if (!method_exists($moduleController, 'saveModuleTest')) {
  error_log("ERROR: saveModuleTest method not found in ModuleController");
  error_log("Available methods: " . implode(', ', get_class_methods($moduleController)));
}

$router->add('GET', '/api/teacher/courses/{course_id}/modules', [$moduleController, 'index']);
$router->add('POST', '/api/teacher/courses/{course_id}/modules', [$moduleController, 'create']);
$router->add('PUT', '/api/teacher/courses/{course_id}/modules/{module_id}', [$moduleController, 'update']);
$router->add('DELETE', '/api/teacher/courses/{course_id}/modules/{module_id}', [$moduleController, 'delete']);

// 🎯 МАРШРУТЫ ДЛЯ РАБОТЫ С ТЕСТАМИ МОДУЛЕЙ (ПРЕПОДАВАТЕЛИ) - ДОЛЖНЫ БЫТЬ ПЕРЕД РОУТАМИ УРОКОВ
$router->add('GET', '/api/teacher/courses/{course_id}/modules/{module_id}/test', [$moduleController, 'getModuleTest']);
$router->add('POST', '/api/teacher/courses/{course_id}/modules/{module_id}/test', [$moduleController, 'saveModuleTest']);
$router->add('DELETE', '/api/teacher/courses/{course_id}/modules/{module_id}/test', [$moduleController, 'deleteModuleTest']);

// 📖 МАРШРУТЫ ДЛЯ РАБОТЫ С УРОКАМИ (ПРЕПОДАВАТЕЛИ)
$router->add('GET', '/api/teacher/courses/{course_id}/modules/{module_id}/lessons', [$moduleController, 'getLessons']);
$router->add('POST', '/api/teacher/courses/{course_id}/modules/{module_id}/lessons', [$moduleController, 'createLesson']);
$router->add('PUT', '/api/teacher/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}', [$moduleController, 'updateLesson']);
$router->add('DELETE', '/api/teacher/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}', [$moduleController, 'deleteLesson']);

// 📄 МАРШРУТЫ ДЛЯ ЗАГРУЗКИ PDF (ОБЩИЕ ДЛЯ ВСЕХ)
$router->add('GET', '/api/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/pdf', [$moduleController, 'getLessonPdf']);

// 📄 МАРШРУТЫ ДЛЯ ЗАГРУЗКИ PDF ФАЙЛОВ
$router->add('POST', '/api/teacher/courses/{course_id}/modules/{module_id}/upload-pdf', [$moduleController, 'uploadPdf']);
$router->add('POST', '/api/teacher/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/upload-pdf', [$moduleController, 'uploadPdfForLesson']);

// 🎯 МАРШРУТЫ ДЛЯ РАБОТЫ С ТЕСТАМИ (ПРЕПОДАВАТЕЛИ)
$router->add('GET', '/api/teacher/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/test', [$moduleController, 'getTest']);
$router->add('POST', '/api/teacher/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/test', [$moduleController, 'saveTest']);
$router->add('DELETE', '/api/teacher/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/test', [$moduleController, 'deleteTest']);
$router->add('POST', '/api/teacher/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/test/image', [$moduleController, 'uploadQuestionImage']);
// 🎯 МАРШРУТЫ ДЛЯ РАБОТЫ С МОДУЛЯМИ (ОБЩИЕ ДЛЯ ВСЕХ ПОЛЬЗОВАТЕЛЕЙ)
$router->add('GET', '/api/courses/{course_id}/modules', [$moduleController, 'indexPublic']);
$router->add('GET', '/api/courses/{course_id}/modules/{module_id}', [$moduleController, 'showPublic']);

// 🎯 МАРШРУТЫ ДЛЯ РАБОТЫ С ТЕСТАМИ МОДУЛЕЙ (СТУДЕНТЫ) - ДОЛЖНЫ БЫТЬ ПЕРЕД РОУТАМИ УРОКОВ
$router->add('GET', '/api/courses/{course_id}/modules/{module_id}/test', [$moduleController, 'getModuleTestPublic']);
$router->add('POST', '/api/courses/{course_id}/modules/{module_id}/test/submit', [$moduleController, 'submitModuleTest']);

// 📖 МАРШРУТЫ ДЛЯ РАБОТЫ С УРОКАМИ (ОБЩИЕ ДЛЯ ВСЕХ ПОЛЬЗОВАТЕЛЕЙ)
$router->add('GET', '/api/courses/{course_id}/modules/{module_id}/lessons', [$moduleController, 'indexLessonsPublic']);
$router->add('GET', '/api/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}', [$moduleController, 'showLessonPublic']);

// 🎯 МАРШРУТЫ ДЛЯ РАБОТЫ С ТЕСТАМИ (СТУДЕНТЫ)
$router->add('GET', '/api/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/test', [$moduleController, 'getTestPublic']);
$router->add('POST', '/api/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/test/submit', [$moduleController, 'submitTest']);

// 📊 МАРШРУТЫ ДЛЯ ИСТОРИИ ПОПЫТОК И РЕЗУЛЬТАТОВ (СТУДЕНТЫ)
$router->add('GET', '/api/student/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/attempts', [$moduleController, 'getTestAttempts']);
$router->add('GET', '/api/student/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/best-result', [$moduleController, 'getBestResult']);

$enrollmentController = new EnrollmentController();

// 🎯 МАРШРУТЫ ДЛЯ ЗАПИСИ НА КУРСЫ (ENROLLMENT)
$router->add('POST', '/api/student/courses/{course_id}/enroll', [$enrollmentController, 'enroll']);
$router->add('POST', '/api/courses/{course_id}/enroll', [$enrollmentController, 'enroll']);
$router->add('GET', '/api/student/my-courses', [$enrollmentController, 'myEnrollments']);
$router->add('GET', '/api/student/courses/{course_id}/check-enrollment', [$enrollmentController, 'checkEnrollment']);

// 👨‍🏫 УПРАВЛЕНИЕ ЗАПИСЯМИ ДЛЯ ПРЕПОДАВАТЕЛЕЙ
$router->add('GET', '/api/teacher/courses/{course_id}/enrollments', [$enrollmentController, 'getCourseEnrollments']);
$router->add('POST', '/api/teacher/enrollments/{enrollment_id}/approve', [$enrollmentController, 'approveEnrollment']);
$router->add('POST', '/api/teacher/enrollments/{enrollment_id}/reject', [$enrollmentController, 'rejectEnrollment']);

// 📊 МАРШРУТЫ ДЛЯ РЕЗУЛЬТАТОВ ТЕСТОВ (TEACHER RESULTS)
$teacherController = new TeacherController();

// Результаты по курсу
$router->add('GET', '/api/teacher/courses/{course_id}/results', [$teacherController, 'getCourseResults']);

// Результаты по конкретному уроку
$router->add('GET', '/api/teacher/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/results', [$teacherController, 'getLessonResults']);

// Прогресс студентов по курсу
$router->add('GET', '/api/teacher/courses/{course_id}/progress', [$teacherController, 'getStudentsProgress']);

// Детальная информация о попытке
$router->add('GET', '/api/teacher/attempts/{attempt_id}', [$teacherController, 'getAttemptDetails']);

// Поиск результатов по студенту
$router->add('POST', '/api/teacher/search-results', [$teacherController, 'searchStudentResults']);

// Экспорт результатов в CSV
$router->add('GET', '/api/teacher/courses/{course_id}/export-results', [$teacherController, 'exportCourseResults']);

// 📊 МАРШРУТЫ ДЛЯ РЕЗУЛЬТАТОВ ТЕСТОВ СТУДЕНТОВ
$studentResultsController = new StudentResultsController();

// Мои попытки по уроку
$router->add('GET', '/api/student/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/attempts', [$studentResultsController, 'getMyAttempts']);

// Мой лучший результат по уроку
$router->add('GET', '/api/student/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/best-result', [$studentResultsController, 'getMyBestResult']);

// Мой прогресс по курсу
$router->add('GET', '/api/student/courses/{course_id}/progress', [$studentResultsController, 'getMyCourseProgress']);

// Детали моей попытки
$router->add('GET', '/api/student/attempts/{attempt_id}', [$studentResultsController, 'getMyAttemptDetails']);

// Моя последняя активность
$router->add('POST', '/api/student/recent-activity', [$studentResultsController, 'getMyRecentActivity']);

// Моя общая статистика
$router->add('GET', '/api/student/statistics', [$studentResultsController, 'getMyOverallStatistics']);

// 📊 МАРШРУТЫ ДЛЯ ПРОГРЕССА СТУДЕНТОВ
$ProgressController = new ProgressController();

// ======================== СУЩЕСТВУЮЩИЕ МАРШРУТЫ ========================
// Полный прогресс по уроку (существует в StudentResultsController)
$router->add('GET', '/api/student/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/progress', [$studentResultsController, 'getMyLessonProgress']);

// Детальный прогресс по курсу (существует в StudentResultsController)
$router->add('GET', '/api/student/courses/{course_id}/detailed-progress', [$studentResultsController, 'getMyDetailedCourseProgress']);

// Обновление прогресса (существует в StudentResultsController)
$router->add('POST', '/api/student/update-progress', [$studentResultsController, 'updateMyProgress']);

// ======================== НОВЫЕ МАРШРУТЫ ДЛЯ ProgressController ========================

// 1. ОСНОВНЫЕ ОПЕРАЦИИ С ПРОГРЕССОМ
// Завершение урока студентом
$router->add('POST', '/api/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/complete', [$ProgressController, 'completeLesson']);

// Проверка статуса завершения урока
$router->add('GET', '/api/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/completion', [$ProgressController, 'checkLessonCompletion']);

// Получение полного статуса урока (новая)
$router->add('GET', '/api/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/status', [$ProgressController, 'getLessonStatus']);

// Сброс прогресса урока (новая)
$router->add('DELETE', '/api/courses/{course_id}/modules/{module_id}/lessons/{lesson_id}/progress', [$ProgressController, 'resetLessonProgress']);

// 2. ПРОГРЕСС ПО КУРСУ И МОДУЛЯМ
// Получение общего прогресса по курсу
$router->add('GET', '/api/courses/{course_id}/progress', [$ProgressController, 'getCourseProgress']);

// Получение детального прогресса по модулям курса
$router->add('GET', '/api/courses/{course_id}/detailed-progress', [$ProgressController, 'getDetailedProgress']);

// Получение прогресса по конкретному модулю (новая)
$router->add('GET', '/api/courses/{course_id}/modules/{module_id}/progress', [$ProgressController, 'getModuleProgress']);

// 3. СТАТИСТИКА И АНАЛИТИКА
// Получение статистики прогресса студента (новая)
$router->add('GET', '/api/courses/{course_id}/progress/stats', [$ProgressController, 'getProgressStats']);

// Получение следующей рекомендованной лекции (новая)
$router->add('GET', '/api/courses/{course_id}/next-lesson', [$ProgressController, 'getNextRecommendedLesson']);

// 4. ПАКЕТНЫЕ ОПЕРАЦИИ
// Пакетная проверка завершения нескольких уроков (новая - POST)
$router->add('POST', '/api/courses/{course_id}/lessons/completion/batch', [$ProgressController, 'checkMultipleLessonsCompletion']);

// 5. АЛЬТЕРНАТИВНЫЕ ПУТИ (для совместимости)
// Альтернативный путь для прогресса курса (для студента)
$router->add('GET', '/api/student/courses/{course_id}/progress', [$ProgressController, 'getCourseProgress']);

// Альтернативный путь для детального прогресса (для студента)
$router->add('GET', '/api/student/courses/{course_id}/progress/detailed', [$ProgressController, 'getDetailedProgress']);


// 📊 МАРШРУТЫ ДЛЯ ПРОГРЕССА ПРЕПОДАВАТЕЛЕЙ
$TeacherProgressController = new TeacherProgressController();
// Статистика курса для учителя
$router->add('GET', '/api/teacher/courses/{course_id}/statistics', [$TeacherProgressController, 'getCourseStatistics']);

// Список студентов курса с прогрессом
$router->add('GET', '/api/teacher/courses/{course_id}/students', [$TeacherProgressController, 'getCourseStudents']);

// Прогресс конкретного студента
$router->add('GET', '/api/teacher/courses/{course_id}/students/{student_id}', [$TeacherProgressController, 'getStudentProgress']);

// Сравнение модулей
$router->add('GET', '/api/teacher/courses/{course_id}/modules-comparison', [$TeacherProgressController, 'getModuleComparison']);

// Временная статистика
$router->add('GET', '/api/teacher/courses/{course_id}/time-statistics', [$TeacherProgressController, 'getTimeBasedStatistics']);

// Экспорт статистики
$router->add('GET', '/api/teacher/courses/{course_id}/export', [$TeacherProgressController, 'exportStatistics']);

// Статистика по всем курсам учителя
$router->add('GET', '/api/teacher/courses/statistics/all', [$TeacherProgressController, 'getAllCoursesStatistics']);

// Более простой роут для детального прогресса (аналог студенческого)
$router->add('GET', '/api/teacher/courses/{course_id}/progress/detailed', [$TeacherProgressController, 'getCourseStatistics']);
