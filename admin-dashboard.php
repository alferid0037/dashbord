<?php
require_once 'config.php';

// Check if session is not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if admin is logged in
if (!isset($_SESSION['professional_logged_in']) || $_SESSION['professional_role'] !== 'admin') {
    header('Location: admin-login.php');
    exit();
}

// Database helper function
function getPDO() {
    static $pdo;
    if (!$pdo) {
        try {
            $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}

// Enhanced Messaging System Class
class MessagingSystem {
    private $pdo;
    
    public function __construct() {
        $this->pdo = getPDO();
    }
    
    public function sendMessage($sender_id, $recipient_id, $subject, $message) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO messages (sender_id, recipient_id, subject, message, created_at, is_read) 
                VALUES (?, ?, ?, ?, NOW(), 0)
            ");
            
            $result = $stmt->execute([$sender_id, $recipient_id, $subject, $message]);
            
            if ($result) {
                $this->createNotification($recipient_id, 'New Message', "You have a new message: " . $subject);
                return ['success' => true, 'message' => 'Message sent successfully'];
            }
            
            return ['success' => false, 'message' => 'Failed to send message'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function getMessages($user_id, $limit = 50) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT m.*, 
                       u.username as sender_name,
                       u.role as sender_role,
                       u.organization as sender_organization
                FROM messages m 
                JOIN professional_users u ON m.sender_id = u.id 
                WHERE m.recipient_id = ? 
                ORDER BY m.created_at DESC 
                LIMIT ?
            ");
            
            $stmt->execute([$user_id, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            return [];
        }
    }
    
    public function getSentMessages($user_id, $limit = 50) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT m.*, 
                       u.username as recipient_name,
                       u.role as recipient_role,
                       u.organization as recipient_organization
                FROM messages m 
                JOIN professional_users u ON m.recipient_id = u.id 
                WHERE m.sender_id = ? 
                ORDER BY m.created_at DESC 
                LIMIT ?
            ");
            
            $stmt->execute([$user_id, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            return [];
        }
    }
    
    public function getMessage($message_id, $user_id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT m.*, 
                       sender.username as sender_name,
                       sender.role as sender_role,
                       recipient.username as recipient_name,
                       recipient.role as recipient_role
                FROM messages m 
                JOIN professional_users sender ON m.sender_id = sender.id 
                JOIN professional_users recipient ON m.recipient_id = recipient.id 
                WHERE m.id = ? AND (m.sender_id = ? OR m.recipient_id = ?)
            ");
            
            $stmt->execute([$message_id, $user_id, $user_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            return null;
        }
    }
    
    public function markAsRead($message_id, $user_id) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE messages 
                SET is_read = 1 
                WHERE id = ? AND recipient_id = ?
            ");
            
            return $stmt->execute([$message_id, $user_id]);
            
        } catch (PDOException $e) {
            return false;
        }
    }
    
    public function getUnreadCount($user_id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count 
                FROM messages 
                WHERE recipient_id = ? AND is_read = 0
            ");
            
            $stmt->execute([$user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'];
            
        } catch (PDOException $e) {
            return 0;
        }
    }
    
    public function deleteMessage($message_id, $user_id) {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM messages 
                WHERE id = ? AND (sender_id = ? OR recipient_id = ?)
            ");
            
            return $stmt->execute([$message_id, $user_id, $user_id]);
            
        } catch (PDOException $e) {
            return false;
        }
    }
    
    public function getAvailableRecipients($current_user_id, $role_filter = null) {
        try {
            $query = "
                SELECT id, username, role, organization 
                FROM professional_users 
                WHERE id != ? AND status = 'active'
            ";
            
            $params = [$current_user_id];
            
            if ($role_filter && in_array($role_filter, ['admin', 'coach', 'medical', 'club', 'scout'])) {
                $query .= " AND role = ?";
                $params[] = $role_filter;
            }
            
            $query .= " ORDER BY role ASC, username ASC";
            
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            return [];
        }
    }
    
    private function createNotification($recipient_id, $title, $message) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (recipient_id, title, message, created_at, is_read) 
                VALUES (?, ?, ?, NOW(), 0)
            ");
            
            return $stmt->execute([$recipient_id, $title, $message]);
            
        } catch (PDOException $e) {
            return false;
        }
    }
    
    public function searchMessages($user_id, $search_term, $limit = 20) {
        try {
            $search_term = '%' . $search_term . '%';
            
            $stmt = $this->pdo->prepare("
                SELECT m.*, 
                       u.username as sender_name,
                       u.role as sender_role
                FROM messages m 
                JOIN professional_users u ON m.sender_id = u.id 
                WHERE m.recipient_id = ? 
                AND (m.subject LIKE ? OR m.message LIKE ? OR u.username LIKE ?)
                ORDER BY m.created_at DESC 
                LIMIT ?
            ");
            
            $stmt->execute([$user_id, $search_term, $search_term, $search_term, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            return [];
        }
    }
}

// Initialize messaging system
$messaging = new MessagingSystem();

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $pdo = getPDO();
    
    switch ($_POST['action']) {
        case 'toggle_user_status':
            $user_id = (int)$_POST['user_id'];
            $status = $_POST['status'] === 'active' ? 'inactive' : 'active';
            
            $stmt = $pdo->prepare("UPDATE professional_users SET status = ? WHERE id = ?");
            $result = $stmt->execute([$status, $user_id]);
            
            echo json_encode(['success' => $result, 'new_status' => $status]);
            exit();
            
        case 'update_permission':
            $role = $_POST['role'];
            $permission = $_POST['permission'];
            $enabled = $_POST['enabled'] === 'true';
            
            $stmt = $pdo->prepare("UPDATE role_permissions SET is_enabled = ? WHERE role = ? AND permission = ?");
            $result = $stmt->execute([$enabled, $role, $permission]);
            
            echo json_encode(['success' => $result]);
            exit();
            
        case 'approve_player':
            $player_id = (int)$_POST['player_id'];
            $action = $_POST['approval_action'];
            
            $stmt = $pdo->prepare("UPDATE player_registrations SET registration_status = ? WHERE id = ?");
            $result = $stmt->execute([$action, $player_id]);
            
            echo json_encode(['success' => $result]);
            exit();
            
        case 'reject_player':
            $player_id = (int)$_POST['player_id'];
            $stmt = $pdo->prepare("UPDATE player_registrations SET registration_status = 'rejected' WHERE id = ?");
            $result = $stmt->execute([$player_id]);
            
            echo json_encode(['success' => $result]);
            exit();
            
        case 'delete_player':
            $player_id = (int)$_POST['player_id'];
            $stmt = $pdo->prepare("DELETE FROM player_registrations WHERE id = ?");
            $result = $stmt->execute([$player_id]);
            
            echo json_encode(['success' => $result]);
            exit();
            
        case 'update_setting':
            $key = $_POST['key'];
            $value = $_POST['value'];
            
            $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $result = $stmt->execute([$value, $key]);
            
            echo json_encode(['success' => $result]);
            exit();
            
        case 'update_settings_bulk':
            $success = true;
            foreach ($_POST['settings'] as $key => $value) {
                $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
                if (!$stmt->execute([$value, $key])) {
                    $success = false;
                }
            }
            echo json_encode(['success' => $success]);
            exit();
            
        // Enhanced Messaging Actions
        case 'send_message':
            $sender_id = (int)$_SESSION['professional_id'];
            $recipient_id = (int)$_POST['recipient_id'];
            $subject = trim($_POST['subject']);
            $message = trim($_POST['message']);
            
            if (empty($recipient_id) || empty($subject) || empty($message)) {
                echo json_encode(['success' => false, 'message' => 'All fields are required']);
                exit();
            }
            
            // Verify recipient exists and is active
            $stmt = $pdo->prepare("SELECT id, username FROM professional_users WHERE id = ? AND status = 'active'");
            $stmt->execute([$recipient_id]);
            $recipient = $stmt->fetch();
            
            if (!$recipient) {
                echo json_encode(['success' => false, 'message' => 'Invalid recipient selected']);
                exit();
            }
            
            // Insert message
            $result = $messaging->sendMessage($sender_id, $recipient_id, $subject, $message);
            echo json_encode($result);
            exit();

        case 'get_messages':
            $user_id = (int)$_SESSION['professional_id'];
            $messages = $messaging->getMessages($user_id);
            echo json_encode($messages);
            exit();

        case 'get_sent_messages':
            $user_id = (int)$_SESSION['professional_id'];
            $messages = $messaging->getSentMessages($user_id);
            echo json_encode($messages);
            exit();

        case 'get_message':
            $message_id = (int)$_POST['message_id'];
            $user_id = (int)$_SESSION['professional_id'];
            $message = $messaging->getMessage($message_id, $user_id);
            
            if ($message) {
                if ($message['recipient_id'] == $user_id) {
                    $messaging->markAsRead($message_id, $user_id);
                }
                echo json_encode(['success' => true, 'message' => $message]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Message not found']);
            }
            exit();

        case 'mark_message_read':
            $message_id = (int)$_POST['message_id'];
            $user_id = (int)$_SESSION['professional_id'];
            $result = $messaging->markAsRead($message_id, $user_id);
            echo json_encode(['success' => $result]);
            exit();

        case 'delete_message':
            $message_id = (int)$_POST['message_id'];
            $user_id = (int)$_SESSION['professional_id'];
            $result = $messaging->deleteMessage($message_id, $user_id);
            echo json_encode(['success' => $result]);
            exit();

        case 'get_unread_count':
            $user_id = (int)$_SESSION['professional_id'];
            $count = $messaging->getUnreadCount($user_id);
            echo json_encode(['count' => $count]);
            exit();

        case 'get_recipients':
            $user_id = (int)$_SESSION['professional_id'];
            $role_filter = isset($_POST['role_filter']) && !empty($_POST['role_filter']) ? $_POST['role_filter'] : null;
            
            $recipients = $messaging->getAvailableRecipients($user_id, $role_filter);
            echo json_encode($recipients);
            exit();

        case 'search_messages':
            $user_id = (int)$_SESSION['professional_id'];
            $search_term = trim($_POST['search_term']);
            if (empty($search_term)) {
                echo json_encode([]);
                exit();
            }
            
            $messages = $messaging->searchMessages($user_id, $search_term);
            echo json_encode($messages);
            exit();
            
        case 'approve_document':
            $doc_id = (int)$_POST['doc_id'];
            $stmt = $pdo->prepare("SELECT club_id FROM club_documents WHERE id = ?");
            $stmt->execute([$doc_id]);
            $document = $stmt->fetch();
            
            if ($document) {
                $stmt = $pdo->prepare("UPDATE club_licenses SET is_approved = 1 WHERE user_id = ?");
                $result = $stmt->execute([$document['club_id']]);
                echo json_encode(['success' => $result]);
            } else {
                echo json_encode(['success' => false]);
            }
            exit();
            
        case 'get_notifications':
            $stmt = $pdo->prepare("SELECT * FROM notifications WHERE recipient_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
            $stmt->execute([$_SESSION['professional_id']]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_id = ? AND is_read = 0");
            $stmt->execute([$_SESSION['professional_id']]);
            
            echo json_encode($notifications);
            exit();
            
        case 'mark_notification_read':
            $notification_id = (int)$_POST['notification_id'];
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
            $result = $stmt->execute([$notification_id]);
            
            echo json_encode(['success' => $result]);
            exit();
    }
}

// Helper function to calculate age
function calculateAge($day, $month, $year) {
    $birthDate = "$year-$month-$day";
    $today = date("Y-m-d");
    $diff = date_diff(date_create($birthDate), date_create($today));
    return $diff->format('%y');
}

// Get dashboard statistics
function getDashboardStats() {
    $pdo = getPDO();
    $stats = [];
    
    $statuses = ['pending', 'approved', 'rejected', 'deleted'];
    foreach ($statuses as $status) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM player_registrations WHERE registration_status = ?");
        $stmt->execute([$status]);
        $stats[$status . '_players'] = $stmt->fetch()['count'];
    }
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM professional_users WHERE status = 'active'");
    $stats['active_users'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT * FROM admin_logs ORDER BY created_at DESC LIMIT 5");
    $stats['recent_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stats['weekly_activity'] = [
        ['day' => 'Mon', 'logins' => rand(20, 50)],
        ['day' => 'Tue', 'logins' => rand(25, 55)],
        ['day' => 'Wed', 'logins' => rand(30, 60)],
        ['day' => 'Thu', 'logins' => rand(35, 65)],
        ['day' => 'Fri', 'logins' => rand(40, 70)],
        ['day' => 'Sat', 'logins' => rand(15, 45)],
        ['day' => 'Sun', 'logins' => rand(10, 40)]
    ];
    
    $stats['scout_reports'] = 300 + rand(0, 50);
    $stats['injuries_tracked'] = 25 + rand(0, 10);
    $stats['active_clubs'] = 8;
    
    return $stats;
}

function getUsers($limit = 10) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT u.*, pr.first_name, pr.last_name, pr.registration_status 
        FROM professional_users u 
        LEFT JOIN player_registrations pr ON u.id = pr.user_id 
        ORDER BY u.created_at DESC 
        LIMIT ?
    ");

}

function getProfessionalUsers() {
    $pdo = getPDO();
    $stmt = $pdo->query("SELECT * FROM professional_users ORDER BY created_at DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRolePermissions() {
    $pdo = getPDO();
    $stmt = $pdo->query("SELECT * FROM role_permissions ORDER BY role, permission");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPlayers($filter = null) {
    $pdo = getPDO();
    $query = "SELECT pr.*, u.email FROM player_registrations pr JOIN users u ON pr.user_id = u.id";
    
    if ($filter && in_array($filter, ['pending', 'approved', 'rejected', 'deleted'])) {
        $query .= " WHERE pr.registration_status = ?";
    }
    
    $query .= " ORDER BY pr.created_at DESC";
    $stmt = $pdo->prepare($query);
    
    if ($filter && in_array($filter, ['pending', 'approved', 'rejected', 'deleted'])) {
        $stmt->execute([$filter]);
    } else {
        $stmt->execute();
    }
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSystemSettings() {
    $pdo = getPDO();
    $stmt = $pdo->query("SELECT * FROM system_settings");
    $settings = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $setting) {
        $settings[$setting['setting_key']] = $setting;
    }
    return $settings;
}

// Get data for dashboard
$stats = getDashboardStats();
$users = getUsers(10);
$professional_users = getProfessionalUsers();
$role_permissions = getRolePermissions();
$filter = $_GET['filter'] ?? null;
$players = getPlayers($filter);
$system_settings = getSystemSettings();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vertwal Academy - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #1e3c72;
            --secondary: #2a5298;
            --accent: #f8d458;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --light: #f8f9fa;
            --dark: #343a40;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light);
            color: var(--dark);
        }

        .admin-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-section img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: 2px solid var(--accent);
        }

        .admin-title {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .admin-subtitle {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .notification-icon, .settings-icon {
            position: relative;
            padding: 0.5rem;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .notification-icon:hover, .settings-icon:hover {
            background: rgba(255,255,255,0.2);
            transform: scale(1.1);
        }

        .admin-user {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
        }

        .nav-tabs {
            background: white;
            border-bottom: 1px solid #dee2e6;
            padding: 0 2rem;
            overflow-x: auto;
        }

        .nav-tabs ul {
            display: flex;
            list-style: none;
            margin: 0;
            padding: 0;
            max-width: 1400px;
            margin: 0 auto;
        }

        .nav-tabs li {
            margin-right: 2rem;
        }

        .nav-tabs a {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 0;
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .nav-tabs a:hover, .nav-tabs a.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .dashboard-section {
            display: none;
        }

        .dashboard-section.active {
            display: block;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
        }

        .stat-card.success::before { background: var(--success); }
        .stat-card.warning::before { background: var(--warning); }
        .stat-card.info::before { background: var(--info); }
        .stat-card.danger::before { background: var(--danger); }

        .stat-card h3 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: var(--primary);
        }

        .stat-card p {
            color: #666;
            margin-bottom: 0;
        }

        .stat-card i {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 2rem;
            opacity: 0.3;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .chart-container {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .chart-title {
            font-size: 1.2rem;
            margin-bottom: 1rem;
            color: var(--primary);
        }

        .data-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .table-header {
            background: var(--primary);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
        }

        .table-content {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--dark);
        }

        tr:hover {
            background: #f8f9fa;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.85rem;
        }

        .btn-primary { background: var(--primary); color: white; }
        .btn-success { background: var(--success); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-info { background: var(--info); color: white; }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .status-suspended { background: #f8d7da; color: #721c24; }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--success);
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        .permissions-grid {
            display: grid;
            gap: 1.5rem;
        }

        .role-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .role-header {
            background: var(--primary);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-permissions {
            padding: 1.5rem;
        }

        .permission-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #eee;
        }

        .permission-item:last-child {
            border-bottom: none;
        }

        .settings-grid {
            display: grid;
            gap: 1.5rem;
        }

        .setting-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid #eee;
        }

        .setting-item:last-child {
            border-bottom: none;
        }

        .setting-description {
            color: #666;
            font-size: 0.9rem;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            overflow-y: auto;
        }

        .modal-content {
            background: white;
            margin: 2rem auto;
            padding: 2rem;
            border-radius: 10px;
            max-width: 800px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
        }

        .close-modal {
            font-size: 1.5rem;
            cursor: pointer;
            background: none;
            border: none;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 1000;
            display: none;
            max-height: 500px;
            overflow-y: auto;
        }

        .notification-dropdown.show {
            display: block;
        }

        .notification-header {
            padding: 1rem;
            border-bottom: 1px solid #eee;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-item {
            padding: 1rem;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background 0.2s;
        }

        .notification-item:hover {
            background: #f8f9fa;
        }

        .notification-item.unread {
            background: #f0f7ff;
        }

        .notification-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .notification-time {
            font-size: 0.8rem;
            color: #666;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
        }

        .role-filter-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .role-filter-btn {
            padding: 8px 16px;
            border: 2px solid var(--primary);
            background: transparent;
            color: var(--primary);
            border-radius: 20px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }

        .role-filter-btn:hover,
        .role-filter-btn.active {
            background: var(--primary);
            color: white;
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }

            .main-content {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .charts-grid {
                grid-template-columns: 1fr;
            }

            .nav-tabs {
                padding: 0 1rem;
            }

            .nav-tabs li {
                margin-right: 1rem;
            }
        }

        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <div class="header-content">
            <div class="logo-section">
                <img src="images\Football Award Vector.jpg" alt="Vertwal Academy">
                <div>
                    <div class="admin-title">Admin Panel – System Control</div>
                    <div class="admin-subtitle">Ethiopian Online Football Scouting</div>
                </div>
            </div>
            
            <div class="header-actions">
                <div class="notification-icon" id="notificationIcon" style="position: relative;">
                    <i class="fas fa-bell"></i>
                    <div class="notification-badge" id="notificationBadge" style="display: none;">0</div>
                    <div class="notification-dropdown" id="notificationDropdown">
                        <div class="notification-header">
                            <span>Notifications</span>
                            <button onclick="markAllNotificationsRead()" style="background: none; border: none; color: var(--primary); cursor: pointer;">
                                Mark all as read
                            </button>
                        </div>
                        <div id="notificationList">
                            <!-- Notifications will be loaded here -->
                        </div>
                    </div>
                </div>
                <div class="settings-icon" onclick="openModal('settingsModal')">
                    <i class="fas fa-cog"></i>
                </div>
                <div class="admin-user">
                    <i class="fas fa-user-shield"></i>
                    <span><?php echo htmlspecialchars($_SESSION['professional_name'] ?? 'Admin'); ?></span>
                </div>
            </div>
        </div>
    </header>

    <nav class="nav-tabs">
        <ul>
            <li><a href="#" class="nav-link active" data-section="dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard Overview</a></li>
            <li><a href="#" class="nav-link" data-section="players"><i class="fas fa-users"></i> Player Management</a></li>
            <li><a href="#" class="nav-link" data-section="users"><i class="fas fa-user-tie"></i> User Management</a></li>
            <li><a href="#" class="nav-link" data-section="roles"><i class="fas fa-user-cog"></i> Access Roles</a></li>
            <li><a href="#" class="nav-link" data-section="settings"><i class="fas fa-cog"></i> System Settings</a></li>
            <li><a href="#" class="nav-link" data-section="messages"><i class="fas fa-envelope"></i> Messaging</a></li>
            <li><a href="#" class="nav-link" data-section="reports"><i class="fas fa-chart-bar"></i> Reports & Logs</a></li>
        </ul>
    </nav>

    <main class="main-content">
        <!-- Dashboard Overview -->
        <section id="dashboard" class="dashboard-section active">
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><?php echo $stats['pending_players']; ?></h3>
                    <p>Pending Registrations</p>
                    <i class="fas fa-user-clock"></i>
                </div>
                <div class="stat-card success">
                    <h3><?php echo $stats['approved_players']; ?></h3>
                    <p>Approved Players</p>
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-card warning">
                    <h3><?php echo $stats['rejected_players']; ?></h3>
                    <p>Rejected Registrations</p>
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="stat-card info">
                    <h3><?php echo $stats['deleted_players']; ?></h3>
                    <p>Deleted Players</p>
                    <i class="fas fa-trash"></i>
                </div>
            </div>

            <div class="charts-grid">
                <div class="chart-container">
                    <h3 class="chart-title">Weekly User Activity</h3>
                    <canvas id="activityChart" width="400" height="200"></canvas>
                </div>
                <div class="chart-container">
                    <h3 class="chart-title">User Distribution by Role</h3>
                    <canvas id="roleChart" width="300" height="300"></canvas>
                </div>
            </div>

           
                        
        </section>

        <!-- Player Management -->
        <section id="players" class="dashboard-section">
            <div class="data-table">
                <div class="table-header">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Player Registrations</span>
                        <div>
                            <select onchange="filterPlayers(this.value)" style="padding: 0.5rem; border-radius: 5px; border: 1px solid #ddd;">
                                <option value="">All Statuses</option>
                                <option value="pending" <?= $filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="approved" <?= $filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                                <option value="rejected" <?= $filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                <option value="deleted" <?= $filter === 'deleted' ? 'selected' : '' ?>>Deleted</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="table-content">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Age</th>
                                <th>City</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($players as $player): 
                                $age = calculateAge($player['birth_day'], $player['birth_month'], $player['birth_year']);
                            ?>
                               <tr data-player-id="<?php echo $player['id']; ?>">
                                    <td><?php echo htmlspecialchars($player['first_name'] . ' ' . $player['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($player['email']); ?></td>
                                    <td><?php echo $age; ?> years</td>
                                    <td><?php echo htmlspecialchars($player['city']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $player['registration_status']; ?>">
                                            <?php echo ucfirst($player['registration_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-primary btn-sm" onclick="viewPlayerDetails(<?php echo $player['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if ($player['registration_status'] === 'pending'): ?>
                                            <button class="btn btn-success btn-sm" onclick="approvePlayer(<?php echo $player['id']; ?>, 'approved')">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                            <button class="btn btn-danger btn-sm" onclick="approvePlayer(<?php echo $player['id']; ?>, 'rejected')">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        <?php elseif ($player['registration_status'] !== 'deleted'): ?>
                                            <button class="btn btn-danger btn-sm" onclick="deletePlayer(<?php echo $player['id']; ?>)">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- User Management -->
        <section id="users" class="dashboard-section">
            <div class="data-table">
                <div class="table-header">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Manage Users & Roles</span>
                        <button class="btn btn-success" onclick="addNewUser()">
                            <i class="fas fa-plus"></i> Add New User
                        </button>
                    </div>
                </div>
                <div class="table-content">
                    <table>
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th>Organization</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($professional_users as $prof_user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($prof_user['username']); ?></td>
                                <td>
                                    <span class="status-badge status-info">
                                        <?php echo ucfirst($prof_user['role']); ?>
                                    </span>
                                </td>
                                <td>
                                    <label class="toggle-switch">
                                        <input type="checkbox" <?php echo $prof_user['status'] === 'active' ? 'checked' : ''; ?> 
                                               onchange="toggleUserStatus(<?php echo $prof_user['id']; ?>, '<?php echo $prof_user['status']; ?>')">
                                        <span class="slider"></span>
                                    </label>
                                </td>
                                <td><?php echo $prof_user['last_login'] ? date('M j, Y', strtotime($prof_user['last_login'])) : 'Never'; ?></td>
                                <td><?php echo htmlspecialchars($prof_user['organization'] ?? 'N/A'); ?></td>
                                <td>
                                    <button class="btn btn-primary btn-sm" onclick="editUser(<?php echo $prof_user['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-warning btn-sm" onclick="resetPassword(<?php echo $prof_user['id']; ?>)">
                                        <i class="fas fa-key"></i>
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="lockAccount(<?php echo $prof_user['id']; ?>)">
                                        <i class="fas fa-lock"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Role-Based Access Control -->
        <section id="roles" class="dashboard-section">
            <h2 style="margin-bottom: 1.5rem; color: var(--primary);">Role-Based Access Control (RBAC)</h2>
            
            <div class="permissions-grid">
                <?php 
                $roles = ['scout', 'coach', 'medical', 'club'];
                $permissions_by_role = [];
                foreach ($role_permissions as $perm) {
                    $permissions_by_role[$perm['role']][] = $perm;
                }
                ?>
                
                <?php foreach ($roles as $role): ?>
                <div class="role-card">
                    <div class="role-header">
                        <i class="fas fa-<?php echo $role === 'scout' ? 'binoculars' : ($role === 'coach' ? 'whistle' : ($role === 'medical' ? 'user-md' : 'building')); ?>"></i>
                        <?php echo ucfirst($role); ?> Dashboard
                    </div>
                    <div class="role-permissions">
                        <?php if (isset($permissions_by_role[$role])): ?>
                            <?php foreach ($permissions_by_role[$role] as $permission): ?>
                            <div class="permission-item">
                                <div>
                                    <strong><?php echo ucwords(str_replace('_', ' ', $permission['permission'])); ?></strong>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" <?php echo $permission['is_enabled'] ? 'checked' : ''; ?> 
                                           onchange="updatePermission('<?php echo $role; ?>', '<?php echo $permission['permission']; ?>', this.checked)">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
           
        <!-- System Settings -->
        <section id="settings" class="dashboard-section">
            <h2 style="margin-bottom: 1.5rem; color: var(--primary);">System Controls</h2>
            
            <div class="settings-grid">
                <div class="setting-card">
                    <h3 style="margin-bottom: 1rem; color: var(--primary);">Security Settings</h3>
                    
                    <div class="setting-item">
                        <div>
                            <strong>Two-Factor Authentication</strong>
                            <div class="setting-description">Enable 2FA for enhanced security</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" <?php echo ($system_settings['two_factor_auth']['setting_value'] ?? 'false') === 'true' ? 'checked' : ''; ?> 
                                   onchange="updateSetting('two_factor_auth', this.checked)">
                            <span class="slider"></span>
                        </label>
                    </div>
                    
                    <div class="setting-item">
                        <div>
                            <strong>Login Attempt Tracking</strong>
                            <div class="setting-description">Track and log login attempts</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" <?php echo ($system_settings['login_tracking']['setting_value'] ?? 'true') === 'true' ? 'checked' : ''; ?> 
                                   onchange="updateSetting('login_tracking', this.checked)">
                            <span class="slider"></span>
                        </label>
                    </div>
                    
                    <div class="setting-item">
                        <div>
                            <strong>GDPR Compliance</strong>
                            <div class="setting-description">Enable GDPR data protection features</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" <?php echo ($system_settings['gdpr_compliance']['setting_value'] ?? 'true') === 'true' ? 'checked' : ''; ?> 
                                   onchange="updateSetting('gdpr_compliance', this.checked)">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                
                <div class="setting-card">
                    <h3 style="margin-bottom: 1rem; color: var(--primary);">System Maintenance</h3>
                    
                    <div class="setting-item">
                        <div>
                            <strong>Data Backup</strong>
                            <div class="setting-description">Create system backup</div>
                        </div>
                        <button class="btn btn-primary" onclick="createBackup()">
                            <i class="fas fa-download"></i> Backup Now
                        </button>
                    </div>
                    
                    <div class="setting-item">
                        <div>
                            <strong>Error Logs</strong>
                            <div class="setting-description">View system error logs</div>
                        </div>
                        <button class="btn btn-warning" onclick="viewErrorLogs()">
                            <i class="fas fa-exclamation-triangle"></i> View Logs
                        </button>
                    </div>
                    
                    <div class="setting-item">
                        <div>
                            <strong>System Status</strong>
                            <div class="setting-description">Check system health</div>
                        </div>
                        <button class="btn btn-info" onclick="checkSystemStatus()">
                            <i class="fas fa-heartbeat"></i> Check Status
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Enhanced Messaging Section -->
        <section id="messages" class="dashboard-section">
            <div class="data-table">
                <div class="table-header">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Messages</span>
                        <div>
                            <button class="btn btn-info" onclick="loadSentMessages()" style="margin-right: 10px;">
                                <i class="fas fa-paper-plane"></i> Sent Messages
                            </button>
                            <button class="btn btn-success" onclick="openNewMessageModal()">
                                <i class="fas fa-plus"></i> New Message
                            </button>
                        </div>
                    </div>
                </div>
                <div class="table-content">
                    <table>
                        <thead>
                            <tr>
                                <th>From/To</th>
                                <th>Subject</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="messagesList">
                            <!-- Messages will be loaded here via AJAX -->
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Reports & Logs -->
        <section id="reports" class="dashboard-section">
            <h2 style="margin-bottom: 1.5rem; color: var(--primary);">Reports & Analytics</h2>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><?php echo $stats['active_users']; ?></h3>
                    <p>Active Users This Month</p>
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-card success">
                    <h3><?php echo $stats['pending_players']; ?></h3>
                    <p>Pending Registrations</p>
                    <i class="fas fa-user-clock"></i>
                </div>
                <div class="stat-card warning">
                    <h3>95%</h3>
                    <p>System Uptime</p>
                    <i class="fas fa-server"></i>
                </div>
                <div class="stat-card info">
                    <h3>1.2GB</h3>
                    <p>Database Size</p>
                    <i class="fas fa-database"></i>
                </div>
            </div>
            
            <div class="data-table">
                <div class="table-header">System Activity Log</div>
                <div class="table-content">
                    <table>
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Action</th>
                                <th>User</th>
                                <th>IP Address</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['recent_activity'] as $activity): ?>
                            <tr>
                                <td><?php echo date('M j, Y H:i', strtotime($activity['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($activity['action']); ?></td>
                                <td><?php echo htmlspecialchars($activity['user'] ?? 'system'); ?></td>
                                <td><?php echo htmlspecialchars($activity['ip_address'] ?? 'N/A'); ?></td>
                                <td><span class="status-badge status-active">Success</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <!-- Enhanced New Message Modal -->
    <div id="newMessageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>New Message</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="messageForm">
                    <div class="form-group">
                        <label>Recipient</label>
                      
                        <select id="recipientSelect" class="form-control" required>
                            <option value="">Select recipient...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" id="messageSubject" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea id="messageContent" class="form-control" rows="5" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Send Message
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- View Message Modal -->
    <div id="viewMessageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="messageViewTitle"></h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="messageViewContent"></div>
                <div id="messageViewMeta" style="margin-top: 20px; font-size: 0.9em; color: #666;"></div>
            </div>
        </div>
    </div>

    <!-- Player Details Modal -->
    <div id="playerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Player Details</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div id="playerDetails">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>

    <!-- Settings Modal -->
    <div id="settingsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>System Settings</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form id="settingsForm" onsubmit="updateSettingsBulk(event)">
                <div class="form-group">
                    <label>Two-Factor Authentication</label>
                    <select name="settings[two_factor_auth]" class="form-control">
                        <option value="true" <?= ($system_settings['two_factor_auth']['setting_value'] ?? 'false') === 'true' ? 'selected' : '' ?>>Enabled</option>
                        <option value="false" <?= ($system_settings['two_factor_auth']['setting_value'] ?? 'false') === 'false' ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Login Attempt Tracking</label>
                    <select name="settings[login_tracking]" class="form-control">
                        <option value="true" <?= ($system_settings['login_tracking']['setting_value'] ?? 'true') === 'true' ? 'selected' : '' ?>>Enabled</option>
                        <option value="false" <?= ($system_settings['login_tracking']['setting_value'] ?? 'true') === 'false' ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>GDPR Compliance</label>
                    <select name="settings[gdpr_compliance]" class="form-control">
                        <option value="true" <?= ($system_settings['gdpr_compliance']['setting_value'] ?? 'true') === 'true' ? 'selected' : '' ?>>Enabled</option>
                        <option value="false" <?= ($system_settings['gdpr_compliance']['setting_value'] ?? 'true') === 'false' ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </div>
    </div>

    <footer style="background: var(--dark); color: white; padding: 2rem; text-align: center; margin-top: 3rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; max-width: 1400px; margin: 0 auto;">
            <div>
                <p><i class="fas fa-envelope"></i> Contact Support: admin@vertwalacademy.com</p>
                <p><i class="fas fa-globe"></i> www.vertwalacademy.com</p>
            </div>
            <div>
                <p><i class="fas fa-phone"></i> +251-XXX-XXXX</p>
                <p><i class="fas fa-code-branch"></i> Version: v1.0.0</p>
            </div>
            <div>
                <a href="logout.php" class="btn btn-danger">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </footer>

    <script>
        let currentRoleFilter = '';

        // Navigation
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                
                document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
                document.querySelectorAll('.dashboard-section').forEach(s => s.classList.remove('active'));
                
                this.classList.add('active');
                
                const sectionId = this.getAttribute('data-section');
                document.getElementById(sectionId).classList.add('active');
                
                // Load messages when messages section is activated
                if (sectionId === 'messages') {
                    loadMessages();
                    loadRecipients();
                }
            });
        });

        // Load messages when page loads if on messages section
        if (document.getElementById('messages').classList.contains('active')) {
            loadMessages();
            loadRecipients();
        }

        function filterRecipients(role) {
            currentRoleFilter = role;
            document.querySelectorAll('.role-filter-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.role === role) {
                    btn.classList.add('active');
                }
            });
            loadRecipients(role);
        }

        // Charts
        document.addEventListener('DOMContentLoaded', function() {
            const activityCtx = document.getElementById('activityChart').getContext('2d');
            new Chart(activityCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($stats['weekly_activity'], 'day')); ?>,
                    datasets: [{
                        label: 'Daily Logins',
                        data: <?php echo json_encode(array_column($stats['weekly_activity'], 'logins')); ?>,
                        borderColor: 'rgb(30, 60, 114)',
                        backgroundColor: 'rgba(30, 60, 114, 0.1)',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            const roleCtx = document.getElementById('roleChart').getContext('2d');
            new Chart(roleCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Players', 'Scouts', 'Coaches', 'Medical', 'Clubs'],
                    datasets: [{
                        data: [<?php echo $stats['approved_players']; ?>, 15, 8, 5, <?php echo $stats['active_clubs']; ?>],
                        backgroundColor: [
                            'rgb(30, 60, 114)',
                            'rgb(42, 82, 152)',
                            'rgb(40, 167, 69)',
                            'rgb(220, 53, 69)',
                            'rgb(23, 162, 184)'
                        ]
                    }]
                },
                options: {
                    responsive: true
                }
            });

            loadNotifications();
            setInterval(loadNotifications, 30000);
        });

        // Enhanced Messaging Functions
        function loadMessages() {
            fetch('admin-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_messages'
            })
            .then(response => response.json())
            .then(messages => {
                const messagesList = document.getElementById('messagesList');
                messagesList.innerHTML = '';
                
                if (messages.length === 0) {
                    messagesList.innerHTML = '<tr><td colspan="5" style="text-align: center;">No messages found</td></tr>';
                    return;
                }
                
                messages.forEach(message => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>
                            <div><strong>${message.sender_name}</strong></div>
                            <div style="font-size: 0.8em; color: #666;">${message.sender_role} - ${message.sender_organization || 'N/A'}</div>
                        </td>
                        <td>${message.subject}</td>
                        <td>${new Date(message.created_at).toLocaleString()}</td>
                        <td><span class="status-badge ${message.is_read ? 'status-approved' : 'status-pending'}">
                            ${message.is_read ? 'Read' : 'Unread'}
                        </span></td>
                        <td>
                            <button class="btn btn-primary btn-sm" onclick="viewMessage(${message.id})">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="deleteMessage(${message.id})">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </td>
                    `;
                    messagesList.appendChild(row);
                });
            })
            .catch(error => {
                console.error('Error loading messages:', error);
                showNotification('Error loading messages', 'error');
            });
        }

        function loadSentMessages() {
            fetch('admin-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_sent_messages'
            })
            .then(response => response.json())
            .then(messages => {
                const messagesList = document.getElementById('messagesList');
                messagesList.innerHTML = '';
                
                if (messages.length === 0) {
                    messagesList.innerHTML = '<tr><td colspan="5" style="text-align: center;">No sent messages found</td></tr>';
                    return;
                }
                
                messages.forEach(message => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>
                            <div><strong>To: ${message.recipient_name}</strong></div>
                            <div style="font-size: 0.8em; color: #666;">${message.recipient_role} - ${message.recipient_organization || 'N/A'}</div>
                        </td>
                        <td>${message.subject}</td>
                        <td>${new Date(message.created_at).toLocaleString()}</td>
                        <td><span class="status-badge status-info">Sent</span></td>
                        <td>
                            <button class="btn btn-primary btn-sm" onclick="viewMessage(${message.id})">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="deleteMessage(${message.id})">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </td>
                    `;
                    messagesList.appendChild(row);
                });
            })
            .catch(error => {
                console.error('Error loading sent messages:', error);
                showNotification('Error loading sent messages', 'error');
            });
        }

        function loadRecipients(roleFilter = '') {
            const body = roleFilter ? `action=get_recipients&role_filter=${roleFilter}` : 'action=get_recipients';
            
            fetch('admin-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: body
            })
            .then(response => response.json())
            .then(recipients => {
                const recipientSelect = document.getElementById('recipientSelect');
                recipientSelect.innerHTML = '<option value="">Select recipient...</option>';
                
                if (recipients.length === 0) {
                    recipientSelect.innerHTML = '<option value="">No recipients found for this role</option>';
                    return;
                }
                
                recipients.forEach(recipient => {
                    const option = document.createElement('option');
                    option.value = recipient.id;
                    option.textContent = `${recipient.username} (${recipient.role.toUpperCase()}) - ${recipient.organization || 'N/A'}`;
                    recipientSelect.appendChild(option);
                });
            })
            .catch(error => {
                console.error('Error loading recipients:', error);
                showNotification('Error loading recipients', 'error');
            });
        }

        function openNewMessageModal() {
            currentRoleFilter = '';
            document.querySelectorAll('.role-filter-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.role === '') {
                    btn.classList.add('active');
                }
            });
            loadRecipients();
            document.getElementById('newMessageModal').style.display = 'block';
        }

        function viewMessage(messageId) {
            fetch('admin-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_message&message_id=${messageId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const message = data.message;
                    document.getElementById('messageViewTitle').textContent = message.subject;
                    document.getElementById('messageViewContent').innerHTML = message.message.replace(/\n/g, '<br>');
                    document.getElementById('messageViewMeta').innerHTML = `
                        <strong>From:</strong> ${message.sender_name} (${message.sender_role})<br>
                        <strong>To:</strong> ${message.recipient_name} (${message.recipient_role})<br>
                        <strong>Date:</strong> ${new Date(message.created_at).toLocaleString()}
                    `;
                    
                    document.getElementById('viewMessageModal').style.display = 'block';
                    loadMessages(); // Refresh to update read status
                } else {
                    showNotification('Message not found', 'error');
                }
            })
            .catch(error => {
                console.error('Error viewing message:', error);
                showNotification('Error loading message', 'error');
            });
        }

        function deleteMessage(messageId) {
            if (confirm('Are you sure you want to delete this message?')) {
                fetch('admin-dashboard.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=delete_message&message_id=${messageId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Message deleted successfully', 'success');
                        loadMessages();
                    } else {
                        showNotification('Failed to delete message', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error deleting message:', error);
                    showNotification('Error deleting message', 'error');
                });
            }
        }

        // Enhanced send message form handler
        document.getElementById('messageForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const recipientId = document.getElementById('recipientSelect').value;
            const subject = document.getElementById('messageSubject').value.trim();
            const message = document.getElementById('messageContent').value.trim();
            
            if (!recipientId || !subject || !message) {
                showNotification('All fields are required', 'error');
                return;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            submitBtn.disabled = true;
            
            fetch('admin-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=send_message&recipient_id=${recipientId}&subject=${encodeURIComponent(subject)}&message=${encodeURIComponent(message)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Message sent successfully!', 'success');
                    closeModal();
                    document.getElementById('messageForm').reset();
                    loadMessages();
                    // Reset role filter
                    currentRoleFilter = '';
                    document.querySelectorAll('.role-filter-btn').forEach(btn => {
                        btn.classList.remove('active');
                        if (btn.dataset.role === '') {
                            btn.classList.add('active');
                        }
                    });
                } else {
                    showNotification(data.message || 'Failed to send message', 'error');
                }
            })
            .catch(error => {
                console.error('Error sending message:', error);
                showNotification('Error sending message. Please try again.', 'error');
            })
            .finally(() => {
                // Reset button state
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });

        // Notification functions
        function loadNotifications() {
            fetch('admin-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_notifications'
            })
            .then(response => response.json())
            .then(notifications => {
                const notificationList = document.getElementById('notificationList');
                const notificationBadge = document.getElementById('notificationBadge');
                
                if (notifications.length > 0) {
                    notificationBadge.textContent = notifications.length;
                    notificationBadge.style.display = 'flex';
                    
                    notificationList.innerHTML = '';
                    notifications.forEach(notification => {
                        const notificationItem = document.createElement('div');
                        notificationItem.className = 'notification-item unread';
                        notificationItem.innerHTML = `
                            <div class="notification-title">${notification.title}</div>
                            <div>${notification.message}</div>
                            <div class="notification-time">${new Date(notification.created_at).toLocaleString()}</div>
                        `;
                        notificationItem.onclick = () => {
                            markNotificationRead(notification.id);
                        };
                        notificationList.appendChild(notificationItem);
                    });
                } else {
                    notificationBadge.style.display = 'none';
                    notificationList.innerHTML = '<div class="notification-item">No new notifications</div>';
                }
            });
        }

        function markNotificationRead(notificationId) {
            fetch('admin-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=mark_notification_read&notification_id=${notificationId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadNotifications();
                }
            });
        }

        function markAllNotificationsRead() {
            fetch('admin-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_notifications'
            })
            .then(response => response.json())
            .then(data => {
                loadNotifications();
            });
        }

        document.getElementById('notificationIcon').addEventListener('click', function(e) {
            e.stopPropagation();
            document.getElementById('notificationDropdown').classList.toggle('show');
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.notification-icon')) {
                document.getElementById('notificationDropdown').classList.remove('show');
            }
        });

        // Filter players by status
        function filterPlayers(status) {
            if (status) {
                window.location.href = `admin-dashboard.php?filter=${status}`;
            } else {
                window.location.href = 'admin-dashboard.php';
            }
        }

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }
        
        function closeModal() {
            document.querySelectorAll('.modal').forEach(modal => {
                modal.style.display = 'none';
            });
        }

        // AJAX Functions
        function toggleUserStatus(userId, currentStatus) {
            fetch('admin-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=toggle_user_status&user_id=${userId}&status=${currentStatus}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('User status updated successfully', 'success');
                } else {
                    showNotification('Failed to update user status', 'error');
                }
            });
        }

        function updatePermission(role, permission, enabled) {
            fetch('admin-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=update_permission&role=${role}&permission=${permission}&enabled=${enabled}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Permission updated successfully', 'success');
                } else {
                    showNotification('Failed to update permission', 'error');
                }
            });
        }

        function approvePlayer(playerId, action) {
            fetch('admin-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=approve_player&player_id=${playerId}&approval_action=${action}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(`Player ${action} successfully`, 'success');
                    location.reload();
                } else {
                    showNotification(`Failed to ${action} player`, 'error');
                }
            });
        }

        function deletePlayer(playerId) {
            if (confirm('Are you sure you want to permanently delete this player? This action cannot be undone.')) {
                fetch('admin-dashboard.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=delete_player&player_id=${playerId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Player deleted successfully', 'success');
                        document.querySelector(`tr[data-player-id="${playerId}"]`).remove();
                    } else {
                        showNotification('Failed to delete player', 'error');
                    }
                });
            }
        }

        function updateSetting(key, value) {
            fetch('admin-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=update_setting&key=${key}&value=${value}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Setting updated successfully', 'success');
                } else {
                    showNotification('Failed to update setting', 'error');
                }
            });
        }

        function updateSettingsBulk(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            const settings = {};
            
            for (let [key, value] of formData.entries()) {
                if (key.startsWith('settings[')) {
                    const settingKey = key.match(/\[(.*?)\]/)[1];
                    settings[settingKey] = value;
                }
            }
            
            fetch('admin-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=update_settings_bulk&settings=${JSON.stringify(settings)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Settings updated successfully', 'success');
                    closeModal();
                } else {
                    showNotification('Failed to update settings', 'error');
                }
            });
        }

        function viewPlayerDetails(playerId) {
            fetch(`get_player_details.php?id=${playerId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('playerDetails').innerHTML = html;
                    openModal('playerModal');
                });
        }

        // Utility Functions
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `alert alert-${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                border-radius: 5px;
                z-index: 9999;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                max-width: 400px;
                ${type === 'success' ? 'background: #d4edda; color: #155724; border: 1px solid #c3e6cb;' : 
                  type === 'info' ? 'background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb;' :
                  'background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;'}
            `;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 5000);
        }

        function addNewUser() {
            showNotification('Add New User functionality would open a modal form here', 'info');
        }

        function editUser(userId) {
            showNotification(`Edit user ${userId} functionality would open a modal form here`, 'info');
        }

        function resetPassword(userId) {
            if (confirm('Are you sure you want to reset this user\'s password?')) {
                showNotification(`Password reset for user ${userId} would be implemented here`, 'info');
            }
        }

        function lockAccount(userId) {
            if (confirm('Are you sure you want to lock this account?')) {
                showNotification(`Account lock for user ${userId} would be implemented here`, 'info');
            }
        }

        function viewUser(userId) {
            showNotification(`View user ${userId} details would open a modal here`, 'info');
        }

        function createBackup() {
            showNotification('System backup functionality would be implemented here', 'info');
        }

        function viewErrorLogs() {
            showNotification('Error logs viewer would open here', 'info');
        }

        function checkSystemStatus() {
            showNotification('System status check would be performed here', 'info');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                closeModal();
            }
        }
    </script>
</body>
</html>