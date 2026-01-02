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

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? 'list';

// APPLY TO JOB (Job Seeker only)
if ($action === 'apply') {
    requireRole('jobseeker');
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondJson(['error' => 'Method not allowed'], 405);
    }
    
    $input = getJsonInput();
    $jobId = isset($input['job_id']) ? (int)$input['job_id'] : 0;
    $resumeText = trim($input['resume_text'] ?? '');
    $coverLetter = trim($input['cover_letter'] ?? '');
    
    if ($jobId <= 0) {
        respondJson(['error' => 'Invalid job ID'], 400);
    }
    
    try {
        $userId = getCurrentUserId();
        
        // Check if job exists and is active
        $stmt = $pdo->prepare('SELECT id, title FROM jobs WHERE id = ? AND status = "active"');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        
        if (!$job) {
            respondJson(['error' => 'Job not found or is no longer active'], 404);
        }
        
        // Check if already applied
        $stmt = $pdo->prepare('SELECT id FROM job_applications WHERE job_id = ? AND jobseeker_id = ?');
        $stmt->execute([$jobId, $userId]);
        if ($stmt->fetch()) {
            respondJson(['error' => 'You have already applied to this job'], 400);
        }
        
        // Insert application
        $stmt = $pdo->prepare('
            INSERT INTO job_applications (job_id, jobseeker_id, resume_text, cover_letter)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$jobId, $userId, $resumeText, $coverLetter]);
        
        // Extract keywords from resume for learning
        if (!empty($resumeText)) {
            $keywords = extractKeywordsFromText($resumeText);
            foreach ($keywords as $kw) {
                try {
                    $stmt = $pdo->prepare('
                        INSERT INTO search_keywords (keyword, frequency, source)
                        VALUES (?, 1, "resume")
                        ON DUPLICATE KEY UPDATE frequency = frequency + 1
                    ');
                    $stmt->execute([strtolower($kw)]);
                } catch (Exception $e) {
                    // Continue even if keyword insertion fails
                }
            }
        }
        
        respondJson([
            'success' => true,
            'message' => 'Application submitted successfully'
        ]);
        
    } catch (Exception $e) {
        respondJson(['error' => 'Failed to submit application', 'details' => $e->getMessage()], 500);
    }
}

// GET MY APPLICATIONS (Job Seeker)
else if ($action === 'my-applications') {
    requireRole('jobseeker');
    
    try {
        $userId = getCurrentUserId();
        
        $stmt = $pdo->prepare('
            SELECT ja.*, j.title, j.location, j.job_type,
                   u.company_name AS company_name,
                   u.company_name AS employer_company
            FROM job_applications ja
            LEFT JOIN jobs j ON ja.job_id = j.id
            LEFT JOIN users u ON j.employer_id = u.id
            WHERE ja.jobseeker_id = ?
            ORDER BY ja.applied_at DESC
        ');
        $stmt->execute([$userId]);
        $applications = $stmt->fetchAll();
        
        $formatted = [];
        foreach ($applications as $app) {
            $formatted[] = [
                'id' => (int)$app['id'],
                'job_id' => (int)$app['job_id'],
                'job_title' => $app['title'],
                'location' => $app['location'],
                'job_type' => $app['job_type'],
                'company_name' => $app['employer_company'],
                'status' => $app['status'],
                'applied_at' => $app['applied_at'],
            ];
        }
        
        respondJson(['success' => true, 'applications' => $formatted]);
        
    } catch (Exception $e) {
        respondJson(['error' => 'Failed to fetch applications', 'details' => $e->getMessage()], 500);
    }
}

// GET APPLICATIONS FOR A JOB (Employer)
// Backwards-compatible: some frontends call this as action=list-by-job
else if ($action === 'job-applications' || $action === 'list-by-job') {
    requireRole('employer');
    
    $jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
    
    if ($jobId <= 0) {
        respondJson(['error' => 'Invalid job ID'], 400);
    }
    
    try {
        $userId = getCurrentUserId();
        
        // Verify job ownership
        $stmt = $pdo->prepare('SELECT id FROM jobs WHERE id = ? AND employer_id = ?');
        $stmt->execute([$jobId, $userId]);
        if (!$stmt->fetch()) {
            respondJson(['error' => 'Job not found or access denied'], 404);
        }
        
        // Get applications
        $stmt = $pdo->prepare('
            SELECT ja.*, u.full_name, u.email, u.phone
            FROM job_applications ja
            LEFT JOIN users u ON ja.jobseeker_id = u.id
            WHERE ja.job_id = ?
            ORDER BY ja.applied_at DESC
        ');
        $stmt->execute([$jobId]);
        $applications = $stmt->fetchAll();
        
        $formatted = [];
        foreach ($applications as $app) {
            $formatted[] = [
                'id' => (int)$app['id'],
                'applicant_name' => $app['full_name'],
                'applicant_email' => $app['email'],
                'applicant_phone' => $app['phone'],
                'resume_text' => $app['resume_text'],
                'cover_letter' => $app['cover_letter'],
                'status' => $app['status'],
                'applied_at' => $app['applied_at'],
            ];
        }
        
        respondJson(['success' => true, 'applications' => $formatted]);
        
    } catch (Exception $e) {
        respondJson(['error' => 'Failed to fetch applications', 'details' => $e->getMessage()], 500);
    }
}

// UPDATE APPLICATION STATUS (Employer)
else if ($action === 'update-status') {
    requireRole('employer');
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondJson(['error' => 'Method not allowed'], 405);
    }
    
    $input = getJsonInput();
    $applicationId = isset($input['application_id']) ? (int)$input['application_id'] : 0;
    $status = trim($input['status'] ?? '');
    
    if ($applicationId <= 0) {
        respondJson(['error' => 'Invalid application ID'], 400);
    }
    
    if (!in_array($status, ['applied', 'shortlisted', 'rejected', 'hired'])) {
        respondJson(['error' => 'Invalid status'], 400);
    }
    
    try {
        $userId = getCurrentUserId();
        
        // Verify ownership through job
        $stmt = $pdo->prepare('
            SELECT ja.id 
            FROM job_applications ja
            LEFT JOIN jobs j ON ja.job_id = j.id
            WHERE ja.id = ? AND j.employer_id = ?
        ');
        $stmt->execute([$applicationId, $userId]);
        if (!$stmt->fetch()) {
            respondJson(['error' => 'Application not found or access denied'], 404);
        }
        
        // Update status
        $stmt = $pdo->prepare('UPDATE job_applications SET status = ? WHERE id = ?');
        $stmt->execute([$status, $applicationId]);
        
        respondJson(['success' => true, 'message' => 'Application status updated']);
        
    } catch (Exception $e) {
        respondJson(['error' => 'Failed to update status', 'details' => $e->getMessage()], 500);
    }
}

else {
    respondJson(['error' => 'Invalid action'], 400);
}

// Helper function to extract keywords from text
function extractKeywordsFromText($text) {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s\+\#]/', ' ', $text);
    $words = preg_split('/\s+/', $text);
    
    $stopWords = [
        'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
        'of', 'with', 'by', 'from', 'as', 'is', 'was', 'are', 'were', 'be',
        'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'should',
        'can', 'could', 'may', 'might', 'must', 'shall', 'i', 'you', 'he',
        'she', 'it', 'we', 'they', 'my', 'your', 'his', 'her', 'its', 'our',
        'their', 'this', 'that', 'these', 'those', 'am', 'been', 'being'
    ];
    
    $keywords = [];
    foreach ($words as $word) {
        $word = trim($word);
        if (strlen($word) >= 3 && !in_array($word, $stopWords)) {
            $keywords[] = $word;
        }
    }
    
    // Get top 20 most frequent keywords
    $counts = array_count_values($keywords);
    arsort($counts);
    return array_slice(array_keys($counts), 0, 20);
}
