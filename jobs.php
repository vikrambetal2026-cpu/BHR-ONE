<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/session.php';
require __DIR__ . '/db.php';

function respondJson($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function getJsonInput() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}
/**
 * Check whether a table has a given column (MySQL).
 * Needed because your shared DB schema may differ from bundled schema.sql.
 */
function tableHasColumn(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . ':' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);
    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? 'list';

// CREATE JOB (Employer only)
if ($action === 'create') {
    requireRole('employer');
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondJson(['error' => 'Method not allowed'], 405);
    }
    
    $input = getJsonInput();
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $skills = trim($input['skills'] ?? '');
    $location = trim($input['location'] ?? '');
    $experienceMin = isset($input['experience_min']) ? (int)$input['experience_min'] : 0;
    $experienceMax = isset($input['experience_max']) ? (int)$input['experience_max'] : null;
    $salaryMin = isset($input['salary_min']) ? (float)$input['salary_min'] : null;
    $salaryMax = isset($input['salary_max']) ? (float)$input['salary_max'] : null;
    $jobType = trim($input['job_type'] ?? 'full-time');
    $status = trim($input['status'] ?? 'active');
    
    // Validation
    if (empty($title) || empty($description) || empty($skills) || empty($location)) {
        respondJson(['error' => 'Title, description, skills, and location are required'], 400);
    }
    
    if (!in_array($jobType, ['full-time', 'part-time', 'contract', 'internship'])) {
        $jobType = 'full-time';
    }
    
    if (!in_array($status, ['active', 'closed', 'draft'])) {
        $status = 'active';
    }
    
    try {
        $userId = getCurrentUserId();
        
        $stmt = $pdo->prepare('
            INSERT INTO jobs (employer_id, title, description, skills, location, 
                            experience_min, experience_max, salary_min, salary_max, 
                            job_type, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $userId, $title, $description, $skills, $location,
            $experienceMin, $experienceMax, $salaryMin, $salaryMax,
            $jobType, $status
        ]);
        
        $jobId = (int)$pdo->lastInsertId();
        
        respondJson([
            'success' => true,
            'message' => 'Job posted successfully',
            'job_id' => $jobId
        ]);
        
    } catch (Exception $e) {
        respondJson(['error' => 'Failed to create job', 'details' => $e->getMessage()], 500);
    }
}

// LIST JOBS
else if ($action === 'list') {
    try {
        $search = trim($_GET['search'] ?? '');
        $location = trim($_GET['location'] ?? '');
        $jobType = trim($_GET['job_type'] ?? '');
        $experienceMin = isset($_GET['experience_min']) ? (int)$_GET['experience_min'] : null;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        $where = [];
        if (tableHasColumn($pdo, 'jobs', 'status')) {
            $where[] = 'j.status = "active"';
        }
        $params = [];
        
        if (!empty($search)) {
            $where[] = '(j.title LIKE ? OR j.skills LIKE ? OR j.description LIKE ?)';
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if (!empty($location)) {
            $where[] = 'j.location LIKE ?';
            $params[] = '%' . $location . '%';
        }
        
        if (!empty($jobType) && in_array($jobType, ['full-time', 'part-time', 'contract', 'internship'])) {
            $where[] = 'j.job_type = ?';
            $params[] = $jobType;
        }
        
        if ($experienceMin !== null) {
            $where[] = 'j.experience_min <= ?';
            $params[] = $experienceMin;
        }
        
        // IMPORTANT: If the older schema doesn't have a `status` column and no filters are
        // provided, `$where` can be empty. `WHERE ` (blank) will throw a SQL syntax error.
        // Use a safe no-op clause instead.
        $whereClause = trim(implode(' AND ', $where));
        if ($whereClause === '') {
            $whereClause = '1=1';
        }
        
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM jobs j WHERE {$whereClause}";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];
        
        // Get jobs
        $sql = "
            SELECT j.*, u.company_name, u.email as employer_email
            FROM jobs j
            LEFT JOIN users u ON j.employer_id = u.id
            WHERE {$whereClause}
            ORDER BY j.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $jobs = $stmt->fetchAll();
        
        // Format jobs
        $formattedJobs = [];
        foreach ($jobs as $job) {
            $formattedJobs[] = [
                'id' => (int)$job['id'],
                'title' => $job['title'],
                'description' => $job['description'],
                'skills' => $job['skills'],
                'location' => $job['location'],
                // Older schemas may not have these columns; default safely.
                'experience_min' => isset($job['experience_min']) ? (int)$job['experience_min'] : 0,
                'experience_max' => isset($job['experience_max']) && $job['experience_max'] !== null ? (int)$job['experience_max'] : null,
                'salary_min' => isset($job['salary_min']) && $job['salary_min'] !== null ? (float)$job['salary_min'] : null,
                'salary_max' => isset($job['salary_max']) && $job['salary_max'] !== null ? (float)$job['salary_max'] : null,
                'job_type' => $job['job_type'] ?? 'full-time',
                'status' => $job['status'] ?? 'active',
                'company_name' => $job['company_name'],
                'created_at' => $job['created_at'] ?? null,
            ];
        }
        
        respondJson([
            'success' => true,
            'jobs' => $formattedJobs,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
    } catch (Exception $e) {
        respondJson(['error' => 'Failed to fetch jobs', 'details' => $e->getMessage()], 500);
    }
}

// GET SINGLE JOB
else if ($action === 'get') {
    $jobId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($jobId <= 0) {
        respondJson(['error' => 'Invalid job ID'], 400);
    }
    
    try {
        $stmt = $pdo->prepare('
            SELECT j.*, u.company_name, u.email as employer_email, u.phone as employer_phone
            FROM jobs j
            LEFT JOIN users u ON j.employer_id = u.id
            WHERE j.id = ?
        ');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        
        if (!$job) {
            respondJson(['error' => 'Job not found'], 404);
        }
        
        $formattedJob = [
            'id' => (int)$job['id'],
            'title' => $job['title'],
            'description' => $job['description'],
            'skills' => $job['skills'],
            'location' => $job['location'],
            'experience_min' => (int)$job['experience_min'],
            'experience_max' => $job['experience_max'] ? (int)$job['experience_max'] : null,
            'salary_min' => $job['salary_min'] ? (float)$job['salary_min'] : null,
            'salary_max' => $job['salary_max'] ? (float)$job['salary_max'] : null,
            'job_type' => $job['job_type'],
            'status' => $job['status'],
            'company_name' => $job['company_name'],
            'created_at' => $job['created_at'],
        ];
        
        respondJson(['success' => true, 'job' => $formattedJob]);
        
    } catch (Exception $e) {
        respondJson(['error' => 'Failed to fetch job', 'details' => $e->getMessage()], 500);
    }
}

// GET EMPLOYER'S JOBS
else if ($action === 'my-jobs') {
    requireRole('employer');
    
    try {
        $userId = getCurrentUserId();
        
        $stmt = $pdo->prepare('
            SELECT j.*, 
                   COUNT(DISTINCT ja.id) as application_count
            FROM jobs j
            LEFT JOIN job_applications ja ON j.id = ja.job_id
            WHERE j.employer_id = ?
            GROUP BY j.id
            ORDER BY j.created_at DESC
        ');
        $stmt->execute([$userId]);
        $jobs = $stmt->fetchAll();
        
        $formattedJobs = [];
        foreach ($jobs as $job) {
            $formattedJobs[] = [
                'id' => (int)$job['id'],
                'title' => $job['title'],
                'description' => $job['description'],
                'skills' => $job['skills'],
                'location' => $job['location'],
                'experience_min' => (int)$job['experience_min'],
                'experience_max' => $job['experience_max'] ? (int)$job['experience_max'] : null,
                'salary_min' => $job['salary_min'] ? (float)$job['salary_min'] : null,
                'salary_max' => $job['salary_max'] ? (float)$job['salary_max'] : null,
                'job_type' => $job['job_type'],
                'status' => $job['status'],
                'application_count' => (int)$job['application_count'],
                'created_at' => $job['created_at'],
            ];
        }
        
        respondJson(['success' => true, 'jobs' => $formattedJobs]);
        
    } catch (Exception $e) {
        respondJson(['error' => 'Failed to fetch jobs', 'details' => $e->getMessage()], 500);
    }
}

// UPDATE JOB
else if ($action === 'update') {
    requireRole('employer');
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondJson(['error' => 'Method not allowed'], 405);
    }
    
    $input = getJsonInput();
    $jobId = isset($input['id']) ? (int)$input['id'] : 0;
    
    if ($jobId <= 0) {
        respondJson(['error' => 'Invalid job ID'], 400);
    }
    
    try {
        $userId = getCurrentUserId();
        
        // Verify ownership
        $stmt = $pdo->prepare('SELECT id FROM jobs WHERE id = ? AND employer_id = ?');
        $stmt->execute([$jobId, $userId]);
        if (!$stmt->fetch()) {
            respondJson(['error' => 'Job not found or access denied'], 404);
        }
        
        // Build update query
        $updates = [];
        $params = [];
        
        if (isset($input['title'])) {
            $updates[] = 'title = ?';
            $params[] = trim($input['title']);
        }
        if (isset($input['description'])) {
            $updates[] = 'description = ?';
            $params[] = trim($input['description']);
        }
        if (isset($input['skills'])) {
            $updates[] = 'skills = ?';
            $params[] = trim($input['skills']);
        }
        if (isset($input['location'])) {
            $updates[] = 'location = ?';
            $params[] = trim($input['location']);
        }
        if (isset($input['experience_min'])) {
            $updates[] = 'experience_min = ?';
            $params[] = (int)$input['experience_min'];
        }
        if (isset($input['experience_max'])) {
            $updates[] = 'experience_max = ?';
            $params[] = (int)$input['experience_max'];
        }
        if (isset($input['salary_min'])) {
            $updates[] = 'salary_min = ?';
            $params[] = (float)$input['salary_min'];
        }
        if (isset($input['salary_max'])) {
            $updates[] = 'salary_max = ?';
            $params[] = (float)$input['salary_max'];
        }
        if (isset($input['job_type'])) {
            $updates[] = 'job_type = ?';
            $params[] = trim($input['job_type']);
        }
        if (isset($input['status'])) {
            $updates[] = 'status = ?';
            $params[] = trim($input['status']);
        }
        
        if (empty($updates)) {
            respondJson(['error' => 'No fields to update'], 400);
        }
        
        $params[] = $jobId;
        $sql = 'UPDATE jobs SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        respondJson(['success' => true, 'message' => 'Job updated successfully']);
        
    } catch (Exception $e) {
        respondJson(['error' => 'Failed to update job', 'details' => $e->getMessage()], 500);
    }
}

// DELETE JOB
else if ($action === 'delete') {
    requireRole('employer');
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondJson(['error' => 'Method not allowed'], 405);
    }
    
    $input = getJsonInput();
    $jobId = isset($input['id']) ? (int)$input['id'] : 0;
    
    if ($jobId <= 0) {
        respondJson(['error' => 'Invalid job ID'], 400);
    }
    
    try {
        $userId = getCurrentUserId();
        
        // Verify ownership
        $stmt = $pdo->prepare('SELECT id FROM jobs WHERE id = ? AND employer_id = ?');
        $stmt->execute([$jobId, $userId]);
        if (!$stmt->fetch()) {
            respondJson(['error' => 'Job not found or access denied'], 404);
        }
        
        // Delete job
        $stmt = $pdo->prepare('DELETE FROM jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        
        respondJson(['success' => true, 'message' => 'Job deleted successfully']);
        
    } catch (Exception $e) {
        respondJson(['error' => 'Failed to delete job', 'details' => $e->getMessage()], 500);
    }
}

else {
    respondJson(['error' => 'Invalid action'], 400);
}
