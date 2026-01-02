<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/session.php';
require __DIR__ . '/admin_config.php';

$config = getConfigArray();

header('Content-Type: application/json; charset=utf-8');

function respond_json($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Check admin session
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    respond_json(['error' => 'Admin authentication required'], 401);
}

$action = $_GET['action'] ?? '';

if ($action === 'summary') {
    try {
        $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}";
        $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        // Total users
        $stmt = $pdo->query('SELECT COUNT(*) as total FROM users');
        $totalUsers = (int)$stmt->fetch()['total'];
        
        // Employers
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'employer'");
        $employers = (int)$stmt->fetch()['total'];
        
        // Job seekers
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'jobseeker'");
        $jobseekers = (int)$stmt->fetch()['total'];
        
        // Active jobs
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM jobs WHERE status = 'active'");
        $activeJobs = (int)$stmt->fetch()['total'];
        
        // Total applications
        $stmt = $pdo->query('SELECT COUNT(*) as total FROM job_applications');
        $applications = (int)$stmt->fetch()['total'];
        
        // Token stats
        $stmt = $pdo->query('SELECT SUM(token_balance) as total FROM employer_tokens');
        $totalTokens = (int)($stmt->fetch()['total'] ?? 0);
        
        respond_json([
            'success' => true,
            'total_users' => $totalUsers,
            'employers' => $employers,
            'jobseekers' => $jobseekers,
            'active_jobs' => $activeJobs,
            'applications' => $applications,
            'total_tokens_in_circulation' => $totalTokens
        ]);
        
    } catch (Exception $e) {
        respond_json(['error' => 'Failed to fetch stats', 'details' => $e->getMessage()], 500);
    }
}

respond_json(['error' => 'Invalid action'], 400);
