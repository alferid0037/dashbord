<?php
require_once 'config.php';

// Check if session is not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if club user is logged in
if (!isset($_SESSION['professional_logged_in']) || $_SESSION['professional_role'] !== 'club') {
    header('Location: club-login.php');
    exit();
}

// Check if user has club role
if ($_SESSION['professional_role'] !== 'club') {
    header('Location: unauthorized.php');
    exit();
}

// Modify your getPDO function to include error handling
function getPDO() {
    static $pdo;
    if (!$pdo) {
        try {
            $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection error. Please try again later.");
        }
    }
    return $pdo;
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $pdo = getPDO();
    $club_id = $_SESSION['professional_id'] ?? 0;
    
    switch ($_POST['action']) {
        case 'add_to_watchlist':
            $player_id = (int)$_POST['player_id'];
            
            // Check if already in watchlist
            $stmt = $pdo->prepare("SELECT id FROM club_watchlist WHERE club_id = ? AND player_id = ?");
            $stmt->execute([$club_id, $player_id]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => false, 'message' => 'Player already in watchlist']);
                exit();
            }
            
            $stmt = $pdo->prepare("INSERT INTO club_watchlist (club_id, player_id) VALUES (?, ?)");
            $result = $stmt->execute([$club_id, $player_id]);
            
            echo json_encode(['success' => $result]);
            exit();
            
        case 'remove_from_watchlist':
            $player_id = (int)$_POST['player_id'];
            
            $stmt = $pdo->prepare("DELETE FROM club_watchlist WHERE club_id = ? AND player_id = ?");
            $result = $stmt->execute([$club_id, $player_id]);
            
            echo json_encode(['success' => $result]);
            exit();
            
        case 'submit_request':
            $player_id = (int)$_POST['player_id'];
            $request_type = $_POST['request_type'];
            $notes = $_POST['notes'];
            
            $stmt = $pdo->prepare("INSERT INTO club_requests (club_id, player_id, request_type, notes) VALUES (?, ?, ?, ?)");
            $result = $stmt->execute([$club_id, $player_id, $request_type, $notes]);
            
            echo json_encode(['success' => $result]);
            exit();
            
        case 'get_notifications':
            // Get all recent notifications for the club
            $stmt = $pdo->prepare("SELECT * FROM notifications WHERE recipient_id = ? ORDER BY created_at DESC LIMIT 10");
            $stmt->execute([$club_id]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get the count of specifically unread notifications
            $stmt_count = $pdo->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE recipient_id = ? AND is_read = 0");
            $stmt_count->execute([$club_id]);
            $unread_count = $stmt_count->fetch(PDO::FETCH_ASSOC)['unread_count'];
            
            echo json_encode(['notifications' => $notifications, 'unread_count' => $unread_count]);
            exit();
            
        case 'mark_notification_read':
            $notification_id = (int)$_POST['notification_id'];
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_id = ?");
            $result = $stmt->execute([$notification_id, $club_id]);
            
            echo json_encode(['success' => $result]);
            exit();

        case 'mark_all_notifications_read':
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_id = ? AND is_read = 0");
            $result = $stmt->execute([$club_id]);
            echo json_encode(['success' => $result]);
            exit();
    }
}

// Helper function to calculate age
function calculateAge($day, $month, $year) {
    if (!$day || !$month || !$year) return 'N/A';
    $birthDate = date_create("$year-$month-$day");
    if (!$birthDate) return 'N/A';
    $today = date_create("today");
    $diff = date_diff($birthDate, $today);
    return $diff->format('%y');
}

// Get dashboard statistics for club
function getClubDashboardStats($club_id) {
    $pdo = getPDO();
    $stats = [];
    
    // Total players in watchlist
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM club_watchlist WHERE club_id = ?");
    $stmt->execute([$club_id]);
    $stats['watchlist_count'] = $stmt->fetch()['count'];
    
    // Pending requests
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM club_requests WHERE club_id = ? AND status = 'pending'");
    $stmt->execute([$club_id]);
    $stats['pending_requests'] = $stmt->fetch()['count'];
    
    // Approved requests
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM club_requests WHERE club_id = ? AND status = 'approved'");
    $stmt->execute([$club_id]);
    $stats['approved_requests'] = $stmt->fetch()['count'];
    
    // Rejected requests
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM club_requests WHERE club_id = ? AND status = 'rejected'");
    $stmt->execute([$club_id]);
    $stats['rejected_requests'] = $stmt->fetch()['count'];
    
    // Recent activity
    $stmt = $pdo->prepare("SELECT * FROM club_activity WHERE club_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$club_id]);
    $stats['recent_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $stats;
}

// Get players with filter
function getPlayers($filter = null, $club_id = null) {
    $pdo = getPDO();
    $query = "SELECT pr.*, u.email, 
              (SELECT COUNT(*) FROM club_watchlist WHERE player_id = pr.id AND club_id = ?) as in_watchlist
              FROM player_registrations pr 
              JOIN users u ON pr.user_id = u.id
              WHERE pr.registration_status = 'approved'";
    
    $params = [$club_id];
    
    if ($filter && in_array($filter, ['forward', 'midfielder', 'defender', 'goalkeeper'])) {
        $query .= " AND pr.position = ?";
        $params[] = $filter;
    }
    
    $query .= " ORDER BY pr.created_at DESC";
    $stmt = $pdo->prepare($query);
    
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get watchlist players
function getWatchlistPlayers($club_id) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT pr.*, u.email 
        FROM club_watchlist cw
        JOIN player_registrations pr ON cw.player_id = pr.id
        JOIN users u ON pr.user_id = u.id
        WHERE cw.club_id = ?
        ORDER BY cw.created_at DESC
    ");
    $stmt->execute([$club_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get club requests
function getClubRequests($club_id) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT cr.*, pr.first_name, pr.last_name 
        FROM club_requests cr
        JOIN player_registrations pr ON cr.player_id = pr.id
        WHERE cr.club_id = ?
        ORDER BY cr.created_at DESC
    ");
    $stmt->execute([$club_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get Club Videos
function getClubVideos($club_id) {
    $pdo = getPDO();
    // This query assumes a 'videos' table. In a real app, this might be more complex.
    // We fetch a general pool of recent videos for demonstration.
    try {
        $stmt = $pdo->prepare("SELECT * FROM videos ORDER BY created_at DESC LIMIT 5");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // If the table doesn't exist or there's an error, log it and return an empty array.
        error_log("Error fetching club videos: " . $e->getMessage());
        return [];
    }
}

// Get club info
function getClubInfo($club_id) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM professional_users WHERE id = ?");
    $stmt->execute([$club_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get data for dashboard
$club_id = $_SESSION['professional_id'];
$stats = getClubDashboardStats($club_id);
$filter = $_GET['filter'] ?? null;
$players = getPlayers($filter, $club_id);
$watchlist_players = getWatchlistPlayers($club_id);
$club_requests = getClubRequests($club_id);
$club_info = getClubInfo($club_id);
$club_videos = getClubVideos($club_id); // Fetch video data
?>
<!DOCTYPE html>

<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Club Dashboard | Ethiopian Online Football Scouting</title>
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
            --dark-mode-bg: #1a1a2e;
            --dark-mode-card: #16213e;
            --dark-mode-text: #f1f1f1;
            --gray: #95a5a6;
        }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        transition: background-color 0.3s, color 0.3s;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: var(--light);
        color: var(--dark);
        display: flex;
        min-height: 100vh;
    }

    body.dark-mode {
        background-color: var(--dark-mode-bg);
        color: var(--dark-mode-text);
    }

    /* Sidebar Styles */
    .sidebar {
        width: 250px;
        background-color: var(--primary);
        color: white;
        height: 100vh;
        position: fixed;
        padding: 20px 0;
        transition: all 0.3s;
        z-index: 100;
    }
    
    body.dark-mode .sidebar {
        background-color: #0f0f1a;
    }

    .sidebar.collapsed {
        width: 70px;
        overflow: hidden;
    }

    .sidebar.collapsed .sidebar-header h3, .sidebar.collapsed .menu-item span {
        display: none;
    }

    .sidebar.collapsed .menu-item {
        justify-content: center;
    }
    .sidebar.collapsed .menu-item i {
        margin-right: 0;
        font-size: 1.2rem;
    }
    
    .sidebar-header {
        padding: 0 20px 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .sidebar-header h3 {
        font-size: 1.2rem;
    }

    .sidebar-header i {
        cursor: pointer;
    }
    
    .sidebar-menu {
        padding: 20px 0;
        height: calc(100vh - 80px);
        overflow-y: auto;
    }
    
    .menu-item {
        padding: 12px 20px;
        cursor: pointer;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        margin: 5px 0;
    }
    
    .menu-item:hover {
        background-color: rgba(255, 255, 255, 0.1);
    }
    
    .menu-item i {
        margin-right: 10px;
        width: 20px;
        text-align: center;
    }
    
    .menu-item.active {
        background-color: var(--accent);
        color: var(--dark);
    }

    /* Main Content Styles */
    .main-content {
        margin-left: 250px;
        width: calc(100% - 250px);
        padding: 20px;
        min-height: 100vh;
        transition: all 0.3s;
    }

    .main-content.expanded {
        margin-left: 70px;
        width: calc(100% - 70px);
    }
    
    body.dark-mode .main-content {
        background-color: var(--dark-mode-bg);
        color: var(--dark-mode-text);
    }
    
    /* Header Styles */
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding: 10px 0;
        position: sticky;
        top: 0;
        background-color: inherit;
        z-index: 10;
    }
    
    .search-bar {
        display: flex;
        align-items: center;
        background-color: white;
        padding: 8px 15px;
        border-radius: 20px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        width: 300px;
    }
    
    body.dark-mode .search-bar {
        background-color: var(--dark-mode-card);
        box-shadow: 0 2px 5px rgba(0,0,0,0.3);
    }
    
    .search-bar input {
        border: none;
        outline: none;
        width: 100%;
        padding: 5px;
        background-color: transparent;
    }
    
    body.dark-mode .search-bar input {
        color: var(--dark-mode-text);
    }
    
    .search-bar input::placeholder {
        color: var(--gray);
    }
    
    .user-actions {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .action-icon {
        position: relative;
        cursor: pointer;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: background-color 0.2s;
    }
    
    .action-icon:hover {
        background-color: rgba(0,0,0,0.1);
    }
    
    body.dark-mode .action-icon:hover {
        background-color: rgba(255,255,255,0.1);
    }
    
    .notification-count {
        position: absolute;
        top: -5px;
        right: -5px;
        background-color: var(--danger);
        color: white;
        border-radius: 50%;
        width: 18px;
        height: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
    }
    
    .user-profile {
        display: flex;
        align-items: center;
        cursor: pointer;
        position: relative;
    }
    
    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background-color: var(--primary);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 10px;
        font-weight: bold;
    }

    /* Content Sections */
    .content-section {
        display: none;
    }

    .content-section.active {
        display: block;
    }

    /* Dashboard cards */
    .cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .dashboard-card {
        background-color: white;
        border-radius: 10px;
        padding: 20px;
        display: flex;
        flex-direction: column;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    
    body.dark-mode .dashboard-card {
         background-color: var(--dark-mode-card);
         box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    }
    
    .dashboard-card .card-header, .dashboard-card .card-body, .dashboard-card .card-footer {
        width: 100%;
    }

    .dashboard-card.stat-card {
        flex-direction: row;
        align-items: center;
        gap: 20px;
    }

    .dashboard-card .icon {
        font-size: 2.5rem;
        padding: 15px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .dashboard-card .icon.total { background-color: #eaf2fa; color: #3498db; }
    .dashboard-card .icon.approved { background-color: #eafaf1; color: #2ecc71; }
    .dashboard-card .icon.pending { background-color: #fff3cd; color: #f39c12; }
    .dashboard-card .icon.rejected { background-color: #fbe9e7; color: #e74c3c; }

    .dashboard-card .card-content .value {
        font-size: 2rem;
        font-weight: 700;
    }

    .dashboard-card .card-content .label {
        font-size: 1rem;
        color: var(--gray);
    }

    /* Data Tables */
    .data-table {
        width: 100%;
        border-collapse: collapse;
        background-color: white;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    
    body.dark-mode .data-table {
        background-color: var(--dark-mode-card);
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    }
    
    .data-table th, .data-table td {
        padding: 12px 15px;
        text-align: left;
        border-bottom: 1px solid #eee;
    }
    
    body.dark-mode .data-table th, 
    body.dark-mode .data-table td {
        border-bottom: 1px solid #444;
    }
    
    .data-table th {
        background-color: #f8f9fa;
        font-weight: 600;
    }
    
    body.dark-mode .data-table th {
        background-color: #0f0f1a;
    }
    
    .data-table tr:hover {
        background-color: #f8f9fa;
    }
    
    body.dark-mode .data-table tr:hover {
        background-color: rgba(255,255,255,0.05);
    }
    
    .status {
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        text-transform: capitalize;
    }
    
    .status.watched { background-color: #d1e7fd; color: #0a58ca; }
    .status.recommended { background-color: #d4edda; color: #155724; }
    .status.not-scouted { background-color: #f8d7da; color: #721c24; }
    .status.pending { background-color: #fff3cd; color: #856404; }
    .status.approved { background-color: #d4edda; color: #155724; }
    .status.rejected { background-color: #f8d7da; color: #721c24; }
    
    .action-btn {
        padding: 5px 10px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        margin-right: 5px;
        font-size: 12px;
        transition: opacity 0.2s;
    }
    
    .action-btn:hover {
        opacity: 0.9;
    }
    
    .btn-primary { background-color: var(--primary); color: white; }
    .btn-secondary { background-color: var(--gray); color: white; }
    .btn-danger { background-color: var(--danger); color: white; }
    .btn-success { background-color: var(--secondary); color: white; }
    .btn-warning { background-color: var(--warning); color: white; }

    /* Rating stars */
    .rating-stars .fa-star {
        color: var(--warning);
    }
    .rating-stars .fa-star.empty {
        color: #ddd;
    }

    /* Card footer */
    .card-footer {
        padding: 15px 20px;
        background-color: #f8f9fa;
        border-top: 1px solid #eee;
        margin: 20px -20px -20px;
    }
    
    body.dark-mode .card-footer {
        background-color: #0f0f1a;
        border-top-color: #444;
    }

    /* Filter selects */
    .filter-select {
        padding: 8px 12px;
        border-radius: 5px;
        border: 1px solid #ddd;
        background-color: white;
        width: 100%;
    }
    
    body.dark-mode .filter-select {
        background-color: var(--dark-mode-card);
        border-color: #444;
        color: var(--dark-mode-text);
    }

    /* Video container */
    .video-container {
        position: relative;
        padding-bottom: 56.25%;
        height: 0;
        overflow: hidden;
        margin-bottom: 15px;
        background-color: #000;
        border-radius: 5px;
    }
    
    .video-container iframe {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
    }

    /* Footer */
    .footer {
        background-color: white;
        padding: 15px 20px;
        text-align: center;
        border-top: 1px solid #eee;
        margin-top: 30px;
        font-size: 14px;
        color: var(--gray);
    }
    
    body.dark-mode .footer {
        background-color: var(--dark-mode-card);
        border-top: 1px solid #444;
        color: #777;
    }
    
    .footer-links {
        display: flex;
        justify-content: center;
        gap: 15px;
        margin-bottom: 10px;
    }
    
    .footer-link {
        color: var(--gray);
        text-decoration: none;
    }
    
    .footer-link:hover {
        color: var(--primary);
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .player-stats { grid-template-columns: repeat(2, 1fr); }
        .player-sections { flex-direction: column; }
    }
    
    @media (max-width: 992px) {
        .player-profile { flex-direction: column; }
        .player-photo { width: 150px; height: 150px; margin-bottom: 15px; }
        .form-row { flex-direction: column; gap: 0; }
    }
    
    @media (max-width: 768px) {
        .sidebar { width: 70px; overflow: hidden; }
        .sidebar-header h3, .menu-item span { display: none; }
        .menu-item { justify-content: center; }
        .menu-item i { margin-right: 0; font-size: 1.2rem; }
        .main-content { margin-left: 70px; width: calc(100% - 70px); }
        .search-bar { width: 200px; }
        .player-stats { grid-template-columns: 1fr; }
    }
    
    @media (max-width: 576px) {
        .header { flex-direction: column; align-items: flex-start; gap: 15px; }
        .search-bar { width: 100%; }
        .user-actions { width: 100%; justify-content: space-between; }
    }

    /* Notification styles */
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
    body.dark-mode .notification-dropdown {
        background-color: var(--dark-mode-card);
        border: 1px solid #444;
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
    body.dark-mode .notification-header { border-bottom-color: #444; }


    .notification-item {
        padding: 1rem;
        border-bottom: 1px solid #eee;
        cursor: pointer;
        transition: background 0.2s;
    }
    body.dark-mode .notification-item { border-bottom-color: #444; }

    .notification-item:hover {
        background: #f8f9fa;
    }
    body.dark-mode .notification-item:hover { background-color: rgba(255,255,255,0.05); }


    .notification-item.unread {
        background: #f0f7ff;
    }
    body.dark-mode .notification-item.unread { background-color: rgba(42, 82, 152, 0.3); }

    .notification-title {
        font-weight: 600;
        margin-bottom: 0.5rem;
    }

    .notification-time {
        font-size: 0.8rem;
        color: #666;
    }
    body.dark-mode .notification-time { color: #aaa; }

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
    .modal {
        display: none;
        position: fixed;
        z-index: 1001;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.5);
    }
    .modal-content {
        background-color: #fefefe;
        margin: 10% auto;
        padding: 20px;
        border: 1px solid #888;
        width: 80%;
        max-width: 700px;
        border-radius: 10px;
    }
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #eee;
        padding-bottom: 10px;
        margin-bottom: 20px;
    }
    .close-modal {
        color: #aaa;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        background: none;
        border: none;
    }
</style>

</head>
<body>
    <!-- Sidebar Navigation -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3>Club Portal</h3>
            <i class="fas fa-bars" id="sidebarToggle"></i>
        </div>
        <div class="sidebar-menu">
            <div class="menu-item active" data-section="dashboard">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </div>
            <div class="menu-item" data-section="players">
                <i class="fas fa-users"></i>
                <span>Player Database</span>
            </div>
            <div class="menu-item" data-section="watchlist">
                <i class="fas fa-star"></i>
                <span>Watchlist</span>
            </div>
            <div class="menu-item" data-section="requests">
                <i class="fas fa-file-signature"></i>
                <span>Signing Requests</span>
            </div>
            <div class="menu-item" data-section="videos">
                <i class="fas fa-video"></i>
                <span>Match Videos</span>
            </div>
            <div class="menu-item" data-section="settings">
                <i class="fas fa-cogs"></i>
                <span>Settings</span>
            </div>
            <div class="menu-item" data-section="logout">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </div>
        </div>
    </div>

<!-- Main Content Area -->
<div class="main-content" id="mainContent">
    <!-- Header with Search and User Profile -->
    <div class="header">
        <div style="display: flex; align-items: center;">
            <div class="user-avatar" style="background-color: var(--secondary); margin-right: 15px;"><?php echo substr($club_info['organization'] ?? 'C', 0, 1); ?></div>
            <h3>Club Dashboard – Player Scouting & Acquisition Hub</h3>
        </div>
        <div class="user-actions">
            <div class="action-icon" id="darkModeToggle">
                <i class="fas fa-moon"></i>
            </div>
            <div class="action-icon notification-bell" id="notificationIcon" style="position: relative;">
                <i class="fas fa-bell"></i>
                <div class="notification-badge" id="notificationBadge" style="display: none;">0</div>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <span>Notifications</span>
                        <button onclick="markAllNotificationsRead()" style="background: none; border: none; color: var(--primary); cursor: pointer; font-size: 12px;">
                            Mark all as read
                        </button>
                    </div>
                    <div id="notificationList">
                        <div class="notification-item" style="text-align: center; color: var(--gray);">Loading...</div>
                    </div>
                </div>
            </div>
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search players, reports..." id="searchInput">
            </div>
            <div class="user-profile" id="userProfile">
                <div class="user-avatar"><?php echo substr($club_info['username'] ?? 'CU', 0, 1); ?></div>
                <span><?php echo htmlspecialchars($club_info['organization'] ?? 'Club User'); ?></span>
            </div>
        </div>
    </div>
    
    <!-- Dashboard Content -->
    <div class="content-section active" data-content="dashboard">
        <section class="cards">
            <div class="dashboard-card stat-card">
                <div class="icon total"><i class="fas fa-users"></i></div>
                <div class="card-content">
                    <div class="value"><?php echo count($players); ?></div>
                    <div class="label">Players Available</div>
                </div>
            </div>
            <div class="dashboard-card stat-card">
                <div class="icon approved"><i class="fas fa-star"></i></div>
                <div class="card-content">
                    <div class="value"><?php echo $stats['watchlist_count']; ?></div>
                    <div class="label">On Watchlist</div>
                </div>
            </div>
            <div class="dashboard-card stat-card">
                <div class="icon pending"><i class="fas fa-clock"></i></div>
                <div class="card-content">
                    <div class="value"><?php echo $stats['pending_requests']; ?></div>
                    <div class="label">Pending Requests</div>
                </div>
            </div>
            <div class="dashboard-card stat-card">
                <div class="icon rejected"><i class="fas fa-check-double"></i></div>
                <div class="card-content">
                    <div class="value"><?php echo $stats['approved_requests']; ?></div>
                    <div class="label">Approved Requests</div>
                </div>
            </div>
        </section>
        
        <!-- Player Search Widget -->
        <div class="dashboard-card">
            <h3><i class="fas fa-search" style="color: var(--primary); margin-right: 10px;"></i>Player Search & Filter</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                <select class="filter-select" onchange="filterPlayers(this.value)">
                    <option value="">All Positions</option>
                    <option value="forward" <?= $filter === 'forward' ? 'selected' : '' ?>>Forward</option>
                    <option value="midfielder" <?= $filter === 'midfielder' ? 'selected' : '' ?>>Midfielder</option>
                    <option value="defender" <?= $filter === 'defender' ? 'selected' : '' ?>>Defender</option>
                    <option value="goalkeeper" <?= $filter === 'goalkeeper' ? 'selected' : '' ?>>Goalkeeper</option>
                </select>
                <select class="filter-select">
                    <option>All Ages</option>
                    <option>16-18</option>
                    <option>19-21</option>
                    <option>22+</option>
                </select>
                <select class="filter-select">
                    <option>All Ratings</option>
                    <option>5 Stars</option>
                    <option>4+ Stars</option>
                    <option>3+ Stars</option>
                </select>
                <button class="action-btn btn-primary" onclick="searchPlayers()">Search Players</button>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
            <!-- Watchlist Widget -->
            <div class="dashboard-card">
                <div style="display: flex; justify-content: space-between; align-items: center; width:100%; margin-bottom: 15px;">
                    <h3><i class="fas fa-eye" style="color: var(--primary); margin-right: 10px;"></i>My Watchlist</h3>
                    <button class="action-btn btn-secondary" onclick="showSection('watchlist')">Manage</button>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Player</th>
                            <th>Position</th>
                            <th>Age</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($watchlist_players, 0, 3) as $player): 
                            $age = calculateAge($player['birth_day'], $player['birth_month'], $player['birth_year']);
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($player['first_name'] . ' ' . $player['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($player['position'] ?? 'N/A'); ?></td>
                            <td><?php echo $age; ?></td>
                            <td><span class="status recommended">On Watchlist</span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($watchlist_players) === 0): ?>
                        <tr>
                            <td colspan="4" style="text-align: center;">No players in your watchlist</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Player Request Widget -->
            <div class="dashboard-card">
                <h3><i class="fas fa-file-signature" style="color: var(--secondary); margin-right: 10px;"></i>Player Requests</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px; width:100%;">
                    <div>
                        <h4>New Request</h4>
                        <select class="filter-select" id="requestPlayer" style="width: 100%; margin-bottom: 10px;">
                            <option value="">Select Player</option>
                            <?php foreach ($players as $player): ?>
                            <option value="<?php echo $player['id']; ?>"><?php echo htmlspecialchars($player['first_name'] . ' ' . $player['last_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="requestType" style="width: 100%; margin-bottom: 10px;">
                            <option value="">Request Type</option>
                            <option value="trial">Trial Session</option>
                            <option value="signing">Formal Signing Offer</option>
                            <option value="evaluation">Additional Evaluation</option>
                        </select>
                        <textarea id="requestNotes" placeholder="Additional notes..." style="width: 100%; height: 100px; padding: 10px; border-radius: 5px; border: 1px solid #ddd; margin-bottom: 10px; background: transparent; color: inherit;"></textarea>
                        <button class="action-btn btn-primary" style="width: 100%;" onclick="submitRequest()">Submit Request</button>
                    </div>
                    <div>
                        <h4>Recent Requests</h4>
                        <div style="max-height: 200px; overflow-y: auto;">
                            <?php foreach (array_slice($club_requests, 0, 3) as $request): ?>
                            <div style="padding: 10px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between;">
                                <div>
                                    <strong><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></strong>
                                    <div style="font-size: 12px; color: var(--gray);"><?php echo ucfirst($request['request_type']); ?></div>
                                </div>
                                <span class="status <?php echo $request['status']; ?>"><?php echo $request['status']; ?></span>
                            </div>
                            <?php endforeach; ?>
                            <?php if (count($club_requests) === 0): ?>
                            <div style="padding: 10px; text-align: center; color: var(--gray);">No recent requests</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Performance & Video Widget -->
        <div class="dashboard-card" style="margin-top: 20px;">
            <h3><i class="fas fa-chart-line" style="color: var(--primary); margin-right: 10px;"></i>Player Performance & Video Analysis</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px; width:100%;">
                <div>
                    <h4>Top Performers</h4>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Player</th>
                                <th>Position</th>
                                <th>Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                                // Sort players by rating for this widget
                                $top_players = $players;
                                usort($top_players, function($a, $b) {
                                    return ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0);
                                });
                            ?>
                            <?php foreach (array_slice($top_players, 0, 3) as $player): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($player['first_name'] . ' ' . $player['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($player['position'] ?? 'N/A'); ?></td>
                                <td><span class="rating-stars"><?php echo round($player['rating'] ?? 0, 1); ?> <i class="fas fa-star"></i></span></td>
                            </tr>
                            <?php endforeach; ?>
                             <?php if (count($top_players) === 0): ?>
                                <tr><td colspan="3" style="text-align:center;">No players to display.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h4>Recent Match Highlights</h4>
                    <?php if (!empty($club_videos)): ?>
                    <div class="video-container">
                        <iframe src="<?php echo htmlspecialchars($club_videos[0]['video_url']); ?>" frameborder="0" allowfullscreen></iframe>
                    </div>
                    <div style="font-size: 12px; color: var(--gray);"><?php echo htmlspecialchars($club_videos[0]['title'] ?? 'Recent Match'); ?> - <?php echo date('M j, Y', strtotime($club_videos[0]['created_at'])); ?></div>
                    <button class="action-btn btn-primary" style="margin-top: 10px;" onclick="showSection('videos')"><i class="fas fa-video"></i> View All Videos</button>
                    <?php else: ?>
                    <div style="padding: 20px; text-align: center; color: var(--gray); border: 1px dashed #ccc; border-radius: 5px;">No videos available</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Player Database Content -->
    <div class="content-section" data-content="players">
        <h2 style="margin-bottom: 20px;">Player Database</h2>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div class="search-bar" style="width: 400px;">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search players..." id="playerSearch" onkeyup="searchTable(this, '.content-section[data-content=players] .data-table')">
            </div>
            <div>
                <button class="action-btn btn-secondary" style="margin-right: 10px;" onclick="showAdvancedFilters()">
                    <i class="fas fa-filter"></i> Advanced Filters
                </button>
                <button class="action-btn btn-primary">
                    <i class="fas fa-download"></i> Export Data
                </button>
            </div>
        </div>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Age</th>
                    <th>Position</th>
                    <th>Current Club</th>
                    <th>Rating</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($players as $player): 
                    $age = calculateAge($player['birth_day'], $player['birth_month'], $player['birth_year']);
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($player['first_name'] . ' ' . $player['last_name']); ?></td>
                    <td><?php echo $age; ?> years</td>
                    <td><?php echo htmlspecialchars($player['position'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($player['current_club'] ?? 'N/A'); ?></td>
                    <td class="rating-stars">
                        <?php 
                        $rating = min(5, max(0, round($player['rating'] ?? 0)));
                        for ($i = 1; $i <= 5; $i++) {
                            echo '<i class="fas fa-star' . ($i > $rating ? ' empty' : '') . '"></i>';
                        }
                        ?>
                    </td>
                    <td>
                        <span class="status <?php echo $player['in_watchlist'] ? 'watched' : 'not-scouted'; ?>">
                            <?php echo $player['in_watchlist'] ? 'On Watchlist' : 'Not Scouted'; ?>
                        </span>
                    </td>
                    <td>
                        <button class="action-btn btn-primary" onclick="viewPlayer(<?php echo $player['id']; ?>)">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <?php if ($player['in_watchlist']): ?>
                        <button class="action-btn btn-danger" onclick="removeFromWatchlist(<?php echo $player['id']; ?>, this)">
                            <i class="fas fa-minus"></i> Remove
                        </button>
                        <?php else: ?>
                        <button class="action-btn btn-success" onclick="addToWatchlist(<?php echo $player['id']; ?>, this)">
                            <i class="fas fa-plus"></i> Watchlist
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                 <?php if (empty($players)): ?>
                    <tr><td colspan="7" style="text-align:center;">No approved players found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Watchlist Content -->
    <div class="content-section" data-content="watchlist">
        <h2 style="margin-bottom: 20px;">My Watchlist</h2>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div class="search-bar" style="width: 400px;">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search watchlist..." id="watchlistSearch" onkeyup="searchCards(this, '.content-section[data-content=watchlist] .dashboard-card')">
            </div>
            <div>
                <button class="action-btn btn-secondary" style="margin-right: 10px;" onclick="sortWatchlist()">
                    <i class="fas fa-sliders-h"></i> Sort
                </button>
                <button class="action-btn btn-danger" onclick="clearWatchlist()">
                    <i class="fas fa-trash"></i> Clear Watchlist
                </button>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
            <?php foreach ($watchlist_players as $player): 
                $age = calculateAge($player['birth_day'], $player['birth_month'], $player['birth_year']);
            ?>
            <div class="dashboard-card" data-player-id="<?php echo $player['id']; ?>">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; width:100%;">
                    <div>
                        <h3><?php echo htmlspecialchars($player['first_name'] . ' ' . $player['last_name']); ?></h3>
                        <div style="color: var(--gray); margin-bottom: 10px;"><?php echo htmlspecialchars($player['position'] ?? 'N/A'); ?> | <?php echo htmlspecialchars($player['current_club'] ?? 'N/A'); ?> | <?php echo $age; ?> yrs</div>
                        <div class="rating-stars" style="margin-bottom: 10px;">
                            <?php 
                            $rating = min(5, max(0, round($player['rating'] ?? 0)));
                            for ($i = 1; $i <= 5; $i++) {
                                echo '<i class="fas fa-star' . ($i > $rating ? ' empty' : '') . '"></i>';
                            }
                            ?>
                        </div>
                        <span class="status recommended">On Watchlist</span>
                    </div>
                    <div class="user-avatar" style="width: 60px; height: 60px; background-color: var(--primary); flex-shrink: 0;">
                        <?php echo substr($player['first_name'], 0, 1) . substr($player['last_name'], 0, 1); ?>
                    </div>
                </div>
                <div style="margin-top: 15px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; width:100%;">
                    <div>
                        <div style="font-size: 12px; color: var(--gray);">Height</div>
                        <div><?php echo ($player['height'] ?? 'N/A') . ' cm'; ?></div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--gray);">Weight</div>
                        <div><?php echo ($player['weight'] ?? 'N/A') . ' kg'; ?></div>
                    </div>
                </div>
                <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center;">
                    <button class="action-btn btn-primary" onclick="viewPlayer(<?php echo $player['id']; ?>)"><i class="fas fa-file-alt"></i> Report</button>
                    <button class="action-btn btn-success" onclick="requestPlayer(<?php echo $player['id']; ?>)"><i class="fas fa-file-signature"></i> Request</button>
                    <button class="action-btn btn-danger" onclick="removeFromWatchlist(<?php echo $player['id']; ?>, this)"><i class="fas fa-eye-slash"></i> Remove</button>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (count($watchlist_players) === 0): ?>
            <div class="dashboard-card" style="text-align: center; padding: 40px; grid-column: 1 / -1;">
                <i class="fas fa-eye-slash" style="font-size: 2rem; color: var(--gray); margin-bottom: 15px;"></i>
                <h3>Your watchlist is empty</h3>
                <p style="color: var(--gray);">Add players to your watchlist to track their progress</p>
                <button class="action-btn btn-primary" style="margin-top: 15px;" onclick="showSection('players')">Browse Players</button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Signing Requests Content -->
    <div class="content-section" data-content="requests">
        <h2 style="margin-bottom: 20px;">Signing Requests</h2>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div class="search-bar" style="width: 400px;">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search requests..." id="requestSearch" onkeyup="searchTable(this, '.content-section[data-content=requests] .data-table')">
            </div>
            <button class="action-btn btn-primary" onclick="showNewRequestModal()">
                <i class="fas fa-plus"></i> New Request
            </button>
        </div>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Player</th>
                    <th>Type</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($club_requests as $request): ?>
                <tr>
                    <td>#REQ-<?php echo str_pad($request['id'], 3, '0', STR_PAD_LEFT); ?></td>
                    <td><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></td>
                    <td><?php echo ucfirst(str_replace('_', ' ', $request['request_type'])); ?></td>
                    <td><?php echo date('M j, Y', strtotime($request['created_at'])); ?></td>
                    <td><span class="status <?php echo $request['status']; ?>"><?php echo $request['status']; ?></span></td>
                    <td>
                        <button class="action-btn btn-primary" onclick="viewRequest(<?php echo $request['id']; ?>)">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <?php if ($request['status'] === 'pending'): ?>
                        <button class="action-btn btn-danger" onclick="cancelRequest(<?php echo $request['id']; ?>)">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($club_requests) === 0): ?>
                <tr>
                    <td colspan="6" style="text-align: center;">No requests found</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Match Videos Content -->
    <div class="content-section" data-content="videos">
        <h2 style="margin-bottom: 20px;">Match Videos & Analysis</h2>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div class="search-bar" style="width: 400px;">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search videos..." id="videoSearch" onkeyup="searchCards(this, '.content-section[data-content=videos] .dashboard-card')">
            </div>
            <div>
                <button class="action-btn btn-secondary" style="margin-right: 10px;" onclick="filterVideosByPlayer()">
                    <i class="fas fa-filter"></i> Filter by Player
                </button>
                <button class="action-btn btn-secondary" onclick="filterVideosByDate()">
                    <i class="fas fa-calendar"></i> Filter by Date
                </button>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px;">
            <?php foreach ($club_videos as $video): ?>
            <div class="dashboard-card">
                <h3><?php echo htmlspecialchars($video['title']); ?></h3>
                <div style="color: var(--gray); margin-bottom: 10px;"><?php echo htmlspecialchars($video['competition'] ?? 'Friendly'); ?> | <?php echo date('M j, Y', strtotime($video['created_at'])); ?></div>
                <div class="video-container">
                    <iframe src="<?php echo htmlspecialchars($video['video_url']); ?>" frameborder="0" allowfullscreen></iframe>
                </div>
                <div class="card-footer" style="text-align: right;">
                    <button class="action-btn btn-primary" onclick="analyzeVideo(<?php echo $video['id']; ?>)">
                        <i class="fas fa-chart-line"></i> Performance Analysis
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (count($club_videos) === 0): ?>
            <div class="dashboard-card" style="text-align: center; padding: 40px; grid-column: 1 / -1;">
                <i class="fas fa-video-slash" style="font-size: 2rem; color: var(--gray); margin-bottom: 15px;"></i>
                <h3>No videos available</h3>
                <p style="color: var(--gray);">Check back later for new video content.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Club Settings Content -->
    <div class="content-section" data-content="settings">
        <h2 style="margin-bottom: 20px;">Club Settings</h2>
        
        <div class="dashboard-card">
            <div class="card-header" style="padding:0; border:0;">
                <h3><i class="fas fa-user-cog" style="color: var(--primary);"></i> Club Profile & Preferences</h3>
            </div>
            <div class="card-body" style="padding: 20px 0 0 0;">
                <div style="display: flex; gap: 30px; margin-bottom: 20px; align-items:center;">
                    <div style="width: 150px; height: 150px; border-radius: 50%; background-color: #eee; display: flex; align-items: center; justify-content: center; flex-shrink:0;">
                        <i class="fas fa-shield-alt" style="font-size: 4rem; color: var(--gray);"></i>
                    </div>
                    <div style="flex: 1;">
                        <h3 style="margin-bottom: 10px;"><?php echo htmlspecialchars($club_info['organization'] ?? 'Your Football Club'); ?></h3>
                        <p style="color: var(--gray); margin-bottom: 15px;">Premier League Club</p>
                        <button class="action-btn btn-secondary" onclick="editClubProfile()"><i class="fas fa-edit"></i> Edit Profile</button>
                        <button class="action-btn btn-secondary" onclick="changeClubLogo()"><i class="fas fa-camera"></i> Change Logo</button>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                    <div>
                        <h4 style="margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Club Information</h4>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; color: var(--gray); margin-bottom: 5px; font-size:14px;">Club Name</label>
                            <input type="text" class="filter-select" value="<?php echo htmlspecialchars($club_info['organization'] ?? ''); ?>" disabled>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; color: var(--gray); margin-bottom: 5px; font-size:14px;">Contact Email</label>
                            <input type="text" class="filter-select" value="<?php echo htmlspecialchars($club_info['email'] ?? ''); ?>" disabled>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; color: var(--gray); margin-bottom: 5px; font-size:14px;">Phone</label>
                            <input type="text" class="filter-select" value="<?php echo htmlspecialchars($club_info['phone'] ?? ''); ?>" disabled>
                        </div>
                    </div>
                    <div>
                        <h4 style="margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Scouting & Notifications</h4>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; color: var(--gray); margin-bottom: 5px; font-size:14px;">Primary Positions Needed</label>
                            <select class="filter-select" multiple disabled>
                                <option>Forward</option>
                                <option selected>Midfielder</option>
                                <option selected>Defender</option>
                                <option>Goalkeeper</option>
                            </select>
                        </div>
                         <h4 style="margin-bottom: 15px;">Notifications</h4>
                        <div style="display: flex; align-items: center; margin-bottom: 10px;">
                            <input type="checkbox" id="newPlayers" checked style="margin-right: 10px;" disabled>
                            <label for="newPlayers">New players matching criteria</label>
                        </div>
                        <div style="display: flex; align-items: center;">
                            <input type="checkbox" id="reportUpdates" checked style="margin-right: 10px;" disabled>
                            <label for="reportUpdates">Scouting report updates</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer" style="text-align: right;">
                <button class="action-btn btn-secondary" style="margin-right: 10px;" onclick="cancelEdit()">Cancel</button>
                <button class="action-btn btn-primary" onclick="saveSettings()">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <div class="footer-links">
            <a href="#" class="footer-link">Privacy Policy</a>
            <a href="#" class="footer-link">Terms of Use</a>
            <a href="#" class="footer-link">Contact Support</a>
        </div>
        <div>© <?php echo date("Y"); ?> Ethiopian Online Football Scouting. All rights reserved.</div>
        <div style="margin-top: 5px; font-size: 12px;">Club Portal v1.0</div>
    </div>
</div>

<!-- Player Details Modal -->
<div id="playerModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Player Details</h2>
            <button class="close-modal" onclick="closeModal()">&times;</button>
        </div>
        <div id="playerDetails" style="text-align:center;">
             Loading player data...
        </div>
    </div>
</div>

<!-- Request Modal -->
<div id="requestModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>New Player Request</h2>
            <button class="close-modal" onclick="closeModal()">&times;</button>
        </div>
        <div id="requestForm">
            <div style="margin-bottom:15px">
                <label>Player</label>
                <select class="filter-select" id="modalPlayerSelect">
                    <option value="">Select Player from Watchlist</option>
                    <?php foreach ($watchlist_players as $player): ?>
                    <option value="<?php echo $player['id']; ?>"><?php echo htmlspecialchars($player['first_name'] . ' ' . $player['last_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:15px">
                <label>Request Type</label>
                <select class="filter-select" id="modalRequestType">
                    <option value="trial">Trial Session</option>
                    <option value="signing">Formal Signing Offer</option>
                    <option value="evaluation">Additional Evaluation</option>
                </select>
            </div>
            <div style="margin-bottom:15px">
                <label>Notes</label>
                <textarea class="filter-select" id="modalRequestNotes" rows="4" style="height:100px"></textarea>
            </div>
            <button type="button" class="action-btn btn-primary" style="width:100%; padding:10px;" onclick="submitModalRequest()">Submit Request</button>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        document.getElementById('sidebarToggle').addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        });

        // Dark Mode Toggle
        document.getElementById('darkModeToggle').addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
        });
        if (localStorage.getItem('darkMode') === 'true') {
            document.body.classList.add('dark-mode');
        }

        // Notification dropdown
        const notificationIcon = document.getElementById('notificationIcon');
        const notificationDropdown = document.getElementById('notificationDropdown');
        notificationIcon.addEventListener('click', function(e) {
            e.stopPropagation();
            notificationDropdown.classList.toggle('show');
            if (notificationDropdown.classList.contains('show')) {
                loadNotifications();
            }
        });
        document.addEventListener('click', function(e) {
            if (!notificationIcon.contains(e.target)) {
                notificationDropdown.classList.remove('show');
            }
        });

        // Menu item navigation
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function() {
                const section = this.dataset.section;
                if (section === 'logout') {
                    if (confirm('Are you sure you want to logout?')) {
                        window.location.href = 'logout.php';
                    }
                    return;
                }
                showSection(section);
            });
        });

        // Initial load
        loadNotifications();
        setInterval(loadNotifications, 30000); // Refresh notifications every 30 seconds
    });

    function showSection(section) {
        document.querySelectorAll('.menu-item').forEach(i => i.classList.remove('active'));
        document.querySelector(`.menu-item[data-section="${section}"]`).classList.add('active');
        
        document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
        document.querySelector(`.content-section[data-content="${section}"]`).classList.add('active');
    }

    function filterPlayers(position) {
        const url = new URL(window.location.href);
        url.searchParams.set('page', 'players'); // To stay on the same tab after reload
        if (position) {
            url.searchParams.set('filter', position);
        } else {
            url.searchParams.delete('filter');
        }
        window.location.href = url.toString();
    }
    
    // Generic search for tables
    function searchTable(input, tableSelector) {
        const searchTerm = input.value.toLowerCase();
        const rows = document.querySelectorAll(`${tableSelector} tbody tr`);
        rows.forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(searchTerm) ? '' : 'none';
        });
    }

    // Generic search for card layouts
    function searchCards(input, cardSelector) {
        const searchTerm = input.value.toLowerCase();
        const cards = document.querySelectorAll(cardSelector);
        cards.forEach(card => {
            card.style.display = card.textContent.toLowerCase().includes(searchTerm) ? '' : 'none';
        });
    }

    function sendAjaxRequest(action, body, callback) {
        fetch('club-dashboard.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=${action}&${body}`
        })
        .then(response => response.json())
        .then(data => callback(data))
        .catch(error => {
            console.error('AJAX Error:', error);
            alert('An error occurred. Please try again.');
        });
    }

    function addToWatchlist(playerId, button) {
        sendAjaxRequest('add_to_watchlist', `player_id=${playerId}`, (data) => {
            if (data.success) {
                alert('Player added to watchlist successfully');
                // Optimistically update UI
                button.textContent = 'Remove';
                button.classList.remove('btn-success');
                button.classList.add('btn-danger');
                button.onclick = () => removeFromWatchlist(playerId, button);
                location.reload(); // Or reload for simplicity
            } else {
                alert(data.message || 'Failed to add player to watchlist');
            }
        });
    }

    function removeFromWatchlist(playerId, button) {
        if (confirm('Are you sure you want to remove this player from your watchlist?')) {
            sendAjaxRequest('remove_from_watchlist', `player_id=${playerId}`, (data) => {
                if (data.success) {
                    alert('Player removed from watchlist successfully');
                     // If on watchlist page, remove the card
                    const card = document.querySelector(`.content-section[data-content="watchlist"] .dashboard-card[data-player-id="${playerId}"]`);
                    if (card) {
                        card.remove();
                    } else {
                        location.reload();
                    }
                } else {
                    alert('Failed to remove player from watchlist');
                }
            });
        }
    }

    function clearWatchlist() {
        if (confirm('Are you sure you want to clear your entire watchlist? This action cannot be undone.')) {
            sendAjaxRequest('clear_watchlist', '', (data) => {
                 if (data.success) {
                    alert('Watchlist cleared successfully');
                    location.reload();
                } else {
                    alert('Failed to clear watchlist.');
                }
            });
        }
    }

    function submitRequest() {
        const playerId = document.getElementById('requestPlayer').value;
        const requestType = document.getElementById('requestType').value;
        const notes = document.getElementById('requestNotes').value;
        
        if (!playerId || !requestType) {
            alert('Please select a player and request type');
            return;
        }
        const body = `player_id=${playerId}&request_type=${requestType}&notes=${encodeURIComponent(notes)}`;
        sendAjaxRequest('submit_request', body, (data) => {
            if (data.success) {
                alert('Request submitted successfully');
                location.reload();
            } else {
                alert('Failed to submit request');
            }
        });
    }

    function showNewRequestModal() {
        document.getElementById('requestModal').style.display = 'block';
    }

    function submitModalRequest() {
        const playerId = document.getElementById('modalPlayerSelect').value;
        const requestType = document.getElementById('modalRequestType').value;
        const notes = document.getElementById('modalRequestNotes').value;
        
        if (!playerId) {
            alert('Please select a player');
            return;
        }
        const body = `player_id=${playerId}&request_type=${requestType}&notes=${encodeURIComponent(notes)}`;
        sendAjaxRequest('submit_request', body, (data) => {
            if (data.success) {
                alert('Request submitted successfully');
                closeModal();
                location.reload();
            } else {
                alert('Failed to submit request');
            }
        });
    }

    function viewPlayer(playerId) {
        document.getElementById('playerDetails').innerHTML = 'Loading player data...';
        document.getElementById('playerModal').style.display = 'block';
        fetch(`get_player_details.php?id=${playerId}`)
            .then(response => response.text())
            .then(html => {
                document.getElementById('playerDetails').innerHTML = html;
            })
            .catch(err => {
                 document.getElementById('playerDetails').innerHTML = 'Failed to load player details.';
            });
    }

    function viewRequest(requestId) {
        alert(`Viewing details for request ID: ${requestId}. This would open a detailed view.`);
    }

    function cancelRequest(requestId) {
        if (confirm('Are you sure you want to cancel this request?')) {
            sendAjaxRequest('cancel_request', `request_id=${requestId}`, (data) => {
                if(data.success) {
                    alert('Request cancelled successfully');
                    location.reload();
                } else {
                    alert('Failed to cancel request.');
                }
            });
        }
    }

    // Modal close functions
    function closeModal() {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.style.display = 'none';
        });
    }
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            closeModal();
        }
    }
    
    function requestPlayer(playerId) {
        document.getElementById('modalPlayerSelect').value = playerId;
        showNewRequestModal();
    }

    // --- NOTIFICATION SCRIPT ---
    function loadNotifications() {
        sendAjaxRequest('get_notifications', '', (data) => {
            const notificationList = document.getElementById('notificationList');
            const notificationBadge = document.getElementById('notificationBadge');
            
            if (data.notifications && data.notifications.length > 0) {
                notificationList.innerHTML = data.notifications.map(n => `
                    <div class="notification-item ${n.is_read == 0 ? 'unread' : ''}" onclick="viewNotification(${n.id}, '${n.link || '#'}')">
                        <div class="notification-title">${n.message || 'New Notification'}</div>
                        <div class="notification-time">${new Date(n.created_at).toLocaleString()}</div>
                    </div>
                `).join('');
            } else {
                notificationList.innerHTML = '<div class="notification-item" style="text-align: center; color: var(--gray);">No new notifications</div>';
            }

            if (data.unread_count > 0) {
                notificationBadge.textContent = data.unread_count;
                notificationBadge.style.display = 'flex';
            } else {
                notificationBadge.style.display = 'none';
            }
        });
    }

    function markAllNotificationsRead() {
        sendAjaxRequest('mark_all_notifications_read', '', (data) => {
            if (data.success) {
                loadNotifications(); // Refresh list to show all as read
            }
        });
    }

    function viewNotification(notificationId, link) {
        sendAjaxRequest('mark_notification_read', `notification_id=${notificationId}`, (data) => {
            if (data.success) {
                loadNotifications(); // Refresh list to show it as read
                if (link && link !== '#') {
                    window.location.href = link;
                } else {
                    alert('Notification viewed. In a real app, you would be redirected.');
                }
            }
        });
    }
    
    // --- PLACEHOLDER FUNCTIONS ---
    function analyzeVideo(videoId) { alert(`Analyze video with ID: ${videoId}`); }
    function editClubProfile() { alert('Edit club profile functionality would be implemented here'); }
    function changeClubLogo() { alert('Change club logo functionality would be implemented here'); }
    function saveSettings() { alert('Club settings saved successfully (demo)'); }
    function cancelEdit() { if (confirm('Discard all changes?')) { location.reload(); } }
    function showAdvancedFilters() { alert('Advanced filters would be displayed here'); }
    function sortWatchlist() { alert('Sort watchlist functionality would be implemented here'); }
    function filterVideosByPlayer() { alert('Filter videos by player functionality would be implemented here'); }
    function filterVideosByDate() { alert('Filter videos by date functionality would be implemented here'); }

</script>

</body>
</html>