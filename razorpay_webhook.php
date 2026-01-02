<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/db.php';

$config = require __DIR__ . '/config.php';

function respondJson($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Log webhook for debugging
$logFile = __DIR__ . '/../logs/razorpay_webhook.log';
$webhookData = file_get_contents('php://input');
$headers = getallheaders();

@mkdir(dirname($logFile), 0755, true);
file_put_contents($logFile, date('Y-m-d H:i:s') . " - Webhook received\n" . $webhookData . "\n\n", FILE_APPEND);

// Verify webhook signature
$webhookSecret = $config['razorpay']['webhook_secret'];
$webhookSignature = $headers['X-Razorpay-Signature'] ?? '';

$expectedSignature = hash_hmac('sha256', $webhookData, $webhookSecret);

if ($webhookSignature !== $expectedSignature) {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Invalid signature\n\n", FILE_APPEND);
    respondJson(['error' => 'Invalid signature'], 403);
}

// Parse webhook payload
$payload = json_decode($webhookData, true);

if (!$payload || !isset($payload['event'])) {
    respondJson(['error' => 'Invalid payload'], 400);
}

$event = $payload['event'];
$paymentData = $payload['payload']['payment']['entity'] ?? [];

file_put_contents($logFile, date('Y-m-d H:i:s') . " - Event: {$event}\n", FILE_APPEND);

// Handle payment.captured event
if ($event === 'payment.captured') {
    $paymentId = $payload['payload']['payment']['entity']['id'] ?? '';
    $amount = (int)($payload['payload']['payment']['entity']['amount'] ?? 0) / 100; // Convert paise to rupees
    $notes = $payload['payload']['payment']['entity']['notes'] ?? [];
    $userId = (int)($notes['user_id'] ?? 0);
    $tokens = (int)($notes['tokens'] ?? 0);
    
    if ($userId > 0 && $tokens > 0) {
        try {
            // Check if payment already processed
            $stmt = $pdo->prepare('SELECT id FROM payments WHERE razorpay_payment_id = ?');
            $stmt->execute([$paymentId]);
            if ($stmt->fetch()) {
                file_put_contents($logFile, date('Y-m-d H:i:s') . " - Payment already processed\n\n", FILE_APPEND);
                respondJson(['success' => true, 'message' => 'Already processed']);
            }
            
            $pdo->beginTransaction();
            
            // Add tokens to employer account
            $stmt = $pdo->prepare('
                UPDATE employer_tokens 
                SET token_balance = token_balance + ?, total_earned = total_earned + ?
                WHERE user_id = ?
            ');
            $stmt->execute([$tokens, $tokens, $userId]);
            
            // Log token transaction
            $stmt = $pdo->prepare('
                INSERT INTO token_transactions (user_id, transaction_type, tokens, description)
                VALUES (?, "topup", ?, ?)
            ');
            $stmt->execute([$userId, $tokens, "Razorpay payment: {$paymentId}"]);
            
            // Record payment
            $stmt = $pdo->prepare('
                INSERT INTO payments (user_id, razorpay_payment_id, amount, tokens, status, payment_method)
                VALUES (?, ?, ?, ?, "success", ?)
            ');
            $stmt->execute([$userId, $paymentId, $amount, $tokens, $paymentData['method'] ?? 'unknown']);
            
            $pdo->commit();
            
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - Success: Added {$tokens} tokens to user {$userId}\n\n", FILE_APPEND);
            
            respondJson(['success' => true, 'message' => 'Payment processed']);
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n\n", FILE_APPEND);
            respondJson(['error' => 'Processing failed', 'details' => $e->getMessage()], 500);
        }
    } else {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Invalid notes: user_id or tokens missing\n\n", FILE_APPEND);
    }
}

respondJson(['success' => true, 'message' => 'Webhook received']);
