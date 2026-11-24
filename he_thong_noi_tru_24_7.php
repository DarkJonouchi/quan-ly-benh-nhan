<?php
declare(strict_types=1);

/**
 * ============================================================================
 * HỆ THỐNG QUẢN LÝ BỆNH NHÂN & VẬN HÀNH NỘI TRÚ 24/24 (PHIÊN BẢN 1 TẬP TIN)
 * ============================================================================
 * Công nghệ: PHP 8.2+ thuần | SQLite 3 (WAL + FTS5) | Modern Clinical Bento UI
 * Quy mô thiết kế: 7 phòng chức năng, 200+ bệnh nhân nội trú, 27 nhân viên 24/24
 * Độ bền vững: Tự động khởi tạo thư mục storage/, sao lưu xoay vòng hàng ngày,
 *              không phụ thuộc CDN hay Composer, hoạt động mượt cả khi mất mạng.
 *
 * CẤU TRÚC MODULAR MVC TRONG 1 TẬP TIN DUY NHẤT:
 *  - [PHẦN 1] CONFIG     : Cấu hình hệ thống, ngưỡng sinh hiệu, ca trực, AI Adapter
 *  - [PHẦN 2] CORE       : Database (PDO SQLite WAL+FTS5), Security (CSRF/XSS/RBAC),
 *                          AuditLogger, BackupManager, AiVoiceAdapter
 *  - [PHẦN 3] MODULES    : Nghiệp vụ Dashboard 7 phòng, Bệnh nhân (1.5), Sinh hiệu,
 *                          Bàn giao ca (1.5.2.1), Công việc (1.2/1.3), Chat FTS5 (1.6.1.2),
 *                          Báo cáo (1.4), Xuất 4 định dạng (1.5.2.2), Quản trị SQLite (1.6)
 *  - [PHẦN 4] ROUTER/API : Xử lý REST API JSON, SSE Real-time, Export, PWA Manifest/SW
 *  - [PHẦN 5] VIEWS/UI   : Giao diện Modern Clinical Bento (Dark/Light Mode, Mobile 1 tay)
 * ============================================================================
 */

// Thiết lập múi giờ Việt Nam và báo lỗi an toàn cho môi trường vận hành
date_default_timezone_set('Asia/Ho_Chi_Minh');
error_reporting(E_ALL);
ini_set('display_errors', '0');

// ============================================================================
// [PHẦN 1] CONFIG - CẤU HÌNH LÕI HỆ THỐNG
// ============================================================================
final class AppConfig
{
    public const APP_NAME = 'Nội Trú 24/24 — Quản Lý Bệnh Nhân';
    public const APP_SHORT_NAME = 'NộiTrú24/7';
    public const APP_VERSION = '2.0.0-SingleFile';

    // Đường dẫn thư mục lưu trữ tự động tạo cùng cấp với file PHP này
    public static function storageDir(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'storage';
    }

    public static function dbPath(): string
    {
        return self::storageDir() . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'inpatient_24_7.sqlite';
    }

    public static function backupDir(): string
    {
        return self::storageDir() . DIRECTORY_SEPARATOR . 'backups';
    }

    public static function archiveDir(): string
    {
        return self::storageDir() . DIRECTORY_SEPARATOR . 'archives';
    }

    // Cấu hình Ca trực (có thể mở rộng thêm Ca Tối / Ca Đêm dễ dàng)
    public const SHIFTS = [
        'sang'  => ['label' => 'Ca Sáng',  'time' => '07:00 - 13:30'],
        'chieu' => ['label' => 'Ca Chiều', 'time' => '13:30 - 19:00'],
        'toi'   => ['label' => 'Ca Tối',   'time' => '19:00 - 07:00'],
    ];

    // Ngưỡng an toàn sinh hiệu (vượt ngưỡng -> Cảnh báo ĐỎ lên Dashboard 7 phòng)
    public const VITAL_THRESHOLDS = [
        'pulse_min' => 50,   'pulse_max' => 110,   // Mạch (lần/phút)
        'temp_min'  => 35.5, 'temp_max'  => 37.8,  // Nhiệt độ (°C)
        'resp_min'  => 12,   'resp_max'  => 24,    // Nhịp thở (lần/phút)
        'spo2_min'  => 94,                         // SpO2 (%)
        'sys_min'   => 90,   'sys_max'   => 140,   // Huyết áp tâm thu (mmHg)
        'dia_min'   => 60,   'dia_max'   => 90,    // Huyết áp tâm trương (mmHg)
    ];

    // Cấu hình mặc định AI Adapter cho Voice-to-Action
    // Chế độ hỗ trợ: 'local_rule' (Offline miễn phí 100%), 'gemini', 'ollama'
    public const DEFAULT_AI_CONFIG = [
        'mode'           => 'local_rule',
        'gemini_api_key' => '',
        'gemini_model'   => 'gemini-2.5-flash-lite',
        'ollama_url'     => 'http://127.0.0.1:11434/api/generate',
        'ollama_model'   => 'qwen2.5:3b',
    ];

    // Số ngày giữ bản sao lưu tự động xoay vòng
    public const AUTO_BACKUP_KEEP_DAYS = 14;

    // =========================================================================
    // DANH SÁCH MODULE HỆ THỐNG (Chỉnh tên, thứ tự, bật/tắt trực tiếp tại đây)
    // =========================================================================
    public const MODULES = [
        ['id' => 'dashboard', 'icon' => '📊', 'label' => 'Dashboard 7 Phòng',  'short' => '7 Phòng',   'title' => 'Dashboard 7 Phòng (Real-time)',      'roles' => [], 'enabled' => true],
        ['id' => 'patients',  'icon' => '🧑‍⚕️', 'label' => 'Bệnh nhân & 24/24', 'short' => 'Bệnh nhân', 'title' => 'Hồ sơ Bệnh nhân & Theo dõi 24/24',   'roles' => [], 'enabled' => true],
        ['id' => 'daily',     'icon' => '✅', 'label' => 'Việc hàng ngày',     'short' => 'Việc ngày', 'title' => 'Công việc Hàng ngày theo Ca trực',    'roles' => [], 'enabled' => true],
        ['id' => 'adhoc',     'icon' => '⚡', 'label' => 'Việc phát sinh',     'short' => 'Phát sinh', 'title' => 'Công việc Phát sinh Đột xuất',        'roles' => [], 'enabled' => true],
        ['id' => 'handover',  'icon' => '🔄', 'label' => 'Bàn giao ca',        'short' => 'Giao ca',   'title' => 'Bàn giao Ca Sáng / Tối 2 chiều',      'roles' => [], 'enabled' => true],
        ['id' => 'chat',      'icon' => '💬', 'label' => 'Trao đổi nội bộ',    'short' => 'Trao đổi',  'title' => 'Trao đổi Nội bộ & Tra cứu FTS5',     'roles' => [], 'enabled' => true],
        ['id' => 'reports',   'icon' => '📈', 'label' => 'Báo cáo tổng hợp',   'short' => 'Báo cáo',   'title' => 'Báo cáo Tổng hợp & Hiệu suất',        'roles' => [], 'enabled' => true],
        ['id' => 'admin',     'icon' => '🗄️', 'label' => 'Quản trị SQLite',    'short' => 'Quản trị',  'title' => 'Quản trị Thư viện SQLite & Sao lưu',  'roles' => ['admin', 'truong_dieu_duong'], 'enabled' => true],
    ];
}

// ============================================================================
// [PHẦN 2] CORE - BẢO MẬT, PHÂN QUYỀN, CSDL SQLITE WAL + FTS5 & AUDIT LOG
// ============================================================================
final class Security
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => 86400, // 24 giờ cho ca trực
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('NOITRU247_SESS');
        session_start();
    }

    // Tạo hoặc lấy CSRF token
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }

    // Kiểm tra CSRF token cho mọi yêu cầu POST/PUT/DELETE
    public static function verifyCsrf(?string $token): bool
    {
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if ($sessionToken === '' || $token === null || $token === '') {
            return false;
        }
        return hash_equals($sessionToken, $token);
    }

    // Chống XSS khi in ra HTML
    public static function e(mixed $val): string
    {
        return htmlspecialchars((string)($val ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // Mã hóa mật khẩu chuẩn Argon2id (tự động fallback Bcrypt nếu máy chủ không hỗ trợ)
    public static function hashPassword(string $plain): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($plain, $algo);
    }

    public static function clientIp(): string
    {
        return (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    // Kiểm tra phân quyền RBAC
    public static function hasRole(array $user, array $allowedRoles): bool
    {
        return in_array($user['role'] ?? '', $allowedRoles, true);
    }

    // Tự xác định ca trực hiện tại theo giờ thực tế
    public static function currentShift(): string
    {
        $hour = (int)date('G');
        if ($hour >= 7 && $hour < 13) {
            return 'sang';
        }
        if ($hour >= 13 && $hour < 19) {
            return 'chieu';
        }
        return 'toi';
    }
}

final class Database
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        // Tự động tạo cấu trúc thư mục lưu trữ
        foreach ([AppConfig::storageDir(), dirname(AppConfig::dbPath()), AppConfig::backupDir(), AppConfig::archiveDir()] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        // Tạo .htaccess chặn truy cập trực tiếp thư mục storage trên Apache
        $htaccess = AppConfig::storageDir() . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }

        $isNewDb = !file_exists(AppConfig::dbPath());

        $pdo = new PDO('sqlite:' . AppConfig::dbPath(), null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Tối ưu SQLite cho 27 nhân viên đọc/ghi đồng thời 24/24
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA busy_timeout = 10000;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA temp_store = MEMORY;');
        $pdo->exec('PRAGMA cache_size = -16000;'); // 16MB RAM cache

        self::$pdo = $pdo;

        if ($isNewDb) {
            self::initSchema($pdo);
            self::seedInitialData($pdo);
        } else {
            // Đảm bảo bảng cốt lõi tồn tại nếu file rỗng
            $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
            if (!$exists) {
                self::initSchema($pdo);
                self::seedInitialData($pdo);
            }
        }

        return self::$pdo;
    }

    /**
     * Sơ đồ CSDL SQLite hoàn chỉnh (Schema + Indexes + FTS5 + Triggers đồng bộ)
     */
    public static function initSchema(PDO $pdo): void
    {
        $sql = <<<'SQL'
-- 1. Người dùng & Phân quyền (Admin / Điều dưỡng trưởng / Nhân viên ca trực)
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    full_name TEXT NOT NULL,
    role TEXT NOT NULL CHECK(role IN ('admin','truong_dieu_duong','nhan_vien')),
    shift_group TEXT DEFAULT 'Ca A',
    phone TEXT DEFAULT '',
    password_hash TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- 2. Danh mục 7 Phòng chức năng
CREATE TABLE IF NOT EXISTS rooms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    capacity INTEGER NOT NULL DEFAULT 30,
    description TEXT DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 1
);

-- 3. Hồ sơ Bệnh nhân nội trú (quy mô 200+ BN, hỗ trợ lưu trữ xuất viện)
CREATE TABLE IF NOT EXISTS patients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_code TEXT NOT NULL UNIQUE,
    full_name TEXT NOT NULL,
    birth_year INTEGER,
    gender TEXT DEFAULT 'Nam',
    room_id INTEGER NOT NULL,
    bed_number TEXT DEFAULT '',
    medical_history TEXT DEFAULT '',
    special_care TEXT DEFAULT '',
    family_contact TEXT DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','discharged','archived')),
    admitted_at TEXT NOT NULL DEFAULT (date('now','localtime')),
    discharged_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (room_id) REFERENCES rooms(id)
);

-- 4. Theo dõi Sinh hiệu, Huyết áp, Ăn uống, Ngủ nghỉ 24/24 (1.5.1.1)
CREATE TABLE IF NOT EXISTS vital_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER NOT NULL,
    room_id INTEGER NOT NULL,
    recorded_by INTEGER NOT NULL,
    shift_code TEXT NOT NULL DEFAULT 'sang',
    pulse INTEGER,
    temperature REAL,
    resp_rate INTEGER,
    spo2 INTEGER,
    bp_sys INTEGER,
    bp_dia INTEGER,
    eating_status TEXT DEFAULT 'Ăn hết suất',
    eating_note TEXT DEFAULT '',
    sleep_hours REAL,
    sleep_quality TEXT DEFAULT 'Ngủ ngon',
    med_taken INTEGER NOT NULL DEFAULT 1,
    clinical_note TEXT DEFAULT '',
    is_abnormal INTEGER NOT NULL DEFAULT 0,
    abnormal_reasons TEXT DEFAULT '',
    raw_voice_text TEXT DEFAULT '',
    recorded_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    FOREIGN KEY (recorded_by) REFERENCES users(id)
);

-- 5. Mẫu Checklist Công việc hàng ngày (1.2.1)
CREATE TABLE IF NOT EXISTS daily_task_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id INTEGER, -- NULL = áp dụng chung cho cả 7 phòng
    shift_code TEXT NOT NULL CHECK(shift_code IN ('sang','chieu','toi')),
    title TEXT NOT NULL,
    assigned_role TEXT DEFAULT 'nhan_vien',
    sort_order INTEGER NOT NULL DEFAULT 1,
    is_active INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY (room_id) REFERENCES rooms(id)
);

-- 6. Nhật ký thực hiện Công việc hàng ngày (Tự reset theo task_date, giữ lịch sử)
CREATE TABLE IF NOT EXISTS daily_task_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    template_id INTEGER NOT NULL,
    task_date TEXT NOT NULL,
    room_id INTEGER NOT NULL,
    shift_code TEXT NOT NULL,
    completed_by INTEGER,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','done')),
    note TEXT DEFAULT '',
    completed_at TEXT,
    UNIQUE(template_id, task_date, room_id),
    FOREIGN KEY (template_id) REFERENCES daily_task_templates(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    FOREIGN KEY (completed_by) REFERENCES users(id)
);

-- 7. Công việc phát sinh đột xuất (1.3.1)
CREATE TABLE IF NOT EXISTS ad_hoc_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id INTEGER NOT NULL,
    patient_id INTEGER,
    shift_code TEXT NOT NULL CHECK(shift_code IN ('sang','chieu','toi')),
    title TEXT NOT NULL,
    description TEXT DEFAULT '',
    priority TEXT NOT NULL DEFAULT 'thuong' CHECK(priority IN ('thuong','gap','khan_cap')),
    assigned_to INTEGER,
    created_by INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'moi' CHECK(status IN ('moi','dang_xu_ly','hoan_thanh')),
    due_at TEXT,
    completed_at TEXT,
    backlog_reason TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 8. Bàn giao ca Sáng / Tối 2 chiều & Khóa chỉnh sửa sau khi chốt (1.5.2.1)
CREATE TABLE IF NOT EXISTS shift_handovers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    handover_date TEXT NOT NULL,
    shift_code TEXT NOT NULL CHECK(shift_code IN ('sang','toi')),
    from_user_id INTEGER NOT NULL,
    to_user_id INTEGER,
    summary_json TEXT NOT NULL,
    notes TEXT DEFAULT '',
    status TEXT NOT NULL DEFAULT 'cho_nhan' CHECK(status IN ('cho_nhan','da_chot')),
    handed_over_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    accepted_at TEXT,
    is_locked INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (from_user_id) REFERENCES users(id),
    FOREIGN KEY (to_user_id) REFERENCES users(id)
);

-- 9. Trao đổi / Chat nội bộ theo phòng và toàn viện (1.6.1.2)
CREATE TABLE IF NOT EXISTS chat_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_type TEXT NOT NULL DEFAULT 'toan_vien' CHECK(channel_type IN ('toan_vien','phong')),
    room_id INTEGER,
    patient_id INTEGER,
    sender_id INTEGER NOT NULL,
    message TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (sender_id) REFERENCES users(id)
);

-- 10. Nhật ký hệ thống (Audit Log bắt buộc cho mọi thao tác dữ liệu y tế)
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT NOT NULL,
    table_name TEXT NOT NULL,
    record_id INTEGER,
    old_values TEXT,
    new_values TEXT,
    ip_address TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- 11. Bảng siêu dữ liệu hệ thống (Cấu hình AI, trạng thái backup, tối ưu hóa)
CREATE TABLE IF NOT EXISTS system_meta (
    meta_key TEXT PRIMARY KEY,
    meta_value TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- ============================================================================
-- CHỈ MỤC TỐI ƯU HIỆU NĂNG 3-5 NĂM (INDEXES)
-- ============================================================================
CREATE INDEX IF NOT EXISTS idx_patients_room_status ON patients(room_id, status);
CREATE INDEX IF NOT EXISTS idx_patients_status ON patients(status);
CREATE INDEX IF NOT EXISTS idx_vitals_room_time ON vital_logs(room_id, recorded_at DESC);
CREATE INDEX IF NOT EXISTS idx_vitals_patient_time ON vital_logs(patient_id, recorded_at DESC);
CREATE INDEX IF NOT EXISTS idx_vitals_abnormal ON vital_logs(is_abnormal, recorded_at DESC);
CREATE INDEX IF NOT EXISTS idx_daily_logs_date_shift ON daily_task_logs(task_date, shift_code, room_id);
CREATE INDEX IF NOT EXISTS idx_adhoc_status_prio ON ad_hoc_tasks(status, priority, room_id);
CREATE INDEX IF NOT EXISTS idx_adhoc_created ON ad_hoc_tasks(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_handover_date ON shift_handovers(handover_date DESC, shift_code);
CREATE INDEX IF NOT EXISTS idx_chat_channel_time ON chat_messages(channel_type, room_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_time ON audit_logs(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_table_rec ON audit_logs(table_name, record_id);

-- ============================================================================
-- TÌM KIẾM TOÀN VĂN FTS5 (FULL-TEXT SEARCH) & TRIGGERS TỰ ĐỘNG ĐỒNG BỘ
-- ============================================================================
CREATE VIRTUAL TABLE IF NOT EXISTS patients_fts USING fts5(
    patient_code, full_name, medical_history, special_care, family_contact,
    content='patients', content_rowid='id', tokenize='unicode61'
);

CREATE TRIGGER IF NOT EXISTS trg_patients_ai AFTER INSERT ON patients BEGIN
    INSERT INTO patients_fts(rowid, patient_code, full_name, medical_history, special_care, family_contact)
    VALUES (new.id, new.patient_code, new.full_name, new.medical_history, new.special_care, new.family_contact);
END;

CREATE TRIGGER IF NOT EXISTS trg_patients_ad AFTER DELETE ON patients BEGIN
    INSERT INTO patients_fts(patients_fts, rowid, patient_code, full_name, medical_history, special_care, family_contact)
    VALUES ('delete', old.id, old.patient_code, old.full_name, old.medical_history, old.special_care, old.family_contact);
END;

CREATE TRIGGER IF NOT EXISTS trg_patients_au AFTER UPDATE ON patients BEGIN
    INSERT INTO patients_fts(patients_fts, rowid, patient_code, full_name, medical_history, special_care, family_contact)
    VALUES ('delete', old.id, old.patient_code, old.full_name, old.medical_history, old.special_care, old.family_contact);
    INSERT INTO patients_fts(rowid, patient_code, full_name, medical_history, special_care, family_contact)
    VALUES (new.id, new.patient_code, new.full_name, new.medical_history, new.special_care, new.family_contact);
END;

CREATE VIRTUAL TABLE IF NOT EXISTS chat_fts USING fts5(
    message,
    content='chat_messages', content_rowid='id', tokenize='unicode61'
);

CREATE TRIGGER IF NOT EXISTS trg_chat_ai AFTER INSERT ON chat_messages BEGIN
    INSERT INTO chat_fts(rowid, message) VALUES (new.id, new.message);
END;

CREATE TRIGGER IF NOT EXISTS trg_chat_ad AFTER DELETE ON chat_messages BEGIN
    INSERT INTO chat_fts(chat_fts, rowid, message) VALUES ('delete', old.id, old.message);
END;

CREATE TRIGGER IF NOT EXISTS trg_chat_au AFTER UPDATE ON chat_messages BEGIN
    INSERT INTO chat_fts(chat_fts, rowid, message) VALUES ('delete', old.id, old.message);
    INSERT INTO chat_fts(rowid, message) VALUES (new.id, new.message);
END;
SQL;
        $pdo->exec($sql);
    }

    /**
     * Dữ liệu khởi tạo ban đầu (3 tài khoản chuẩn vai trò, 7 phòng chức năng, mẫu BN & checklist)
     */
    private static function seedInitialData(PDO $pdo): void
    {
        $pdo->beginTransaction();
        try {
            // 1. Tài khoản mặc định (Khuyến nghị đổi mật khẩu sau khi triển khai thực tế)
            $stmtUser = $pdo->prepare(
                'INSERT INTO users (username, full_name, role, shift_group, phone, password_hash) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $users = [
                ['admin',      'Quản trị Hệ thống',    'admin',             'Quản trị', '0900000001', Security::hashPassword('Admin@2026')],
                ['ddtruong',   'ĐD Trưởng Minh Anh',   'truong_dieu_duong', 'Hành chính', '0900000002', Security::hashPassword('Truong@2026')],
                ['dieuduong1', 'ĐD Nguyễn Hoàng Nam',  'nhan_vien',         'Ca Sáng',  '0900000003', Security::hashPassword('CaTruc@2026')],
                ['dieuduong2', 'ĐD Trần Thị Thu Hà',   'nhan_vien',         'Ca Chiều', '0900000004', Security::hashPassword('CaTruc@2026')],
            ];
            foreach ($users as $u) {
                $stmtUser->execute($u);
            }

            // 2. 7 Phòng chức năng (tổng sức chứa ~200+ giường)
            $stmtRoom = $pdo->prepare('INSERT INTO rooms (code, name, capacity, description, sort_order) VALUES (?, ?, ?, ?, ?)');
            $rooms = [
                ['P1', 'Phòng 1 — Chăm sóc tích cực', 28, 'Bệnh nhân cần theo dõi sinh hiệu sát 24/24', 1],
                ['P2', 'Phòng 2 — Nội tổng hợp A',    30, 'Bệnh nhân bệnh lý nền tim mạch, huyết áp', 2],
                ['P3', 'Phòng 3 — Nội tổng hợp B',    30, 'Bệnh nhân điều trị ổn định, phục hồi', 3],
                ['P4', 'Phòng 4 — Chăm sóc đặc biệt', 28, 'Bệnh nhân hạn chế vận động, cần hỗ trợ ăn uống', 4],
                ['P5', 'Phòng 5 — Phục hồi chức năng',30, 'Bệnh nhân tập vận động và vật lý trị liệu', 5],
                ['P6', 'Phòng 6 — Theo dõi dài ngày', 28, 'Bệnh nhân nội trú dài hạn, chế độ dinh dưỡng riêng', 6],
                ['P7', 'Phòng 7 — Tiếp nhận & Dự phòng', 28, 'Bệnh nhân mới tiếp nhận hoặc chờ xuất viện', 7],
            ];
            foreach ($rooms as $r) {
                $stmtRoom->execute($r);
            }

            // 3. Bệnh nhân mẫu trải đều 7 phòng (dễ dàng thêm tới 200+ BN)
            $stmtPat = $pdo->prepare(
                'INSERT INTO patients (patient_code, full_name, birth_year, gender, room_id, bed_number, medical_history, special_care, family_contact)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $patients = [
                ['BN001', 'Nguyễn Văn An',    1954, 'Nam', 1, 'G01', 'Tăng huyết áp, đái tháo đường type 2', 'Đo huyết áp 4 lần/ngày, ăn nhạt', 'Nguyễn Văn Bình (Con) - 0912345601'],
                ['BN002', 'Trần Thị Bích',    1960, 'Nữ',  1, 'G02', 'Suy tim độ II, khó thở khi gắng sức',  'Theo dõi SpO2 liên tục, kê cao đầu', 'Lê Minh Tuấn (Con) - 0912345602'],
                ['BN003', 'Lê Hoàng Cường',   1948, 'Nam', 2, 'G01', 'Di chứng tai biến mạch máu não',       'Hỗ trợ xoay trở mỗi 2 giờ chống loét', 'Lê Thị Hoa (Vợ) - 0912345603'],
                ['BN004', 'Phạm Thị Dung',    1957, 'Nữ',  2, 'G05', 'Loãng xương, tăng huyết áp',           'Cẩn thận té ngã khi đi vệ sinh', 'Phạm Quốc Đạt (Con) - 0912345604'],
                ['BN005', 'Võ Minh Đức',      1965, 'Nam', 3, 'G01', 'Viêm phổi ổn định, COPD nhẹ',          'Khí dung sáng chiều theo y lệnh', 'Võ Thị Lan (Em) - 0912345605'],
                ['BN006', 'Đặng Thị Hạnh',    1952, 'Nữ',  3, 'G03', 'Rối loạn giấc ngủ, suy nhược',         'Cháo dinh dưỡng chia 5 bữa nhỏ', 'Đặng Văn Hải (Con) - 0912345606'],
                ['BN007', 'Bùi Quốc Khánh',   1946, 'Nam', 4, 'G01', 'Sa sút trí tuệ, hạn chế vận động',     'Cần đút ăn, theo dõi lượng nước uống', 'Bùi Thị Mai (Con) - 0912345607'],
                ['BN008', 'Huỳnh Thị Lan',    1959, 'Nữ',  4, 'G04', 'Đái tháo đường, viêm khớp gối',        'Kiểm tra đường huyết trước ăn sáng', 'Huỳnh Văn Long (Con) - 0912345608'],
                ['BN009', 'Ngô Văn Mạnh',     1968, 'Nam', 5, 'G02', 'Sau phẫu thuật khớp háng',             'Tập vận động nhẹ buổi sáng', 'Ngô Thị Nga (Vợ) - 0912345609'],
                ['BN010', 'Dương Thị Ngọc',   1955, 'Nữ',  5, 'G06', 'Tăng huyết áp ổn định',                'Theo dõi giấc ngủ ban đêm', 'Dương Văn Phúc (Con) - 0912345610'],
                ['BN011', 'Trịnh Văn Quang',  1950, 'Nam', 6, 'G01', 'Bệnh thận mạn giai đoạn 3',            'Hạn chế muối và đạm theo thực đơn', 'Trịnh Thị Oanh (Con) - 0912345611'],
                ['BN012', 'Lý Thị Sen',       1962, 'Nữ',  6, 'G04', 'Thiếu máu nhẹ, rối loạn tiền đình',    'Hỗ trợ đứng dậy từ từ', 'Lý Văn Tài (Con) - 0912345612'],
                ['BN013', 'Phan Văn Thắng',   1970, 'Nam', 7, 'G01', 'Mới nhập viện theo dõi huyết áp',      'Đánh giá ban đầu trong 48 giờ', 'Phan Thị Uyên (Vợ) - 0912345613'],
                ['BN014', 'Đỗ Thị Xuân',      1958, 'Nữ',  7, 'G02', 'Ổn định chuẩn bị xuất viện',           'Hoàn tất hồ sơ bàn giao gia đình', 'Đỗ Văn Vinh (Con) - 0912345614'],
            ];
            foreach ($patients as $p) {
                $stmtPat->execute($p);
            }

            // 4. Checklist Công việc hàng ngày (Ca Sáng & Ca Chiều)
            $stmtTpl = $pdo->prepare('INSERT INTO daily_task_templates (room_id, shift_code, title, sort_order) VALUES (?, ?, ?, ?)');
            $templates = [
                [null, 'sang',  'Đo sinh hiệu & huyết áp đầu ca sáng cho toàn phòng', 1],
                [null, 'sang',  'Phát thuốc sáng & xác nhận bệnh nhân uống đủ liều', 2],
                [null, 'sang',  'Kiểm tra vệ sinh buồng bệnh, thay ga giường cần thiết', 3],
                [null, 'sang',  'Hỗ trợ ăn sáng & ghi nhận khẩu phần ăn', 4],
                [null, 'chieu', 'Đo sinh hiệu & kiểm tra lại các ca cần theo dõi sát', 1],
                [null, 'chieu', 'Phát thuốc chiều & chăm sóc vệ sinh cá nhân', 2],
                [null, 'chieu', 'Hỗ trợ bữa chiều & kiểm tra lượng nước uống', 3],
                [null, 'chieu', 'Tổng hợp diễn biến chuẩn bị bàn giao ca tối', 4],
                [null, 'toi',   'Đi buồng kiểm tra giấc ngủ, đèn đêm và an toàn giường', 1],
                [null, 'toi',   'Đo sinh hiệu định kỳ ca đêm cho bệnh nhân nguy cơ cao', 2],
            ];
            foreach ($templates as $t) {
                $stmtTpl->execute($t);
            }

            // 5. Dữ liệu sinh hiệu mẫu (bao gồm 1 ca bất thường ở Phòng 1 để Dashboard hiển thị màu Đỏ)
            $stmtVital = $pdo->prepare(
                'INSERT INTO vital_logs (patient_id, room_id, recorded_by, shift_code, pulse, temperature, resp_rate, spo2, bp_sys, bp_dia, eating_status, sleep_hours, sleep_quality, med_taken, clinical_note, is_abnormal, abnormal_reasons)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmtVital->execute([1, 1, 3, 'sang', 114, 37.9, 22, 95, 155, 95, 'Ăn 1/2 suất', 5.0, 'Khó ngủ', 1, 'Huyết áp tăng và sốt nhẹ, đã báo điều dưỡng trưởng', 1, 'Mạch cao (114); Sốt (37.9°C); Huyết áp cao (155/95)']);
            $stmtVital->execute([2, 1, 3, 'sang', 84,  36.7, 18, 97, 125, 80, 'Ăn hết suất', 7.0, 'Ngủ ngon', 1, 'Ổn định, thở đều', 0, '']);
            $stmtVital->execute([5, 3, 3, 'sang', 78,  36.6, 17, 98, 128, 78, 'Ăn hết suất', 7.5, 'Ngủ ngon', 1, 'Khí dung xong, dễ thở', 0, '']);

            // 6. Công việc phát sinh mẫu (Phòng 2 có việc Gấp -> Dashboard màu Vàng)
            $stmtAdhoc = $pdo->prepare(
                'INSERT INTO ad_hoc_tasks (room_id, patient_id, shift_code, title, description, priority, assigned_to, created_by, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmtAdhoc->execute([2, 3, 'sang', 'Hỗ trợ xoay trở & kiểm tra vùng tỳ đè', 'Người nhà báo bệnh nhân mỏi lưng, cần kiểm tra da vùng cùng cụt', 'gap', 3, 2, 'dang_xu_ly']);
            $stmtAdhoc->execute([4, 7, 'chieu', 'Bổ sung sữa dinh dưỡng theo chỉ định', 'Cập nhật khay ăn chiều cho giường G01', 'thuong', 4, 2, 'moi']);

            // 7. Tin nhắn trao đổi nội bộ mẫu
            $stmtChat = $pdo->prepare('INSERT INTO chat_messages (channel_type, room_id, patient_id, sender_id, message) VALUES (?, ?, ?, ?, ?)');
            $stmtChat->execute(['toan_vien', null, null, 2, 'Chào cả nhóm trực! Lưu ý theo dõi sát huyết áp BN001 ở Phòng 1 trong ca hôm nay nhé.']);
            $stmtChat->execute(['phong', 1, 1, 3, 'Phòng 1 đã đo lại sinh hiệu BN001 Nguyễn Văn An, đang cho nghỉ ngơi và theo dõi sau 30 phút.']);

            // 8. Lưu cấu hình AI mặc định vào system_meta
            $stmtMeta = $pdo->prepare('INSERT OR REPLACE INTO system_meta (meta_key, meta_value) VALUES (?, ?)');
            $stmtMeta->execute(['ai_config', json_encode(AppConfig::DEFAULT_AI_CONFIG, JSON_UNESCAPED_UNICODE)]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function getMeta(string $key, ?string $default = null): ?string
    {
        $stmt = self::conn()->prepare('SELECT meta_value FROM system_meta WHERE meta_key = ?');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val === false ? $default : (string)$val;
    }

    public static function setMeta(string $key, string $value): void
    {
        $stmt = self::conn()->prepare(
            "INSERT INTO system_meta (meta_key, meta_value, updated_at) VALUES (?, ?, datetime('now','localtime'))
             ON CONFLICT(meta_key) DO UPDATE SET meta_value = excluded.meta_value, updated_at = datetime('now','localtime')"
        );
        $stmt->execute([$key, $value]);
    }
}

final class AuditLogger
{
    public static function log(?int $userId, string $action, string $table, ?int $recordId, mixed $oldVal = null, mixed $newVal = null): void
    {
        $stmt = Database::conn()->prepare(
            'INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $action,
            $table,
            $recordId,
            $oldVal !== null ? json_encode($oldVal, JSON_UNESCAPED_UNICODE) : null,
            $newVal !== null ? json_encode($newVal, JSON_UNESCAPED_UNICODE) : null,
            Security::clientIp(),
        ]);
    }
}

final class BackupManager
{
    /**
     * Tự động sao lưu xoay vòng hằng ngày (chạy gọn nhẹ 1 lần/ngày khi có truy cập)
     */
    public static function runAutoBackupIfNeeded(): void
    {
        $today = date('Y-m-d');
        $lastBackup = Database::getMeta('last_auto_backup_date', '');
        if ($lastBackup === $today) {
            return;
        }
        try {
            self::createBackup('auto_' . $today);
            self::rotateOldBackups();
            Database::setMeta('last_auto_backup_date', $today);
        } catch (Throwable) {
            // Không làm gián đoạn nghiệp vụ lâm sàng nếu quyền ghi backup lỗi
        }
    }

    public static function createBackup(string $prefix = 'manual'): array
    {
        $pdo = Database::conn();
        // Đồng bộ WAL vào file chính trước khi sao lưu để đảm bảo toàn vẹn 100%
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE);');

        $stamp = date('Ymd_His');
        $baseName = $prefix . '_' . $stamp;
        $sqliteTarget = AppConfig::backupDir() . DIRECTORY_SEPARATOR . $baseName . '.sqlite';

        if (!copy(AppConfig::dbPath(), $sqliteTarget)) {
            throw new RuntimeException('Không thể sao chép tập tin CSDL SQLite.');
        }

        // Đóng gói ZIP nếu máy chủ PHP có tiện ích ZipArchive
        if (class_exists('ZipArchive')) {
            $zipTarget = AppConfig::backupDir() . DIRECTORY_SEPARATOR . $baseName . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipTarget, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $zip->addFile($sqliteTarget, $baseName . '.sqlite');
                $zip->close();
                @unlink($sqliteTarget);
                return ['file' => basename($zipTarget), 'path' => $zipTarget, 'size' => filesize($zipTarget)];
            }
        }

        return ['file' => basename($sqliteTarget), 'path' => $sqliteTarget, 'size' => filesize($sqliteTarget)];
    }

    private static function rotateOldBackups(): void
    {
        $files = glob(AppConfig::backupDir() . DIRECTORY_SEPARATOR . 'auto_*.*') ?: [];
        rsort($files);
        $keep = AppConfig::AUTO_BACKUP_KEEP_DAYS;
        if (count($files) > $keep) {
            foreach (array_slice($files, $keep) as $oldFile) {
                @unlink($oldFile);
            }
        }
    }

    public static function listBackups(): array
    {
        $files = glob(AppConfig::backupDir() . DIRECTORY_SEPARATOR . '*.{sqlite,zip}', GLOB_BRACE) ?: [];
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $result = [];
        foreach ($files as $f) {
            $result[] = [
                'name' => basename($f),
                'size' => filesize($f),
                'modified_at' => date('Y-m-d H:i:s', (int)filemtime($f)),
            ];
        }
        return $result;
    }

    /**
     * Xuất gói lưu trữ dài hạn theo Tháng hoặc Năm (1.6.1.3) giúp DB chính luôn nhẹ sau 3-5 năm
     */
    public static function buildArchivePackage(int $year, ?int $month = null): array
    {
        $pdo = Database::conn();
        $prefix = $month ? sprintf('%04d-%02d', $year, $month) : sprintf('%04d', $year);
        $like = $prefix . '%';

        $fetch = static function (string $sql, array $params) use ($pdo): array {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll();
        };

        $payload = [
            'exported_at' => date('Y-m-d H:i:s'),
            'period'      => $prefix,
            'app_version' => AppConfig::APP_VERSION,
            'patients'    => $fetch('SELECT * FROM patients ORDER BY id', []),
            'vital_logs'  => $fetch('SELECT * FROM vital_logs WHERE recorded_at LIKE ? ORDER BY recorded_at', [$like]),
            'daily_tasks' => $fetch('SELECT * FROM daily_task_logs WHERE task_date LIKE ? ORDER BY task_date', [$like]),
            'ad_hoc_tasks'=> $fetch('SELECT * FROM ad_hoc_tasks WHERE created_at LIKE ? ORDER BY created_at', [$like]),
            'handovers'   => $fetch('SELECT * FROM shift_handovers WHERE handover_date LIKE ? ORDER BY handover_date', [$like]),
            'chat_logs'   => $fetch('SELECT * FROM chat_messages WHERE created_at LIKE ? ORDER BY created_at', [$like]),
            'audit_logs'  => $fetch('SELECT * FROM audit_logs WHERE created_at LIKE ? ORDER BY created_at', [$like]),
        ];

        $fileBase = 'archive_' . str_replace('-', '_', $prefix) . '_' . date('Ymd_His');
        $jsonPath = AppConfig::archiveDir() . DIRECTORY_SEPARATOR . $fileBase . '.json';
        file_put_contents($jsonPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if (class_exists('ZipArchive')) {
            $zipPath = AppConfig::archiveDir() . DIRECTORY_SEPARATOR . $fileBase . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $zip->addFile($jsonPath, $fileBase . '.json');
                $zip->close();
                @unlink($jsonPath);
                return ['file' => basename($zipPath), 'path' => $zipPath];
            }
        }

        return ['file' => basename($jsonPath), 'path' => $jsonPath];
    }
}

/**
 * ============================================================================
 * AI ADAPTER CHO VOICE-TO-ACTION TIẾNG VIỆT
 * Dễ dàng chuyển đổi giữa: local_rule (Offline), gemini (Cloud), ollama (Local AI)
 * ============================================================================
 */
final class AiVoiceAdapter
{
    public static function getConfig(): array
    {
        $raw = Database::getMeta('ai_config');
        $cfg = $raw ? (json_decode($raw, true) ?: []) : [];
        return array_merge(AppConfig::DEFAULT_AI_CONFIG, $cfg);
    }

    /**
     * Phân tích câu nói tiếng Việt thành dữ liệu có cấu trúc cho Thẻ xác nhận 2 giây
     */
    public static function parseVietnameseVoice(string $text): array
    {
        $text = trim($text);
        $cfg = self::getConfig();
        $parsed = null;

        if ($cfg['mode'] === 'gemini' && !empty($cfg['gemini_api_key']) && function_exists('curl_init')) {
            $parsed = self::callGemini($text, $cfg);
        } elseif ($cfg['mode'] === 'ollama' && !empty($cfg['ollama_url']) && function_exists('curl_init')) {
            $parsed = self::callOllama($text, $cfg);
        }

        // Luôn có bộ phân tích tiếng Việt nội bộ (Offline) làm nền tảng hoặc dự phòng an toàn
        if (!$parsed) {
            $parsed = self::parseLocalRules($text);
        }

        // Đối chiếu Phòng và Bệnh nhân trong SQLite để gợi ý khớp chính xác
        return self::enrichWithDatabaseMatch($parsed, $text, $cfg['mode']);
    }

    private static function parseLocalRules(string $text): array
    {
        $lower = mb_strtolower($text, 'UTF-8');
        $intent = 'vital'; // Mặc định ghi sinh hiệu & chăm sóc

        if (preg_match('/(phát sinh|đột xuất|khẩn cấp|sự cố|hỏng|vỡ|báo gấp|cần xử lý)/u', $lower)) {
            $intent = 'adhoc';
        } elseif (preg_match('/(nhắn phòng|nhắn tin|ghi chú phòng|thông báo)/u', $lower)) {
            $intent = 'chat';
        }

        // Bóc tách số phòng (1..7)
        $roomNumber = null;
        if (preg_match('/phòng\s*(?:số\s*)?([1-7])/u', $lower, $m)) {
            $roomNumber = (int)$m[1];
        }

        // Bóc tách tên bệnh nhân hoặc mã BN
        $patientHint = '';
        if (preg_match('/(bn\s*\d+)/iu', $text, $m)) {
            $patientHint = strtoupper(str_replace(' ', '', $m[1]));
        } elseif (preg_match('/(?:bệnh nhân|bn|ông|bà|cô|chú|anh|chị)\s+([a-zA-ZÀ-ỹ\s]{3,35}?)(?:,|huyết áp|mạch|nhiệt|spo2|ăn|ngủ|uống|phát sinh|cần|$)/iu', $text, $m)) {
            $patientHint = trim($m[1]);
        }

        // Bóc tách Huyết áp (ví dụ: 130/80 hoặc 130 trên 80)
        $bpSys = null;
        $bpDia = null;
        if (preg_match('/(?:huyết áp|ha)\s*(?:là|:)?\s*(\d{2,3})\s*(?:\/|trên|-)\s*(\d{2,3})/u', $lower, $m)) {
            $bpSys = (int)$m[1];
            $bpDia = (int)$m[2];
        }

        // Bóc tách Mạch
        $pulse = null;
        if (preg_match('/mạch\s*(?:là|:)?\s*(\d{2,3})/u', $lower, $m)) {
            $pulse = (int)$m[1];
        }

        // Bóc tách Nhiệt độ (36.8 hoặc 36,8 hoặc 37 độ 5)
        $temp = null;
        if (preg_match('/(?:nhiệt độ|sốt)\s*(?:là|:)?\s*(\d{2})[.,](\d)/u', $lower, $m)) {
            $temp = (float)($m[1] . '.' . $m[2]);
        } elseif (preg_match('/(\d{2})\s*độ\s*(\d)?/u', $lower, $m)) {
            $temp = (float)($m[1] . '.' . ($m[2] ?? '0'));
        }

        // Bóc tách Nhịp thở
        $resp = null;
        if (preg_match('/(?:nhịp thở|thở)\s*(?:là|:)?\s*(\d{1,2})/u', $lower, $m)) {
            $resp = (int)$m[1];
        }

        // Bóc tách SpO2
        $spo2 = null;
        if (preg_match('/(?:spo2|sp\s*o2|oxy)\s*(?:là|:)?\s*(\d{2,3})/u', $lower, $m)) {
            $spo2 = (int)$m[1];
        }

        // Bóc tách Ăn uống
        $eating = 'Ăn hết suất';
        if (preg_match('/(không ăn|bỏ ăn|nhịn ăn)/u', $lower)) {
            $eating = 'Bỏ ăn / Không ăn';
        } elseif (preg_match('/(ăn ít|ăn 1\/2|nửa suất|ăn kém)/u', $lower)) {
            $eating = 'Ăn 1/2 suất';
        } elseif (preg_match('/(ăn hết|ăn tốt|ăn ngoan)/u', $lower)) {
            $eating = 'Ăn hết suất';
        }

        // Bóc tách Giấc ngủ
        $sleepHours = null;
        if (preg_match('/ngủ\s*(?:được\s*)?(\d+(?:[.,]\d+)?)\s*(?:tiếng|giờ)/u', $lower, $m)) {
            $sleepHours = (float)str_replace(',', '.', $m[1]);
        }
        $sleepQuality = 'Ngủ ngon';
        if (preg_match('/(mất ngủ|khó ngủ|chập chờn|trằn trọc)/u', $lower)) {
            $sleepQuality = 'Khó ngủ / Chập chờn';
        }

        // Đã uống thuốc?
        $medTaken = preg_match('/(chưa uống thuốc|từ chối thuốc)/u', $lower) ? 0 : 1;

        // Mức độ ưu tiên nếu là việc phát sinh
        $priority = 'thuong';
        if (preg_match('/(khẩn cấp|cấp cứu|nguy kịch|nguy hiểm)/u', $lower)) {
            $priority = 'khan_cap';
        } elseif (preg_match('/(gấp|nhanh|ngay)/u', $lower)) {
            $priority = 'gap';
        }

        return [
            'intent'        => $intent,
            'room_number'   => $roomNumber,
            'patient_hint'  => $patientHint,
            'bp_sys'        => $bpSys,
            'bp_dia'        => $bpDia,
            'pulse'         => $pulse,
            'temperature'   => $temp,
            'resp_rate'     => $resp,
            'spo2'          => $spo2,
            'eating_status' => $eating,
            'sleep_hours'   => $sleepHours,
            'sleep_quality' => $sleepQuality,
            'med_taken'     => $medTaken,
            'priority'      => $priority,
            'title'         => mb_substr($text, 0, 120, 'UTF-8'),
            'clinical_note' => $text,
        ];
    }

    private static function callGemini(string $text, array $cfg): ?array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($cfg['gemini_model']) . ':generateContent?key=' . urlencode($cfg['gemini_api_key']);
        $prompt = "Phân tích câu báo cáo y tế tiếng Việt sau thành JSON thuần (chỉ trả về JSON, không markdown) với các khóa: "
            . "intent ('vital'|'adhoc'|'chat'), room_number (1-7 hoặc null), patient_hint (string), bp_sys (int|null), bp_dia (int|null), "
            . "pulse (int|null), temperature (float|null), resp_rate (int|null), spo2 (int|null), eating_status (string), sleep_hours (float|null), "
            . "sleep_quality (string), med_taken (0 hoặc 1), priority ('thuong'|'gap'|'khan_cap'), title (string), clinical_note (string).\nCâu nói: " . $text;

        $body = json_encode(['contents' => [['parts' => [['text' => $prompt]]]]]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if (!$resp) {
            return null;
        }
        $data = json_decode($resp, true);
        $rawText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $rawText = preg_replace('/^```json\s*|\s*```$/m', '', trim($rawText));
        $json = json_decode($rawText, true);
        return is_array($json) ? $json : null;
    }

    private static function callOllama(string $text, array $cfg): ?array
    {
        $prompt = "Trích xuất câu y tế tiếng Việt sau thành JSON thuần với khóa: intent, room_number, patient_hint, bp_sys, bp_dia, pulse, temperature, resp_rate, spo2, eating_status, sleep_hours, sleep_quality, med_taken, priority, title, clinical_note. Văn bản: " . $text;
        $ch = curl_init($cfg['ollama_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode(['model' => $cfg['ollama_model'], 'prompt' => $prompt, 'format' => 'json', 'stream' => false]),
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if (!$resp) {
            return null;
        }
        $data = json_decode($resp, true);
        $json = json_decode((string)($data['response'] ?? ''), true);
        return is_array($json) ? $json : null;
    }

    private static function enrichWithDatabaseMatch(array $parsed, string $rawText, string $adapterUsed): array
    {
        $pdo = Database::conn();
        $roomId = null;
        if (!empty($parsed['room_number'])) {
            $st = $pdo->prepare('SELECT id FROM rooms WHERE sort_order = ? OR code = ? LIMIT 1');
            $st->execute([(int)$parsed['room_number'], 'P' . (int)$parsed['room_number']]);
            $roomId = $st->fetchColumn() ?: null;
        }

        $patientId = null;
        $patientLabel = '';
        $hint = trim((string)($parsed['patient_hint'] ?? ''));
        if ($hint !== '') {
            $sql = "SELECT id, patient_code, full_name, room_id FROM patients WHERE status = 'active' AND (patient_code LIKE ? OR full_name LIKE ?)";
            $params = ['%' . $hint . '%', '%' . $hint . '%'];
            if ($roomId) {
                $sql .= ' ORDER BY CASE WHEN room_id = ? THEN 0 ELSE 1 END, id LIMIT 1';
                $params[] = $roomId;
            } else {
                $sql .= ' LIMIT 1';
            }
            $st = $pdo->prepare($sql);
            $st->execute($params);
            if ($row = $st->fetch()) {
                $patientId = (int)$row['id'];
                $patientLabel = $row['patient_code'] . ' - ' . $row['full_name'];
                if (!$roomId) {
                    $roomId = (int)$row['room_id'];
                }
            }
        }

        $abnormal = ClinicalService::evaluateVitals($parsed);

        return array_merge($parsed, [
            'room_id'          => $roomId ? (int)$roomId : 1,
            'patient_id'       => $patientId,
            'patient_label'    => $patientLabel,
            'is_abnormal'      => $abnormal['is_abnormal'],
            'abnormal_reasons' => $abnormal['reasons'],
            'raw_voice_text'   => $rawText,
            'adapter_used'     => $adapterUsed,
        ]);
    }
}

// ============================================================================
// [PHẦN 3] MODULES - NGHIỆP VỤ LÂM SÀNG, 7 PHÒNG, BÁO CÁO & XUẤT ĐA ĐỊNH DẠNG
// ============================================================================
final class ClinicalService
{
    /**
     * Đánh giá ngưỡng an toàn sinh hiệu & huyết áp (1.5.1.1)
     */
    public static function evaluateVitals(array $v): array
    {
        $t = AppConfig::VITAL_THRESHOLDS;
        $reasons = [];

        if (!empty($v['pulse'])) {
            $p = (int)$v['pulse'];
            if ($p < $t['pulse_min']) $reasons[] = "Mạch chậm ({$p})";
            elseif ($p > $t['pulse_max']) $reasons[] = "Mạch nhanh ({$p})";
        }
        if (!empty($v['temperature'])) {
            $temp = (float)$v['temperature'];
            if ($temp < $t['temp_min']) $reasons[] = "Hạ thân nhiệt ({$temp}°C)";
            elseif ($temp > $t['temp_max']) $reasons[] = "Sốt ({$temp}°C)";
        }
        if (!empty($v['resp_rate'])) {
            $r = (int)$v['resp_rate'];
            if ($r < $t['resp_min'] || $r > $t['resp_max']) $reasons[] = "Nhịp thở bất thường ({$r})";
        }
        if (!empty($v['spo2'])) {
            $s = (int)$v['spo2'];
            if ($s < $t['spo2_min']) $reasons[] = "SpO2 thấp ({$s}%)";
        }
        if (!empty($v['bp_sys']) && !empty($v['bp_dia'])) {
            $sys = (int)$v['bp_sys'];
            $dia = (int)$v['bp_dia'];
            if ($sys > $t['sys_max'] || $dia > $t['dia_max']) {
                $reasons[] = "Huyết áp cao ({$sys}/{$dia})";
            } elseif ($sys < $t['sys_min'] || $dia < $t['dia_min']) {
                $reasons[] = "Huyết áp thấp ({$sys}/{$dia})";
            }
        }

        return [
            'is_abnormal' => count($reasons) > 0 ? 1 : 0,
            'reasons'     => implode('; ', $reasons),
        ];
    }

    /**
     * Tổng hợp trạng thái Real-time 7 Phòng (Xanh / Vàng / Đỏ)
     */
    public static function getDashboard7Rooms(): array
    {
        $pdo = Database::conn();
        $today = date('Y-m-d');
        $currentShift = Security::currentShift();

        $rooms = $pdo->query('SELECT * FROM rooms ORDER BY sort_order ASC')->fetchAll();

        // Đếm bệnh nhân đang điều trị theo phòng
        $patCounts = [];
        foreach ($pdo->query("SELECT room_id, COUNT(*) c FROM patients WHERE status='active' GROUP BY room_id") as $r) {
            $patCounts[(int)$r['room_id']] = (int)$r['c'];
        }

        // Đếm sinh hiệu bất thường trong 12 giờ gần nhất theo phòng
        $abnStmt = $pdo->query(
            "SELECT v.room_id, COUNT(DISTINCT v.patient_id) c,
                    GROUP_CONCAT(p.full_name || ' (' || v.abnormal_reasons || ')', ' | ') details
             FROM vital_logs v
             JOIN patients p ON p.id = v.patient_id
             WHERE v.is_abnormal = 1 AND v.recorded_at >= datetime('now','localtime','-12 hours')
             GROUP BY v.room_id"
        );
        $abnormalByRoom = [];
        foreach ($abnStmt as $r) {
            $abnormalByRoom[(int)$r['room_id']] = ['count' => (int)$r['c'], 'details' => (string)$r['details']];
        }

        // Đếm công việc phát sinh chưa hoàn thành theo phòng & mức ưu tiên
        $adhocStmt = $pdo->query(
            "SELECT room_id,
                    SUM(CASE WHEN priority='khan_cap' THEN 1 ELSE 0 END) urgent_cnt,
                    SUM(CASE WHEN priority='gap' THEN 1 ELSE 0 END) high_cnt,
                    COUNT(*) total_open
             FROM ad_hoc_tasks
             WHERE status != 'hoan_thanh'
             GROUP BY room_id"
        );
        $adhocByRoom = [];
        foreach ($adhocStmt as $r) {
            $adhocByRoom[(int)$r['room_id']] = [
                'urgent' => (int)$r['urgent_cnt'],
                'high'   => (int)$r['high_cnt'],
                'total'  => (int)$r['total_open'],
            ];
        }

        // Tiến độ công việc hàng ngày ca hiện tại theo phòng
        $tplCountStmt = $pdo->prepare('SELECT room_id, COUNT(*) c FROM daily_task_templates WHERE is_active=1 AND shift_code=? GROUP BY room_id');
        $tplCountStmt->execute([$currentShift]);
        $globalTplCount = 0;
        $roomTplCount = [];
        foreach ($tplCountStmt as $r) {
            if ($r['room_id'] === null) $globalTplCount = (int)$r['c'];
            else $roomTplCount[(int)$r['room_id']] = (int)$r['c'];
        }

        $doneStmt = $pdo->prepare("SELECT room_id, COUNT(*) c FROM daily_task_logs WHERE task_date=? AND shift_code=? AND status='done' GROUP BY room_id");
        $doneStmt->execute([$today, $currentShift]);
        $doneByRoom = [];
        foreach ($doneStmt as $r) {
            $doneByRoom[(int)$r['room_id']] = (int)$r['c'];
        }

        $cards = [];
        $totalActivePatients = 0;
        $totalRed = 0;
        $totalYellow = 0;
        $totalOpenAdhoc = 0;

        foreach ($rooms as $room) {
            $rid = (int)$room['id'];
            $pCount = $patCounts[$rid] ?? 0;
            $totalActivePatients += $pCount;

            $abn = $abnormalByRoom[$rid] ?? ['count' => 0, 'details' => ''];
            $ah = $adhocByRoom[$rid] ?? ['urgent' => 0, 'high' => 0, 'total' => 0];
            $totalOpenAdhoc += $ah['total'];

            $tasksTotal = $globalTplCount + ($roomTplCount[$rid] ?? 0);
            $tasksDone = $doneByRoom[$rid] ?? 0;
            $tasksPending = max(0, $tasksTotal - $tasksDone);

            // Quy tắc mã màu trạng thái: Đỏ > Vàng > Xanh
            $status = 'green';
            $statusLabel = 'Ổn định';
            $alertSummary = 'Các chỉ số sinh hiệu và lịch trực ổn định';

            if ($abn['count'] > 0 || $ah['urgent'] > 0) {
                $status = 'red';
                $statusLabel = 'Cảnh báo khẩn';
                $totalRed++;
                $parts = [];
                if ($abn['count'] > 0) $parts[] = "{$abn['count']} BN sinh hiệu bất thường";
                if ($ah['urgent'] > 0) $parts[] = "{$ah['urgent']} việc KHẨN CẤP";
                $alertSummary = implode(' • ', $parts);
            } elseif ($ah['high'] > 0 || $ah['total'] > 0 || $tasksPending > 0) {
                $status = 'yellow';
                $statusLabel = 'Cần chú ý';
                $totalYellow++;
                $parts = [];
                if ($ah['total'] > 0) $parts[] = "{$ah['total']} việc phát sinh";
                if ($tasksPending > 0) $parts[] = "Còn {$tasksPending}/{$tasksTotal} việc ca trực";
                $alertSummary = implode(' • ', $parts);
            }

            $cards[] = [
                'id'               => $rid,
                'code'             => $room['code'],
                'name'             => $room['name'],
                'capacity'         => (int)$room['capacity'],
                'patient_count'    => $pCount,
                'status'           => $status,
                'status_label'     => $statusLabel,
                'alert_summary'    => $alertSummary,
                'abnormal_count'   => $abn['count'],
                'abnormal_details' => $abn['details'],
                'adhoc_open'       => $ah['total'],
                'adhoc_urgent'     => $ah['urgent'],
                'tasks_done'       => $tasksDone,
                'tasks_total'      => $tasksTotal,
            ];
        }

        // Lấy danh sách cảnh báo đỏ gần nhất để hiển thị thanh thông báo
        $alerts = $pdo->query(
            "SELECT v.id, v.recorded_at, v.abnormal_reasons, p.patient_code, p.full_name, r.code AS room_code, r.name AS room_name
             FROM vital_logs v
             JOIN patients p ON p.id = v.patient_id
             JOIN rooms r ON r.id = v.room_id
             WHERE v.is_abnormal = 1 AND v.recorded_at >= datetime('now','localtime','-24 hours')
             ORDER BY v.recorded_at DESC LIMIT 8"
        )->fetchAll();

        // Sinh hiệu mới ghi nhận gần nhất (hiển thị Real-time ngay khi đăng nhập)
        $recentVitals = $pdo->query(
            "SELECT v.id, v.pulse, v.temperature, v.spo2, v.bp_sys, v.bp_dia,
                    v.is_abnormal, v.abnormal_reasons, v.recorded_at,
                    p.patient_code, p.full_name, r.code AS room_code,
                    COALESCE(u.full_name, 'Hệ thống') AS recorder_name
             FROM vital_logs v
             JOIN patients p ON p.id = v.patient_id
             JOIN rooms r ON r.id = v.room_id
             LEFT JOIN users u ON u.id = v.recorded_by
             ORDER BY v.recorded_at DESC, v.id DESC LIMIT 6"
        )->fetchAll();

        // Việc phát sinh đang mở / mới nhất
        $recentAdhoc = $pdo->query(
            "SELECT t.id, t.title, t.priority, t.status, t.created_at,
                    r.code AS room_code,
                    COALESCE(p.full_name, '') AS patient_name,
                    COALESCE(u.full_name, 'Chưa gán') AS assignee_name
             FROM ad_hoc_tasks t
             JOIN rooms r ON r.id = t.room_id
             LEFT JOIN patients p ON p.id = t.patient_id
             LEFT JOIN users u ON u.id = t.assigned_to
             WHERE t.status != 'hoan_thanh'
             ORDER BY CASE t.priority WHEN 'khan_cap' THEN 1 WHEN 'gap' THEN 2 ELSE 3 END, t.id DESC
             LIMIT 6"
        )->fetchAll();

        // Nhật ký hoạt động hệ thống gần nhất
        $recentLogs = $pdo->query(
            "SELECT a.action, a.table_name, a.created_at,
                    COALESCE(u.full_name, 'Hệ thống') AS actor_name
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC LIMIT 5"
        )->fetchAll();

        $activeStaffCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn();

        return [
            'server_time'        => date('H:i:s d/m/Y'),
            'current_shift'      => $currentShift,
            'shift_label'        => AppConfig::SHIFTS[$currentShift]['label'] ?? 'Ca Trực',
            'shift_time'         => AppConfig::SHIFTS[$currentShift]['time'] ?? '',
            'active_staff_count' => $activeStaffCount,
            'summary'            => [
                'active_patients' => $totalActivePatients,
                'rooms_red'       => $totalRed,
                'rooms_yellow'    => $totalYellow,
                'rooms_green'     => count($cards) - $totalRed - $totalYellow,
                'open_adhoc'      => $totalOpenAdhoc,
            ],
            'rooms'              => $cards,
            'recent_alerts'      => $alerts,
            'recent_vitals'      => $recentVitals,
            'recent_adhoc'       => $recentAdhoc,
            'recent_logs'        => $recentLogs,
        ];
    }

    /**
     * Tính khoảng thời gian cho bộ lọc Ngày / Tuần / Tháng / Quý / Năm (1.4.1 & 1.6.1.2)
     */
    public static function dateRange(string $period, ?string $refDate = null): array
    {
        $ts = $refDate ? strtotime($refDate) : time();
        if (!$ts) $ts = time();

        $y = (int)date('Y', $ts);
        $m = (int)date('n', $ts);

        switch ($period) {
            case 'week':
                $start = date('Y-m-d', strtotime('monday this week', $ts));
                $end   = date('Y-m-d', strtotime('sunday this week', $ts));
                $label = "Tuần {$start} đến {$end}";
                break;
            case 'month':
                $start = date('Y-m-01', $ts);
                $end   = date('Y-m-t', $ts);
                $label = 'Tháng ' . date('m/Y', $ts);
                break;
            case 'quarter':
                $q = (int)ceil($m / 3);
                $sm = ($q - 1) * 3 + 1;
                $em = $sm + 2;
                $start = sprintf('%04d-%02d-01', $y, $sm);
                $end   = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $em)));
                $label = "Quý {$q}/{$y}";
                break;
            case 'year':
                $start = sprintf('%04d-01-01', $y);
                $end   = sprintf('%04d-12-31', $y);
                $label = "Năm {$y}";
                break;
            case 'day':
            default:
                $start = date('Y-m-d', $ts);
                $end   = $start;
                $label = 'Ngày ' . date('d/m/Y', $ts);
                break;
        }
        return ['start' => $start . ' 00:00:00', 'end' => $end . ' 23:59:59', 'start_date' => $start, 'end_date' => $end, 'label' => $label];
    }

    /**
     * Báo cáo tổng hợp & Báo cáo công việc phát sinh (1.4.1 & 1.4.1.1)
     */
    public static function getComprehensiveReport(string $period, ?string $refDate = null): array
    {
        $pdo = Database::conn();
        $range = self::dateRange($period, $refDate);

        // 1. Thống kê tổng quan công việc phát sinh
        $st = $pdo->prepare(
            "SELECT COUNT(*) total,
                    SUM(CASE WHEN status='hoan_thanh' THEN 1 ELSE 0 END) done_cnt,
                    SUM(CASE WHEN priority='khan_cap' THEN 1 ELSE 0 END) urgent_cnt,
                    SUM(CASE WHEN status='hoan_thanh' AND (due_at IS NULL OR completed_at <= due_at) THEN 1 ELSE 0 END) ontime_cnt
             FROM ad_hoc_tasks WHERE created_at BETWEEN ? AND ?"
        );
        $st->execute([$range['start'], $range['end']]);
        $adhocStats = $st->fetch() ?: ['total' => 0, 'done_cnt' => 0, 'urgent_cnt' => 0, 'ontime_cnt' => 0];

        // 2. Tần suất sự cố theo phòng (1.4.1.1)
        $stRoom = $pdo->prepare(
            "SELECT r.code, r.name,
                    COUNT(a.id) total,
                    SUM(CASE WHEN a.status='hoan_thanh' THEN 1 ELSE 0 END) done_cnt,
                    SUM(CASE WHEN a.priority='khan_cap' THEN 1 ELSE 0 END) urgent_cnt
             FROM rooms r
             LEFT JOIN ad_hoc_tasks a ON a.room_id = r.id AND a.created_at BETWEEN ? AND ?
             GROUP BY r.id ORDER BY r.sort_order"
        );
        $stRoom->execute([$range['start'], $range['end']]);
        $byRoom = $stRoom->fetchAll();

        // 3. Tần suất sự cố theo ca trực (1.4.1.1)
        $stShift = $pdo->prepare(
            "SELECT shift_code, COUNT(*) total,
                    SUM(CASE WHEN status='hoan_thanh' THEN 1 ELSE 0 END) done_cnt
             FROM ad_hoc_tasks WHERE created_at BETWEEN ? AND ? GROUP BY shift_code"
        );
        $stShift->execute([$range['start'], $range['end']]);
        $byShift = $stShift->fetchAll();

        // 4. Nguyên nhân tồn đọng công việc phát sinh
        $stBacklog = $pdo->prepare(
            "SELECT a.id, a.title, a.priority, a.status, a.backlog_reason, a.created_at, r.code AS room_code, u.full_name AS assignee_name
             FROM ad_hoc_tasks a
             JOIN rooms r ON r.id = a.room_id
             LEFT JOIN users u ON u.id = a.assigned_to
             WHERE a.created_at BETWEEN ? AND ? AND (a.status != 'hoan_thanh' OR a.backlog_reason != '')
             ORDER BY CASE a.priority WHEN 'khan_cap' THEN 1 WHEN 'gap' THEN 2 ELSE 3 END, a.created_at DESC
             LIMIT 30"
        );
        $stBacklog->execute([$range['start'], $range['end']]);
        $backlogs = $stBacklog->fetchAll();

        // 5. Thống kê sinh hiệu & công việc hàng ngày trong kỳ
        $stV = $pdo->prepare("SELECT COUNT(*) total, SUM(is_abnormal) abnormal_cnt FROM vital_logs WHERE recorded_at BETWEEN ? AND ?");
        $stV->execute([$range['start'], $range['end']]);
        $vitalStats = $stV->fetch() ?: ['total' => 0, 'abnormal_cnt' => 0];

        $stD = $pdo->prepare("SELECT COUNT(*) total, SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) done_cnt FROM daily_task_logs WHERE task_date BETWEEN ? AND ?");
        $stD->execute([$range['start_date'], $range['end_date']]);
        $dailyStats = $stD->fetch() ?: ['total' => 0, 'done_cnt' => 0];

        $totalAh = (int)$adhocStats['total'];
        $doneAh = (int)$adhocStats['done_cnt'];
        $ontimeRate = $doneAh > 0 ? round(((int)$adhocStats['ontime_cnt'] / $doneAh) * 100, 1) : 100.0;

        return [
            'range'        => $range,
            'adhoc_stats'  => [
                'total'       => $totalAh,
                'done'        => $doneAh,
                'urgent'      => (int)$adhocStats['urgent_cnt'],
                'ontime_rate' => $ontimeRate,
            ],
            'vital_stats'  => [
                'total'    => (int)$vitalStats['total'],
                'abnormal' => (int)$vitalStats['abnormal_cnt'],
            ],
            'daily_stats'  => [
                'total' => (int)$dailyStats['total'],
                'done'  => (int)$dailyStats['done_cnt'],
            ],
            'by_room'      => $byRoom,
            'by_shift'     => $byShift,
            'backlogs'     => $backlogs,
        ];
    }

    /**
     * Tự động tổng hợp dữ liệu cho Bàn giao ca Sáng / Tối (1.5.2.1)
     */
    public static function buildHandoverSnapshot(): array
    {
        $pdo = Database::conn();
        $abnormalPatients = $pdo->query(
            "SELECT p.patient_code, p.full_name, r.code AS room_code, v.bp_sys, v.bp_dia, v.pulse, v.temperature, v.spo2, v.abnormal_reasons, v.recorded_at
             FROM vital_logs v
             JOIN patients p ON p.id = v.patient_id
             JOIN rooms r ON r.id = v.room_id
             WHERE v.is_abnormal = 1 AND v.recorded_at >= datetime('now','localtime','-12 hours')
             ORDER BY v.recorded_at DESC"
        )->fetchAll();

        $specialCare = $pdo->query(
            "SELECT p.patient_code, p.full_name, r.code AS room_code, p.bed_number, p.special_care
             FROM patients p JOIN rooms r ON r.id = p.room_id
             WHERE p.status = 'active' AND TRIM(p.special_care) != ''
             ORDER BY r.sort_order, p.bed_number LIMIT 50"
        )->fetchAll();

        $openAdhoc = $pdo->query(
            "SELECT a.id, a.title, a.priority, a.status, r.code AS room_code, u.full_name AS assignee, a.created_at
             FROM ad_hoc_tasks a
             JOIN rooms r ON r.id = a.room_id
             LEFT JOIN users u ON u.id = a.assigned_to
             WHERE a.status != 'hoan_thanh'
             ORDER BY CASE a.priority WHEN 'khan_cap' THEN 1 WHEN 'gap' THEN 2 ELSE 3 END, a.created_at DESC"
        )->fetchAll();

        $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM patients WHERE status='active'")->fetchColumn();

        return [
            'generated_at'      => date('Y-m-d H:i:s'),
            'active_patients'   => $activeCount,
            'abnormal_patients' => $abnormalPatients,
            'special_care'      => $specialCare,
            'open_adhoc'        => $openAdhoc,
        ];
    }
}

/**
 * ============================================================================
 * BỘ XUẤT FILE ĐA ĐỊNH DẠNG (1.5.2.2): EXCEL (.xls/.csv), WORD (.doc), PDF, WEB
 * ============================================================================
 */
final class ExportService
{
    public static function handleExport(array $user): void
    {
        $type   = (string)($_GET['type'] ?? 'patients'); // patients | handover | report
        $format = (string)($_GET['format'] ?? 'web');    // excel | csv | word | pdf | web
        $id     = (int)($_GET['id'] ?? 0);
        $period = (string)($_GET['period'] ?? 'month');
        $refDate = $_GET['date'] ?? date('Y-m-d');

        $pdo = Database::conn();
        $title = '';
        $headers = [];
        $rows = [];
        $metaLines = [
            'Đơn vị: Khu Điều trị Nội trú 24/24 (7 Phòng chức năng)',
            'Người xuất: ' . $user['full_name'] . ' — Thời gian: ' . date('H:i d/m/Y'),
        ];

        if ($type === 'patients') {
            $title = 'DANH SÁCH HỒ SƠ BỆNH NHÂN NỘI TRÚ';
            $status = (string)($_GET['status'] ?? 'active');
            $sql = "SELECT p.patient_code, p.full_name, p.birth_year, p.gender, r.code AS room_code, p.bed_number,
                           p.medical_history, p.special_care, p.family_contact, p.status, p.admitted_at
                    FROM patients p JOIN rooms r ON r.id = p.room_id";
            $params = [];
            if ($status !== 'all') {
                $sql .= ' WHERE p.status = ?';
                $params[] = $status;
            }
            $sql .= ' ORDER BY r.sort_order, p.bed_number, p.id';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $headers = ['Mã BN', 'Họ và Tên', 'Năm sinh', 'Giới tính', 'Phòng', 'Giường', 'Tiền sử bệnh', 'Chăm sóc đặc biệt', 'Người nhà', 'Trạng thái', 'Ngày nhập'];
            foreach ($st->fetchAll() as $r) {
                $rows[] = array_values($r);
            }
        } elseif ($type === 'handover') {
            $st = $pdo->prepare(
                "SELECT h.*, u1.full_name AS from_name, u2.full_name AS to_name
                 FROM shift_handovers h
                 JOIN users u1 ON u1.id = h.from_user_id
                 LEFT JOIN users u2 ON u2.id = h.to_user_id
                 WHERE h.id = ?"
            );
            $st->execute([$id]);
            $h = $st->fetch();
            if (!$h) {
                http_response_code(404);
                exit('Không tìm thấy biên bản bàn giao ca.');
            }
            $snap = json_decode($h['summary_json'], true) ?: [];
            $shiftName = $h['shift_code'] === 'sang' ? 'CA SÁNG' : 'CA TỐI';
            $title = "BIÊN BẢN BÀN GIAO {$shiftName} — NGÀY " . date('d/m/Y', strtotime($h['handover_date']));
            $metaLines[] = 'Người giao ca: ' . $h['from_name'] . ' (' . $h['handed_over_at'] . ')';
            $metaLines[] = 'Người nhận ca: ' . ($h['to_name'] ?: 'Chưa xác nhận') . ($h['accepted_at'] ? ' (' . $h['accepted_at'] . ')' : '');
            $metaLines[] = 'Trạng thái: ' . ($h['is_locked'] ? 'ĐÃ CHỐT & KHÓA CHỈNH SỬA' : 'CHỜ XÁC NHẬN') . ' | Tổng BN hiện diện: ' . ($snap['active_patients'] ?? 0);
            if (!empty($h['notes'])) {
                $metaLines[] = 'Ghi chú bàn giao: ' . $h['notes'];
            }

            $headers = ['Phân mục', 'Phòng', 'Đối tượng / Đầu việc', 'Chi tiết / Chỉ số', 'Mức độ / Thời gian'];
            foreach ($snap['abnormal_patients'] ?? [] as $ab) {
                $rows[] = ['Sinh hiệu bất thường', $ab['room_code'], $ab['patient_code'] . ' - ' . $ab['full_name'], $ab['abnormal_reasons'], $ab['recorded_at']];
            }
            foreach ($snap['open_adhoc'] ?? [] as $ah) {
                $rows[] = ['Việc phát sinh tồn', $ah['room_code'], $ah['title'], 'Phụ trách: ' . ($ah['assignee'] ?: 'Chưa gán') . ' (' . $ah['status'] . ')', strtoupper($ah['priority'])];
            }
            foreach ($snap['special_care'] ?? [] as $sc) {
                $rows[] = ['Chăm sóc đặc biệt', $sc['room_code'], $sc['patient_code'] . ' - ' . $sc['full_name'] . ' (' . $sc['bed_number'] . ')', $sc['special_care'], 'Theo dõi 24/24'];
            }
        } else {
            $rep = ClinicalService::getComprehensiveReport($period, $refDate);
            $title = 'BÁO CÁO TỔNG HỢP & CÔNG VIỆC PHÁT SINH — ' . mb_strtoupper($rep['range']['label'], 'UTF-8');
            $metaLines[] = sprintf(
                'Tổng việc phát sinh: %d | Đã hoàn thành: %d | Khẩn cấp: %d | Tỷ lệ đúng hạn: %s%% | Lượt đo sinh hiệu: %d (Bất thường: %d)',
                $rep['adhoc_stats']['total'],
                $rep['adhoc_stats']['done'],
                $rep['adhoc_stats']['urgent'],
                $rep['adhoc_stats']['ontime_rate'],
                $rep['vital_stats']['total'],
                $rep['vital_stats']['abnormal']
            );
            $headers = ['Mã phòng', 'Tên phòng chức năng', 'Tổng việc phát sinh', 'Đã hoàn thành', 'Số ca khẩn cấp'];
            foreach ($rep['by_room'] as $rm) {
                $rows[] = [$rm['code'], $rm['name'], $rm['total'], $rm['done_cnt'], $rm['urgent_cnt']];
            }
        }

        AuditLogger::log((int)$user['id'], 'EXPORT_' . strtoupper($format), $type, $id ?: null, null, ['period' => $period]);
        $filenameBase = $type . '_' . date('Ymd_His');

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
            echo "\xEF\xBB\xBF"; // UTF-8 BOM cho Excel hiển thị tiếng Việt chuẩn
            $out = fopen('php://output', 'wb');
            fputcsv($out, [$title]);
            foreach ($metaLines as $ml) fputcsv($out, [$ml]);
            fputcsv($out, []);
            fputcsv($out, $headers);
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
            exit;
        }

        if ($format === 'excel') {
            // Xuất Excel chuẩn UTF-8 (.xls HTML-table tương thích 100% Microsoft Excel & LibreOffice)
            header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filenameBase . '.xls"');
            echo "\xEF\xBB\xBF";
            echo self::renderPrintableHtml($title, $metaLines, $headers, $rows, false);
            exit;
        }

        if ($format === 'word') {
            header('Content-Type: application/msword; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filenameBase . '.doc"');
            echo "\xEF\xBB\xBF";
            echo self::renderPrintableHtml($title, $metaLines, $headers, $rows, false, true);
            exit;
        }

        // PDF (Khổ A4/A5 tự mở hộp thoại in -> Lưu PDF) hoặc Bản xem Web
        header('Content-Type: text/html; charset=UTF-8');
        echo self::renderPrintableHtml($title, $metaLines, $headers, $rows, $format === 'pdf', true);
        exit;
    }

    private static function renderPrintableHtml(string $title, array $metaLines, array $headers, array $rows, bool $autoPrint = false, bool $withSignatures = false): string
    {
        $e = static fn(mixed $v): string => Security::e($v);
        $metaHtml = '';
        foreach ($metaLines as $m) {
            $metaHtml .= '<p style="margin:3px 0;font-size:13px;color:#334155;">' . $e($m) . '</p>';
        }
        $thHtml = '';
        foreach ($headers as $h) {
            $thHtml .= '<th>' . $e($h) . '</th>';
        }
        $trHtml = '';
        if (empty($rows)) {
            $trHtml = '<tr><td colspan="' . count($headers) . '" style="text-align:center;color:#64748b;">Không có dữ liệu trong kỳ báo cáo.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $trHtml .= '<tr>';
                foreach ($row as $cell) {
                    $trHtml .= '<td>' . $e($cell) . '</td>';
                }
                $trHtml .= '</tr>';
            }
        }
        $signBlock = $withSignatures ? <<<HTML
        <div style="display:flex;justify-content:space-between;margin-top:36px;text-align:center;font-size:13px;">
            <div style="width:40%;"><strong>NGƯỜI LẬP / GIAO CA</strong><br><em style="color:#64748b;">(Ký, ghi rõ họ tên)</em><br><br><br><br></div>
            <div style="width:40%;"><strong>ĐIỀU DƯỠNG TRƯỞNG / NGƯỜI NHẬN</strong><br><em style="color:#64748b;">(Ký, ghi rõ họ tên)</em><br><br><br><br></div>
        </div>
HTML : '';

        $printBtn = $autoPrint
            ? '<div class="no-print" style="margin-bottom:16px;display:flex;gap:8px;"><button onclick="window.print()" style="padding:8px 16px;background:#0f766e;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">In / Lưu file PDF (A4)</button><button onclick="window.close()" style="padding:8px 16px;background:#e2e8f0;border:none;border-radius:6px;cursor:pointer;">Đóng</button></div><script>window.onload=function(){setTimeout(function(){window.print();},350);};</script>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>{$e($title)}</title>
<style>
  @page { size: A4; margin: 14mm; }
  body { font-family: "Segoe UI", Arial, sans-serif; color: #0f172a; margin: 20px; line-height: 1.45; }
  h1 { font-size: 18px; color: #0f766e; margin: 0 0 8px 0; text-transform: uppercase; }
  table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 12.5px; }
  th, td { border: 1px solid #cbd5e1; padding: 7px 8px; text-align: left; vertical-align: top; }
  th { background: #f1f5f9; font-weight: 700; color: #0f172a; }
  @media print { .no-print { display: none !important; } body { margin: 0; } }
</style>
</head>
<body>
  {$printBtn}
  <h1>{$e($title)}</h1>
  {$metaHtml}
  <table>
    <thead><tr>{$thHtml}</tr></thead>
    <tbody>{$trHtml}</tbody>
  </table>
  {$signBlock}
</body>
</html>
HTML;
    }
}

// ============================================================================
// [PHẦN 4] ROUTER, REST API JSON, REAL-TIME SSE & PWA ASSETS
// ============================================================================
Security::startSession();
$pdo = Database::conn();
BackupManager::runAutoBackupIfNeeded();

$action = (string)($_GET['action'] ?? '');

// 1. PWA Manifest động (cho phép cài app ra màn hình chính điện thoại)
if ($action === 'manifest') {
    header('Content-Type: application/manifest+json; charset=UTF-8');
    $svgIcon = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 192 192"><rect width="192" height="192" rx="40" fill="#0f766e"/><path d="M84 44h24v40h40v24h-40v40H84v-40H44V84h40z" fill="#ffffff"/></svg>');
    echo json_encode([
        'name'             => AppConfig::APP_NAME,
        'short_name'       => AppConfig::APP_SHORT_NAME,
        'start_url'        => basename(__FILE__),
        'display'          => 'standalone',
        'background_color' => '#0b1120',
        'theme_color'      => '#0f766e',
        'icons'            => [
            ['src' => $svgIcon, 'sizes' => '192x192', 'type' => 'image/svg+xml', 'purpose' => 'any maskable'],
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Service Worker gọn nhẹ cho PWA
if ($action === 'sw') {
    header('Content-Type: application/javascript; charset=UTF-8');
    echo "self.addEventListener('install', e => self.skipWaiting());\n";
    echo "self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));\n";
    echo "self.addEventListener('fetch', e => { if (e.request.method === 'GET') { e.respondWith(fetch(e.request).catch(() => caches.match(e.request))); } });\n";
    exit;
}

// Kiểm tra đăng nhập hiện tại
function currentUser(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $st = Database::conn()->prepare('SELECT id, username, full_name, role, shift_group, phone FROM users WHERE id = ? AND is_active = 1');
    $st->execute([(int)$_SESSION['user_id']]);
    $cached = $st->fetch() ?: null;
    return $cached;
}

function jsonOut(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Xử lý Đăng nhập / Đăng xuất
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        jsonOut(['ok' => false, 'error' => 'Phiên làm việc không hợp lệ (CSRF). Vui lòng tải lại trang.'], 403);
    }
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $st = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $st->execute([$username]);
    $u = $st->fetch();

    if (!$u || !password_verify($password, $u['password_hash'])) {
        AuditLogger::log(null, 'LOGIN_FAILED', 'users', null, null, ['username' => $username]);
        jsonOut(['ok' => false, 'error' => 'Sai tên đăng nhập hoặc mật khẩu.'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    AuditLogger::log((int)$u['id'], 'LOGIN_SUCCESS', 'users', (int)$u['id']);
    jsonOut(['ok' => true]);
}

if ($action === 'logout') {
    $u = currentUser();
    if ($u) {
        AuditLogger::log((int)$u['id'], 'LOGOUT', 'users', (int)$u['id']);
    }
    session_destroy();
    header('Location: ' . basename(__FILE__));
    exit;
}

// 4. Server-Sent Events (SSE) cho Dashboard Real-time 24/24
if ($action === 'sse') {
    $user = currentUser();
    if (!$user) {
        http_response_code(401);
        exit;
    }
    // Giải phóng khóa session ngay lập tức để không chặn các request API khác
    session_write_close();
    header('Content-Type: text/event-stream; charset=UTF-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    // Tự thích ứng: nếu chạy bằng PHP built-in server đơn luồng thì gửi 1 gói rồi đóng để Smart Polling đảm nhiệm
    $loops = (PHP_SAPI === 'cli-server') ? 1 : 6;
    for ($i = 0; $i < $loops; $i++) {
        $payload = ClinicalService::getDashboard7Rooms();
        echo "event: dashboard\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level() > 0) @ob_flush();
        @flush();
        if (connection_aborted() || $i === $loops - 1) break;
        sleep(4);
    }
    exit;
}

// 5. Xuất file đa định dạng (1.5.2.2)
if ($action === 'export') {
    $user = currentUser();
    if (!$user) {
        http_response_code(401);
        exit('Vui lòng đăng nhập.');
    }
    ExportService::handleExport($user);
}

// 6. Tải xuống Backup / Archive (1.6.1.3)
if ($action === 'download_backup') {
    $user = currentUser();
    if (!$user || !Security::hasRole($user, ['admin', 'truong_dieu_duong'])) {
        http_response_code(403);
        exit('Không có quyền tải bản sao lưu.');
    }
    $folder = ($_GET['kind'] ?? 'backup') === 'archive' ? AppConfig::archiveDir() : AppConfig::backupDir();
    $file = basename((string)($_GET['file'] ?? ''));
    $full = $folder . DIRECTORY_SEPARATOR . $file;
    if ($file === '' || !is_file($full)) {
        http_response_code(404);
        exit('Không tìm thấy tập tin.');
    }
    AuditLogger::log((int)$user['id'], 'DOWNLOAD_FILE', 'system_backup', null, null, ['file' => $file]);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . (string)filesize($full));
    readfile($full);
    exit;
}

// 7. REST API JSON cho toàn bộ các module
if ($action === 'api') {
    $user = currentUser();
    if (!$user) {
        jsonOut(['ok' => false, 'error' => 'Phiên đăng nhập đã hết hạn.'], 401);
    }

    $op = (string)($_GET['op'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'];

    // Đọc JSON body nếu là POST
    $input = [];
    if ($method === 'POST') {
        $rawBody = file_get_contents('php://input');
        $decoded = json_decode((string)$rawBody, true);
        $input = is_array($decoded) ? $decoded : $_POST;
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
        if (!Security::verifyCsrf((string)$csrf)) {
            jsonOut(['ok' => false, 'error' => 'Mã bảo mật CSRF không khớp.'], 403);
        }
    }

    try {
        switch ($op) {
            // --- DASHBOARD 7 PHÒNG ---
            case 'dashboard':
                jsonOut(['ok' => true, 'data' => ClinicalService::getDashboard7Rooms()]);

            // --- DỮ LIỆU DANH MỤC NỀN (Phòng, Nhân sự, AI Config) ---
            case 'bootstrap':
                $rooms = $pdo->query('SELECT * FROM rooms ORDER BY sort_order')->fetchAll();
                $staff = $pdo->query('SELECT id, username, full_name, role, shift_group FROM users WHERE is_active=1 ORDER BY role, full_name')->fetchAll();
                $aiCfg = AiVoiceAdapter::getConfig();
                if (!Security::hasRole($user, ['admin'])) {
                    $aiCfg['gemini_api_key'] = $aiCfg['gemini_api_key'] ? '********' : '';
                }
                jsonOut([
                    'ok' => true,
                    'data' => [
                        'user'       => $user,
                        'rooms'      => $rooms,
                        'staff'      => $staff,
                        'shifts'     => AppConfig::SHIFTS,
                        'thresholds' => AppConfig::VITAL_THRESHOLDS,
                        'ai_config'  => $aiCfg,
                        'is_cli_srv' => PHP_SAPI === 'cli-server',
                    ],
                ]);

            // --- VOICE-TO-ACTION: BÓC TÁCH GIỌNG NÓI ---
            case 'voice_parse':
                $text = trim((string)($input['text'] ?? ''));
                if ($text === '') jsonOut(['ok' => false, 'error' => 'Chưa có nội dung giọng nói.'], 422);
                jsonOut(['ok' => true, 'data' => AiVoiceAdapter::parseVietnameseVoice($text)]);

            // --- MODULE 1.5.1: QUẢN LÝ BỆNH NHÂN + KHO LƯU TRỮ FTS5 ---
            case 'patients_list':
                $q = trim((string)($_GET['q'] ?? ''));
                $roomId = (int)($_GET['room_id'] ?? 0);
                $status = (string)($_GET['status'] ?? 'active');

                $where = [];
                $params = [];
                if ($status !== 'all') {
                    $where[] = 'p.status = ?';
                    $params[] = $status;
                }
                if ($roomId > 0) {
                    $where[] = 'p.room_id = ?';
                    $params[] = $roomId;
                }

                if ($q !== '') {
                    // Sử dụng FTS5 nếu từ khóa sạch, fallback LIKE để hỗ trợ mọi ký tự đặc biệt
                    $ftsTerm = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q);
                    $ftsTerm = trim((string)$ftsTerm);
                    if ($ftsTerm !== '') {
                        $tokens = array_filter(explode(' ', $ftsTerm));
                        $matchExpr = implode(' AND ', array_map(static fn($t) => '"' . $t . '"*', $tokens));
                        $where[] = '(p.id IN (SELECT rowid FROM patients_fts WHERE patients_fts MATCH ?) OR p.patient_code LIKE ? OR p.full_name LIKE ?)';
                        $params[] = $matchExpr;
                        $params[] = '%' . $q . '%';
                        $params[] = '%' . $q . '%';
                    }
                }

                $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
                $sql = "SELECT p.*, r.code AS room_code, r.name AS room_name,
                               (SELECT v.is_abnormal FROM vital_logs v WHERE v.patient_id = p.id ORDER BY v.recorded_at DESC LIMIT 1) AS latest_abnormal,
                               (SELECT v.bp_sys || '/' || v.bp_dia FROM vital_logs v WHERE v.patient_id = p.id AND v.bp_sys IS NOT NULL ORDER BY v.recorded_at DESC LIMIT 1) AS latest_bp,
                               (SELECT v.recorded_at FROM vital_logs v WHERE v.patient_id = p.id ORDER BY v.recorded_at DESC LIMIT 1) AS latest_vital_at
                        FROM patients p
                        JOIN rooms r ON r.id = p.room_id
                        {$whereSql}
                        ORDER BY r.sort_order ASC, p.bed_number ASC, p.id DESC LIMIT 250";
                $st = $pdo->prepare($sql);
                $st->execute($params);
                jsonOut(['ok' => true, 'data' => $st->fetchAll()]);

            case 'patient_save':
                $id = (int)($input['id'] ?? 0);
                $code = strtoupper(trim((string)($input['patient_code'] ?? '')));
                $name = trim((string)($input['full_name'] ?? ''));
                $roomId = (int)($input['room_id'] ?? 1);
                if ($code === '' || $name === '') {
                    jsonOut(['ok' => false, 'error' => 'Mã bệnh nhân và Họ tên là bắt buộc.'], 422);
                }
                $fields = [
                    'patient_code'    => $code,
                    'full_name'       => $name,
                    'birth_year'      => !empty($input['birth_year']) ? (int)$input['birth_year'] : null,
                    'gender'          => (string)($input['gender'] ?? 'Nam'),
                    'room_id'         => $roomId,
                    'bed_number'      => trim((string)($input['bed_number'] ?? '')),
                    'medical_history' => trim((string)($input['medical_history'] ?? '')),
                    'special_care'    => trim((string)($input['special_care'] ?? '')),
                    'family_contact'  => trim((string)($input['family_contact'] ?? '')),
                    'status'          => in_array($input['status'] ?? 'active', ['active', 'discharged', 'archived'], true) ? $input['status'] : 'active',
                ];

                if ($id > 0) {
                    $old = $pdo->prepare('SELECT * FROM patients WHERE id = ?');
                    $old->execute([$id]);
                    $oldRow = $old->fetch();
                    if (!$oldRow) jsonOut(['ok' => false, 'error' => 'Không tìm thấy bệnh nhân.'], 404);

                    $dischargedAt = ($fields['status'] !== 'active' && empty($oldRow['discharged_at'])) ? date('Y-m-d') : $oldRow['discharged_at'];
                    $st = $pdo->prepare(
                        "UPDATE patients SET patient_code=?, full_name=?, birth_year=?, gender=?, room_id=?, bed_number=?,
                         medical_history=?, special_care=?, family_contact=?, status=?, discharged_at=?, updated_at=datetime('now','localtime')
                         WHERE id=?"
                    );
                    $st->execute([
                        $fields['patient_code'], $fields['full_name'], $fields['birth_year'], $fields['gender'],
                        $fields['room_id'], $fields['bed_number'], $fields['medical_history'], $fields['special_care'],
                        $fields['family_contact'], $fields['status'], $dischargedAt, $id
                    ]);
                    AuditLogger::log((int)$user['id'], 'UPDATE', 'patients', $id, $oldRow, $fields);
                    jsonOut(['ok' => true, 'id' => $id]);
                } else {
                    $st = $pdo->prepare(
                        'INSERT INTO patients (patient_code, full_name, birth_year, gender, room_id, bed_number, medical_history, special_care, family_contact, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $st->execute(array_values($fields));
                    $newId = (int)$pdo->lastInsertId();
                    AuditLogger::log((int)$user['id'], 'CREATE', 'patients', $newId, null, $fields);
                    jsonOut(['ok' => true, 'id' => $newId]);
                }

            // --- MODULE 1.5.1.1: SINH HIỆU, HUYẾT ÁP, ĂN UỐNG, NGỦ NGHỈ 24/24 ---
            case 'patient_vitals':
                $pid = (int)($_GET['patient_id'] ?? 0);
                $st = $pdo->prepare(
                    'SELECT v.*, u.full_name AS recorder_name
                     FROM vital_logs v JOIN users u ON u.id = v.recorded_by
                     WHERE v.patient_id = ? ORDER BY v.recorded_at DESC LIMIT 30'
                );
                $st->execute([$pid]);
                jsonOut(['ok' => true, 'data' => $st->fetchAll()]);

            case 'vital_save':
                $pid = (int)($input['patient_id'] ?? 0);
                $patSt = $pdo->prepare('SELECT id, room_id FROM patients WHERE id = ?');
                $patSt->execute([$pid]);
                $pat = $patSt->fetch();
                if (!$pat) jsonOut(['ok' => false, 'error' => 'Vui lòng chọn đúng bệnh nhân cần ghi nhận.'], 422);

                $roomId = !empty($input['room_id']) ? (int)$input['room_id'] : (int)$pat['room_id'];
                $eval = ClinicalService::evaluateVitals($input);
                $payload = [
                    'patient_id'       => $pid,
                    'room_id'          => $roomId,
                    'recorded_by'      => (int)$user['id'],
                    'shift_code'       => (string)($input['shift_code'] ?? Security::currentShift()),
                    'pulse'            => $input['pulse'] !== '' && $input['pulse'] !== null ? (int)$input['pulse'] : null,
                    'temperature'      => $input['temperature'] !== '' && $input['temperature'] !== null ? (float)$input['temperature'] : null,
                    'resp_rate'        => $input['resp_rate'] !== '' && $input['resp_rate'] !== null ? (int)$input['resp_rate'] : null,
                    'spo2'             => $input['spo2'] !== '' && $input['spo2'] !== null ? (int)$input['spo2'] : null,
                    'bp_sys'           => $input['bp_sys'] !== '' && $input['bp_sys'] !== null ? (int)$input['bp_sys'] : null,
                    'bp_dia'           => $input['bp_dia'] !== '' && $input['bp_dia'] !== null ? (int)$input['bp_dia'] : null,
                    'eating_status'    => trim((string)($input['eating_status'] ?? 'Ăn hết suất')),
                    'eating_note'      => trim((string)($input['eating_note'] ?? '')),
                    'sleep_hours'      => $input['sleep_hours'] !== '' && $input['sleep_hours'] !== null ? (float)$input['sleep_hours'] : null,
                    'sleep_quality'    => trim((string)($input['sleep_quality'] ?? 'Ngủ ngon')),
                    'med_taken'        => !empty($input['med_taken']) ? 1 : 0,
                    'clinical_note'    => trim((string)($input['clinical_note'] ?? '')),
                    'is_abnormal'      => $eval['is_abnormal'],
                    'abnormal_reasons' => $eval['reasons'],
                    'raw_voice_text'   => trim((string)($input['raw_voice_text'] ?? '')),
                ];

                $st = $pdo->prepare(
                    'INSERT INTO vital_logs (patient_id, room_id, recorded_by, shift_code, pulse, temperature, resp_rate, spo2, bp_sys, bp_dia,
                     eating_status, eating_note, sleep_hours, sleep_quality, med_taken, clinical_note, is_abnormal, abnormal_reasons, raw_voice_text)
                     VALUES (:patient_id, :room_id, :recorded_by, :shift_code, :pulse, :temperature, :resp_rate, :spo2, :bp_sys, :bp_dia,
                     :eating_status, :eating_note, :sleep_hours, :sleep_quality, :med_taken, :clinical_note, :is_abnormal, :abnormal_reasons, :raw_voice_text)'
                );
                $st->execute($payload);
                $newId = (int)$pdo->lastInsertId();
                AuditLogger::log((int)$user['id'], 'CREATE', 'vital_logs', $newId, null, $payload);
                jsonOut(['ok' => true, 'id' => $newId, 'is_abnormal' => $eval['is_abnormal'], 'abnormal_reasons' => $eval['reasons']]);

            // --- MODULE 1.2.1: CÔNG VIỆC HÀNG NGÀY (Tự reset theo ngày, giữ lịch sử) ---
            case 'daily_tasks_list':
                $date = (string)($_GET['date'] ?? date('Y-m-d'));
                $shift = (string)($_GET['shift'] ?? Security::currentShift());
                $roomId = (int)($_GET['room_id'] ?? 1);

                $st = $pdo->prepare(
                    "SELECT t.id AS template_id, t.title, t.shift_code, t.room_id AS tpl_room_id,
                            l.id AS log_id, COALESCE(l.status, 'pending') AS status, l.note, l.completed_at, u.full_name AS completed_by_name
                     FROM daily_task_templates t
                     LEFT JOIN daily_task_logs l ON l.template_id = t.id AND l.task_date = ? AND l.room_id = ?
                     LEFT JOIN users u ON u.id = l.completed_by
                     WHERE t.is_active = 1 AND t.shift_code = ? AND (t.room_id IS NULL OR t.room_id = ?)
                     ORDER BY t.sort_order ASC, t.id ASC"
                );
                $st->execute([$date, $roomId, $shift, $roomId]);
                jsonOut(['ok' => true, 'data' => $st->fetchAll()]);

            case 'daily_task_toggle':
                $tplId = (int)($input['template_id'] ?? 0);
                $roomId = (int)($input['room_id'] ?? 1);
                $shift = (string)($input['shift_code'] ?? Security::currentShift());
                $date = (string)($input['task_date'] ?? date('Y-m-d'));
                $done = !empty($input['done']);
                $status = $done ? 'done' : 'pending';
                $completedAt = $done ? date('Y-m-d H:i:s') : null;
                $completedBy = $done ? (int)$user['id'] : null;

                $st = $pdo->prepare(
                    'INSERT INTO daily_task_logs (template_id, task_date, room_id, shift_code, completed_by, status, completed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT(template_id, task_date, room_id)
                     DO UPDATE SET status = excluded.status, completed_by = excluded.completed_by, completed_at = excluded.completed_at'
                );
                $st->execute([$tplId, $date, $roomId, $shift, $completedBy, $status, $completedAt]);
                AuditLogger::log((int)$user['id'], 'TOGGLE_DAILY', 'daily_task_logs', $tplId, null, ['date' => $date, 'room_id' => $roomId, 'status' => $status]);
                jsonOut(['ok' => true]);

            case 'daily_template_add':
                if (!Security::hasRole($user, ['admin', 'truong_dieu_duong'])) {
                    jsonOut(['ok' => false, 'error' => 'Chỉ Quản trị hoặc Điều dưỡng trưởng được thêm mẫu công việc.'], 403);
                }
                $title = trim((string)($input['title'] ?? ''));
                $shift = (string)($input['shift_code'] ?? 'sang');
                $roomId = !empty($input['room_id']) ? (int)$input['room_id'] : null;
                if ($title === '') jsonOut(['ok' => false, 'error' => 'Vui lòng nhập nội dung công việc.'], 422);
                $st = $pdo->prepare('INSERT INTO daily_task_templates (room_id, shift_code, title, sort_order) VALUES (?, ?, ?, 99)');
                $st->execute([$roomId, $shift, $title]);
                AuditLogger::log((int)$user['id'], 'CREATE', 'daily_task_templates', (int)$pdo->lastInsertId(), null, ['title' => $title, 'shift' => $shift]);
                jsonOut(['ok' => true]);

            // --- MODULE 1.3.1: CÔNG VIỆC PHÁT SINH ---
            case 'adhoc_list':
                $shift = (string)($_GET['shift'] ?? 'all');
                $status = (string)($_GET['status'] ?? 'all');
                $roomId = (int)($_GET['room_id'] ?? 0);
                $where = [];
                $params = [];
                if ($shift !== 'all') { $where[] = 'a.shift_code = ?'; $params[] = $shift; }
                if ($status !== 'all') { $where[] = 'a.status = ?'; $params[] = $status; }
                if ($roomId > 0) { $where[] = 'a.room_id = ?'; $params[] = $roomId; }
                $wSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

                $st = $pdo->prepare(
                    "SELECT a.*, r.code AS room_code, r.name AS room_name,
                            p.patient_code, p.full_name AS patient_name,
                            u1.full_name AS assignee_name, u2.full_name AS creator_name
                     FROM ad_hoc_tasks a
                     JOIN rooms r ON r.id = a.room_id
                     LEFT JOIN patients p ON p.id = a.patient_id
                     LEFT JOIN users u1 ON u1.id = a.assigned_to
                     JOIN users u2 ON u2.id = a.created_by
                     {$wSql}
                     ORDER BY CASE a.status WHEN 'hoan_thanh' THEN 2 ELSE 1 END,
                              CASE a.priority WHEN 'khan_cap' THEN 1 WHEN 'gap' THEN 2 ELSE 3 END,
                              a.created_at DESC LIMIT 120"
                );
                $st->execute($params);
                jsonOut(['ok' => true, 'data' => $st->fetchAll()]);

            case 'adhoc_save':
                $title = trim((string)($input['title'] ?? ''));
                $roomId = (int)($input['room_id'] ?? 1);
                if ($title === '') jsonOut(['ok' => false, 'error' => 'Vui lòng nhập tiêu đề công việc phát sinh.'], 422);
                $priority = in_array($input['priority'] ?? 'thuong', ['thuong', 'gap', 'khan_cap'], true) ? $input['priority'] : 'thuong';
                $shift = in_array($input['shift_code'] ?? '', ['sang', 'chieu', 'toi'], true) ? $input['shift_code'] : Security::currentShift();
                $patientId = !empty($input['patient_id']) ? (int)$input['patient_id'] : null;
                $assignedTo = !empty($input['assigned_to']) ? (int)$input['assigned_to'] : null;
                $dueAt = !empty($input['due_at']) ? (string)$input['due_at'] : date('Y-m-d H:i:s', strtotime('+2 hours'));

                $st = $pdo->prepare(
                    'INSERT INTO ad_hoc_tasks (room_id, patient_id, shift_code, title, description, priority, assigned_to, created_by, due_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([$roomId, $patientId, $shift, $title, trim((string)($input['description'] ?? '')), $priority, $assignedTo, (int)$user['id'], $dueAt]);
                $newId = (int)$pdo->lastInsertId();
                AuditLogger::log((int)$user['id'], 'CREATE', 'ad_hoc_tasks', $newId, null, ['title' => $title, 'priority' => $priority, 'room_id' => $roomId]);
                jsonOut(['ok' => true, 'id' => $newId]);

            case 'adhoc_update_status':
                $id = (int)($input['id'] ?? 0);
                $status = (string)($input['status'] ?? 'dang_xu_ly');
                if (!in_array($status, ['moi', 'dang_xu_ly', 'hoan_thanh'], true)) {
                    jsonOut(['ok' => false, 'error' => 'Trạng thái không hợp lệ.'], 422);
                }
                $oldSt = $pdo->prepare('SELECT * FROM ad_hoc_tasks WHERE id = ?');
                $oldSt->execute([$id]);
                $old = $oldSt->fetch();
                if (!$old) jsonOut(['ok' => false, 'error' => 'Không tìm thấy công việc.'], 404);

                $completedAt = $status === 'hoan_thanh' ? date('Y-m-d H:i:s') : null;
                $backlogReason = trim((string)($input['backlog_reason'] ?? $old['backlog_reason']));
                $st = $pdo->prepare('UPDATE ad_hoc_tasks SET status=?, completed_at=?, backlog_reason=? WHERE id=?');
                $st->execute([$status, $completedAt, $backlogReason, $id]);
                AuditLogger::log((int)$user['id'], 'UPDATE_STATUS', 'ad_hoc_tasks', $id, ['status' => $old['status']], ['status' => $status, 'backlog_reason' => $backlogReason]);
                jsonOut(['ok' => true]);

            // --- MODULE 1.5.2.1: BÀN GIAO CA SÁNG / TỐI (2 CHIỀU + KHÓA CHỐT CA) ---
            case 'handover_preview':
                jsonOut(['ok' => true, 'data' => ClinicalService::buildHandoverSnapshot()]);

            case 'handovers_list':
                $st = $pdo->query(
                    'SELECT h.*, u1.full_name AS from_name, u2.full_name AS to_name
                     FROM shift_handovers h
                     JOIN users u1 ON u1.id = h.from_user_id
                     LEFT JOIN users u2 ON u2.id = h.to_user_id
                     ORDER BY h.handover_date DESC, h.id DESC LIMIT 40'
                );
                jsonOut(['ok' => true, 'data' => $st->fetchAll()]);

            case 'handover_create':
                $shift = ($input['shift_code'] ?? 'sang') === 'toi' ? 'toi' : 'sang';
                $date = (string)($input['handover_date'] ?? date('Y-m-d'));
                $notes = trim((string)($input['notes'] ?? ''));
                $snapshot = ClinicalService::buildHandoverSnapshot();

                $st = $pdo->prepare(
                    'INSERT INTO shift_handovers (handover_date, shift_code, from_user_id, summary_json, notes, status, is_locked)
                     VALUES (?, ?, ?, ?, ?, ?, 0)'
                );
                $st->execute([$date, $shift, (int)$user['id'], json_encode($snapshot, JSON_UNESCAPED_UNICODE), $notes, 'cho_nhan']);
                $newId = (int)$pdo->lastInsertId();
                AuditLogger::log((int)$user['id'], 'HANDOVER_CREATE', 'shift_handovers', $newId, null, ['date' => $date, 'shift' => $shift]);
                jsonOut(['ok' => true, 'id' => $newId]);

            case 'handover_accept':
                $id = (int)($input['id'] ?? 0);
                $st = $pdo->prepare('SELECT * FROM shift_handovers WHERE id = ?');
                $st->execute([$id]);
                $h = $st->fetch();
                if (!$h) jsonOut(['ok' => false, 'error' => 'Không tìm thấy biên bản bàn giao.'], 404);
                if ((int)$h['is_locked'] === 1) jsonOut(['ok' => false, 'error' => 'Biên bản này đã chốt ca và bị khóa chỉnh sửa.'], 409);

                $upd = $pdo->prepare("UPDATE shift_handovers SET to_user_id=?, status='da_chot', accepted_at=datetime('now','localtime'), is_locked=1 WHERE id=?");
                $upd->execute([(int)$user['id'], $id]);
                AuditLogger::log((int)$user['id'], 'HANDOVER_LOCKED', 'shift_handovers', $id, ['status' => 'cho_nhan'], ['status' => 'da_chot', 'to_user_id' => (int)$user['id']]);
                jsonOut(['ok' => true]);

            // --- MODULE 1.6.1.2: LỊCH SỬ CHAT / TRAO ĐỔI NỘI BỘ + FTS5 ---
            case 'chat_list':
                $channel = (string)($_GET['channel'] ?? 'toan_vien');
                $roomId = (int)($_GET['room_id'] ?? 0);
                $period = (string)($_GET['period'] ?? 'month');
                $q = trim((string)($_GET['q'] ?? ''));
                $range = ClinicalService::dateRange($period, $_GET['date'] ?? date('Y-m-d'));

                $where = ['c.created_at BETWEEN ? AND ?'];
                $params = [$range['start'], $range['end']];
                if ($channel === 'phong' && $roomId > 0) {
                    $where[] = "c.channel_type = 'phong' AND c.room_id = ?";
                    $params[] = $roomId;
                } elseif ($channel === 'toan_vien') {
                    $where[] = "c.channel_type = 'toan_vien'";
                }

                if ($q !== '') {
                    $fts = trim((string)preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q));
                    if ($fts !== '') {
                        $tokens = array_filter(explode(' ', $fts));
                        $expr = implode(' AND ', array_map(static fn($t) => '"' . $t . '"*', $tokens));
                        $where[] = '(c.id IN (SELECT rowid FROM chat_fts WHERE chat_fts MATCH ?) OR c.message LIKE ?)';
                        $params[] = $expr;
                        $params[] = '%' . $q . '%';
                    }
                }

                $sql = "SELECT c.*, u.full_name AS sender_name, u.role AS sender_role,
                               r.code AS room_code, p.patient_code, p.full_name AS patient_name
                        FROM chat_messages c
                        JOIN users u ON u.id = c.sender_id
                        LEFT JOIN rooms r ON r.id = c.room_id
                        LEFT JOIN patients p ON p.id = c.patient_id
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY c.created_at DESC LIMIT 150";
                $st = $pdo->prepare($sql);
                $st->execute($params);
                jsonOut(['ok' => true, 'data' => $st->fetchAll(), 'range' => $range]);

            case 'chat_send':
                $msg = trim((string)($input['message'] ?? ''));
                if ($msg === '') jsonOut(['ok' => false, 'error' => 'Nội dung tin nhắn không được để trống.'], 422);
                $channel = ($input['channel_type'] ?? 'toan_vien') === 'phong' ? 'phong' : 'toan_vien';
                $roomId = $channel === 'phong' ? (int)($input['room_id'] ?? 1) : null;
                $patientId = !empty($input['patient_id']) ? (int)$input['patient_id'] : null;

                $st = $pdo->prepare('INSERT INTO chat_messages (channel_type, room_id, patient_id, sender_id, message) VALUES (?, ?, ?, ?, ?)');
                $st->execute([$channel, $roomId, $patientId, (int)$user['id'], $msg]);
                $newId = (int)$pdo->lastInsertId();
                AuditLogger::log((int)$user['id'], 'CHAT_SEND', 'chat_messages', $newId, null, ['channel' => $channel, 'room_id' => $roomId]);
                jsonOut(['ok' => true, 'id' => $newId]);

            // --- MODULE 1.4.1 & 1.4.1.1: BÁO CÁO TỔNG HỢP ---
            case 'reports':
                $period = (string)($_GET['period'] ?? 'month');
                $refDate = $_GET['date'] ?? date('Y-m-d');
                jsonOut(['ok' => true, 'data' => ClinicalService::getComprehensiveReport($period, $refDate)]);

            // --- MODULE 1.6.1 & 1.6.1.3: QUẢN TRỊ SQLITE, BACKUP, ARCHIVE, AUDIT LOG ---
            case 'sqlite_admin':
                if (!Security::hasRole($user, ['admin', 'truong_dieu_duong'])) {
                    jsonOut(['ok' => false, 'error' => 'Yêu cầu quyền Quản trị hoặc Điều dưỡng trưởng.'], 403);
                }
                $dbFile = AppConfig::dbPath();
                $walFile = $dbFile . '-wal';
                $tables = ['users', 'rooms', 'patients', 'vital_logs', 'daily_task_logs', 'ad_hoc_tasks', 'shift_handovers', 'chat_messages', 'audit_logs'];
                $counts = [];
                foreach ($tables as $tb) {
                    $counts[$tb] = (int)$pdo->query("SELECT COUNT(*) FROM {$tb}")->fetchColumn();
                }
                $audits = $pdo->query(
                    'SELECT a.*, u.full_name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 40'
                )->fetchAll();

                jsonOut([
                    'ok' => true,
                    'data' => [
                        'journal_mode' => $pdo->query('PRAGMA journal_mode')->fetchColumn(),
                        'busy_timeout' => $pdo->query('PRAGMA busy_timeout')->fetchColumn(),
                        'db_size'      => file_exists($dbFile) ? filesize($dbFile) : 0,
                        'wal_size'     => file_exists($walFile) ? filesize($walFile) : 0,
                        'last_vacuum'  => Database::getMeta('last_vacuum_at', 'Chưa chạy'),
                        'last_backup'  => Database::getMeta('last_auto_backup_date', 'Chưa có'),
                        'counts'       => $counts,
                        'backups'      => BackupManager::listBackups(),
                        'audits'       => $audits,
                        'ai_config'    => AiVoiceAdapter::getConfig(),
                    ],
                ]);

            case 'sqlite_vacuum':
                if (!Security::hasRole($user, ['admin'])) {
                    jsonOut(['ok' => false, 'error' => 'Chỉ Quản trị viên (Admin) mới được tối ưu hóa VACUUM.'], 403);
                }
                $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE);');
                $pdo->exec('VACUUM;');
                Database::setMeta('last_vacuum_at', date('Y-m-d H:i:s'));
                AuditLogger::log((int)$user['id'], 'SQLITE_VACUUM', 'system_meta', null);
                jsonOut(['ok' => true, 'message' => 'Đã đồng bộ WAL và tối ưu hóa (VACUUM) CSDL SQLite thành công.']);

            case 'sqlite_backup_now':
                if (!Security::hasRole($user, ['admin', 'truong_dieu_duong'])) {
                    jsonOut(['ok' => false, 'error' => 'Không có quyền sao lưu.'], 403);
                }
                $res = BackupManager::createBackup('manual');
                AuditLogger::log((int)$user['id'], 'MANUAL_BACKUP', 'system_backup', null, null, $res);
                jsonOut(['ok' => true, 'data' => $res]);

            case 'sqlite_archive_export':
                if (!Security::hasRole($user, ['admin', 'truong_dieu_duong'])) {
                    jsonOut(['ok' => false, 'error' => 'Không có quyền xuất gói lưu trữ.'], 403);
                }
                $year = (int)($input['year'] ?? date('Y'));
                $month = !empty($input['month']) ? (int)$input['month'] : null;
                $res = BackupManager::buildArchivePackage($year, $month);
                AuditLogger::log((int)$user['id'], 'ARCHIVE_EXPORT', 'system_archive', null, null, ['year' => $year, 'month' => $month, 'file' => $res['file']]);
                jsonOut(['ok' => true, 'data' => $res]);

            case 'ai_config_save':
                if (!Security::hasRole($user, ['admin'])) {
                    jsonOut(['ok' => false, 'error' => 'Chỉ Quản trị viên mới được đổi cấu hình AI Adapter.'], 403);
                }
                $cur = AiVoiceAdapter::getConfig();
                $newCfg = [
                    'mode'           => in_array($input['mode'] ?? 'local_rule', ['local_rule', 'gemini', 'ollama'], true) ? $input['mode'] : 'local_rule',
                    'gemini_api_key' => ($input['gemini_api_key'] ?? '') === '********' ? $cur['gemini_api_key'] : trim((string)($input['gemini_api_key'] ?? '')),
                    'gemini_model'   => trim((string)($input['gemini_model'] ?? 'gemini-2.5-flash-lite')),
                    'ollama_url'     => trim((string)($input['ollama_url'] ?? 'http://127.0.0.1:11434/api/generate')),
                    'ollama_model'   => trim((string)($input['ollama_model'] ?? 'qwen2.5:3b')),
                ];
                Database::setMeta('ai_config', json_encode($newCfg, JSON_UNESCAPED_UNICODE));
                AuditLogger::log((int)$user['id'], 'UPDATE_AI_CONFIG', 'system_meta', null, ['mode' => $cur['mode']], ['mode' => $newCfg['mode']]);
                jsonOut(['ok' => true]);

            default:
                jsonOut(['ok' => false, 'error' => 'API không tồn tại.'], 404);
        }
    } catch (Throwable $e) {
        jsonOut(['ok' => false, 'error' => 'Lỗi xử lý hệ thống: ' . $e->getMessage()], 500);
    }
}

// ============================================================================
// [PHẦN 5] VIEWS - GIAO DIỆN MODERN CLINICAL BENTO UI (DARK/LIGHT + PWA)
// ============================================================================
$user = currentUser();
$csrf = Security::csrfToken();
$selfUrl = Security::e(basename(__FILE__));
?>
<!DOCTYPE html>
<html lang="vi" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0f294a">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="manifest" href="<?= $selfUrl ?>?action=manifest">
<title><?= Security::e(AppConfig::APP_NAME) ?></title>
<style>
/* ==========================================================================
   MODERN CLINICAL BENTO UI — 100% CSS THUẦN, ZERO CDN, DARK/LIGHT MODE
   ========================================================================== */
:root {
  --bg: #f1f5f9;
  --surface: #ffffff;
  --surface-2: #f8fafc;
  --border: #e2e8f0;
  --text: #0f172a;
  --text-muted: #475569;
  --navy: #0f294a;
  --primary: #1e40af;
  --primary-hover: #1e3a8a;
  --primary-soft: #dbeafe;
  --sidebar-bg: #0f294a;
  --sidebar-border: #1e3a5f;
  --sidebar-text: #e2e8f0;
  --sidebar-muted: #93c5fd;
  --green: #15803d; --green-bg: #dcfce7; --green-bd: #86efac;
  --yellow: #b45309; --yellow-bg: #fef3c7; --yellow-bd: #fcd34d;
  --red: #b91c1c; --red-bg: #fee2e2; --red-bd: #fca5a5;
  --shadow: 0 2px 10px rgba(15, 41, 74, 0.06);
  --radius: 14px;
}
[data-theme="dark"] {
  --bg: #09111e;
  --surface: #111c2e;
  --surface-2: #17253b;
  --border: #243654;
  --text: #f1f5f9;
  --text-muted: #94a3b8;
  --navy: #60a5fa;
  --primary: #3b82f6;
  --primary-hover: #2563eb;
  --primary-soft: rgba(59, 130, 246, 0.18);
  --sidebar-bg: #071426;
  --sidebar-border: #162a47;
  --sidebar-text: #e2e8f0;
  --sidebar-muted: #93c5fd;
  --green: #4ade80; --green-bg: rgba(22, 163, 74, 0.16); --green-bd: rgba(74, 222, 128, 0.4);
  --yellow: #fbbf24; --yellow-bg: rgba(217, 119, 6, 0.18); --yellow-bd: rgba(251, 191, 36, 0.4);
  --red: #f87171; --red-bg: rgba(220, 38, 38, 0.2); --red-bd: rgba(248, 113, 113, 0.45);
  --shadow: 0 6px 20px rgba(0, 0, 0, 0.38);
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
body {
  margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  background: var(--bg); color: var(--text); line-height: 1.5; font-size: 15px;
  transition: background .2s ease, color .2s ease;
}
a { color: var(--primary); text-decoration: none; }
button, input, select, textarea { font: inherit; color: inherit; }

/* Nút bấm tối ưu thao tác 1 tay (tối thiểu 42px) */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  min-height: 42px; padding: 8px 14px; border-radius: 12px; border: 1px solid var(--border);
  background: var(--surface); color: var(--text); font-weight: 600; cursor: pointer;
  transition: transform .1s ease, background .15s ease;
}
.btn:active { transform: scale(0.97); }
.btn-primary { background: var(--primary); color: #fff; border-color: transparent; }
.btn-primary:hover { background: var(--primary-hover); }
.btn-danger { background: var(--red); color: #fff; border-color: transparent; }
.btn-soft { background: var(--surface-2); }
.btn-sm { min-height: 34px; padding: 5px 10px; font-size: 13px; border-radius: 9px; }

.input, select.input, textarea.input {
  width: 100%; min-height: 42px; padding: 9px 12px; border-radius: 11px;
  border: 1px solid var(--border); background: var(--surface-2); color: var(--text);
  outline: none;
}
.input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
label.lbl { display: block; font-size: 12.5px; font-weight: 600; color: var(--text-muted); margin-bottom: 4px; }

/* Đăng nhập */
.login-wrap {
  min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
}
.login-card {
  width: 100%; max-width: 420px; background: var(--surface); border: 1px solid var(--border);
  border-radius: 22px; padding: 28px; box-shadow: var(--shadow);
}
.brand-badge {
  display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px;
  border-radius: 999px; background: var(--primary-soft); color: var(--primary);
  font-weight: 700; font-size: 13px; margin-bottom: 12px;
}

/* Bố cục ứng dụng chính (Sidebar Xanh Đậm PC + Bottom Nav Mobile 1 tay) */
.app-shell { display: flex; min-height: 100vh; width: 100%; overflow-x: hidden; }
.sidebar {
  width: 238px; background: var(--sidebar-bg); border-right: 1px solid var(--sidebar-border);
  padding: 12px 10px; display: flex; flex-direction: column; gap: 4px;
  position: sticky; top: 0; height: 100vh; overflow-y: auto; flex-shrink: 0; color: var(--sidebar-text);
}
.nav-item {
  display: flex; align-items: center; gap: 8px; padding: 9px 10px; border-radius: 10px;
  font-size: 13.5px; font-weight: 600; color: var(--sidebar-text); cursor: pointer; border: none;
  background: transparent; width: 100%; text-align: left; white-space: nowrap;
  overflow: hidden; text-overflow: ellipsis; transition: background .15s ease;
}
.nav-item:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
.nav-item.active { background: #1e40af; color: #fff; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.22); }

.main-area { flex: 1; min-width: 0; display: flex; flex-direction: column; padding-bottom: 92px; }
.topbar {
  position: sticky; top: 0; z-index: 30; background: var(--surface);
  border-bottom: 1px solid var(--border); padding: 10px 18px;
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.content { padding: 14px 18px; max-width: 1440px; width: 100%; margin: 0 auto; }

/* Thanh trạng thái Thời gian thực ngay khi đăng nhập */
.live-pulse-bar {
  display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px;
  background: linear-gradient(135deg, #0f294a, #1e40af); color: #fff;
  border-radius: var(--radius); padding: 10px 14px; margin-bottom: 12px; box-shadow: var(--shadow);
}
.live-dot {
  display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #4ade80;
  box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.7); animation: livePing 1.6s infinite;
}
@keyframes livePing {
  0% { box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.7); }
  70% { box-shadow: 0 0 0 7px rgba(74, 222, 128, 0); }
  100% { box-shadow: 0 0 0 0 rgba(74, 222, 128, 0); }
}
.rt-filter-pills { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.rt-pill {
  border: 1px solid rgba(255,255,255,0.22); background: rgba(255,255,255,0.12); color: #fff;
  padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; cursor: pointer;
  transition: background .15s ease;
}
.rt-pill:hover { background: rgba(255,255,255,0.22); }
.rt-pill.active { background: #fff; color: #0f294a; border-color: #fff; }

/* Bento Grid & Thẻ gọn gàng */
.bento-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(145px, 1fr)); gap: 10px; margin-bottom: 12px; }
.kpi-card {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 11px 14px; box-shadow: var(--shadow); cursor: pointer; transition: transform .12s ease, border-color .15s ease;
}
.kpi-card:hover { transform: translateY(-1px); border-color: var(--primary); }
.kpi-val { font-size: 22px; font-weight: 800; line-height: 1.15; margin-top: 3px; color: var(--navy); }
.kpi-lbl { font-size: 12px; color: var(--text-muted); font-weight: 600; }

.rooms-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.room-card {
  background: var(--surface); border: 1.5px solid var(--border); border-radius: 14px;
  padding: 12px 14px; box-shadow: var(--shadow); cursor: pointer; transition: transform .15s ease;
  display: flex; flex-direction: column; gap: 8px;
}

/* Luồng thông tin thời gian thực (Sinh hiệu + Hoạt động) */
.rt-feed-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(310px, 1fr)); gap: 12px; margin-top: 14px; }
.rt-feed-card {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 12px 14px; box-shadow: var(--shadow);
}
.rt-feed-head {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  padding-bottom: 8px; margin-bottom: 8px; border-bottom: 1px solid var(--border);
}
.rt-feed-title { font-size: 13.5px; font-weight: 800; color: var(--navy); margin: 0; }
.rt-row {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;
  padding: 6px 0; border-bottom: 1px dashed var(--border); font-size: 12.5px;
}
.rt-row:last-child { border-bottom: none; padding-bottom: 0; }
.rt-badge {
  display: inline-block; padding: 2px 6px; border-radius: 6px; font-size: 11px; font-weight: 700;
  background: var(--primary); color: #fff; flex-shrink: 0;
}
.room-card:hover { transform: translateY(-2px); }
.room-card.status-green { border-color: var(--green-bd); }
.room-card.status-yellow { border-color: var(--yellow-bd); }
.room-card.status-red { border-color: var(--red-bd); animation: pulseRed 2.2s infinite; }
@keyframes pulseRed {
  0%, 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.25); }
  50% { box-shadow: 0 0 0 8px rgba(220, 38, 38, 0); }
}

.pill {
  display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px;
  border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap;
}
.pill-green { background: var(--green-bg); color: var(--green); }
.pill-yellow { background: var(--yellow-bg); color: var(--yellow); }
.pill-red { background: var(--red-bg); color: var(--red); }
.pill-neutral { background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border); }

.card {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 16px; box-shadow: var(--shadow); margin-bottom: 16px;
}
.card-head {
  display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
  gap: 10px; margin-bottom: 14px;
}
.card-title { font-size: 17px; font-weight: 800; margin: 0; }
.grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
.grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }

/* Bảng dữ liệu responsive */
.table-wrap { overflow-x: auto; }
table.tbl { width: 100%; border-collapse: collapse; font-size: 13.5px; }
table.tbl th, table.tbl td { padding: 10px 10px; border-bottom: 1px solid var(--border); text-align: left; }
table.tbl th { font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: var(--text-muted); background: var(--surface-2); }

/* Nút Ghi Âm 1 Chạm Nổi Bật (Voice-to-Action FAB) */
.voice-fab {
  position: fixed; right: 20px; bottom: 84px; z-index: 45;
  width: 62px; height: 62px; border-radius: 50%; border: none;
  background: linear-gradient(135deg, #0f294a, #2563eb); color: #fff;
  box-shadow: 0 10px 26px rgba(15, 118, 110, 0.45); cursor: pointer;
  display: flex; align-items: center; justify-content: center; font-size: 26px;
}
.voice-fab.recording { background: linear-gradient(135deg, #dc2626, #f97316); animation: pulseRed 1s infinite; }

/* Thanh điều hướng dưới cùng cho Mobile 1 tay */
.bottom-nav {
  display: none; position: fixed; bottom: 0; left: 0; right: 0; z-index: 40;
  background: var(--surface); border-top: 1px solid var(--border);
  padding: 6px 6px calc(6px + env(safe-area-inset-bottom));
  justify-content: space-around;
}
.bnav-btn {
  display: flex; flex-direction: column; align-items: center; gap: 2px;
  border: none; background: transparent; color: var(--text-muted);
  font-size: 11px; font-weight: 700; padding: 6px 8px; border-radius: 10px; cursor: pointer;
}
.bnav-btn.active { color: var(--primary); background: var(--primary-soft); }

/* Modal */
.modal-backdrop {
  position: fixed; inset: 0; z-index: 60; background: rgba(15, 23, 42, 0.65);
  display: none; align-items: center; justify-content: center; padding: 14px;
}
.modal-backdrop.open { display: flex; }
.modal-box {
  width: 100%; max-width: 660px; max-height: 90vh; overflow-y: auto;
  background: var(--surface); border: 1px solid var(--border); border-radius: 20px;
  padding: 20px; box-shadow: 0 20px 50px rgba(0, 0, 0, 0.4);
}
.toast {
  position: fixed; top: 18px; right: 18px; z-index: 90; padding: 12px 16px;
  border-radius: 12px; background: #0f172a; color: #fff; font-weight: 600;
  box-shadow: 0 10px 28px rgba(0,0,0,.3); display: none; max-width: 360px;
}
.section-view { display: none; }
.section-view.active { display: block; }
.progress-bar { height: 8px; border-radius: 99px; background: var(--surface-2); overflow: hidden; border: 1px solid var(--border); }
.progress-fill { height: 100%; background: var(--primary); transition: width .3s ease; }

@media (max-width: 900px) {
  .sidebar { display: none; }
  .bottom-nav { display: flex; }
  .content { padding: 14px 12px; }
  .topbar { padding: 10px 12px; }
}
@media (min-width: 901px) {
  .voice-fab { bottom: 26px; right: 26px; }
  .main-area { padding-bottom: 30px; }
}
</style>
</head>
<body>
<div id="toast" class="toast"></div>

<?php if (!$user): ?>
<!-- ==================== MÀN HÌNH ĐĂNG NHẬP ==================== -->
<div class="login-wrap">
  <div class="login-card">
    <div class="brand-badge">🏥 Hệ thống Nội trú 24/24 • SQLite WAL</div>
    <h1 style="margin:0 0 6px;font-size:22px;">Đăng nhập Ca trực</h1>
    <p style="margin:0 0 18px;color:var(--text-muted);font-size:13.5px;">7 Phòng chức năng • 200+ Bệnh nhân • Bảo mật RBAC & Audit Log</p>
    <form id="loginForm">
      <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
      <div style="margin-bottom:12px;">
        <label class="lbl">Tên đăng nhập</label>
        <input class="input" name="username" required autocomplete="username" placeholder="VD: admin, ddtruong, dieuduong1">
      </div>
      <div style="margin-bottom:16px;">
        <label class="lbl">Mật khẩu</label>
        <input class="input" type="password" name="password" required autocomplete="current-password" placeholder="Nhập mật khẩu...">
      </div>
      <div id="loginErr" style="color:var(--red);font-size:13px;font-weight:600;margin-bottom:10px;display:none;"></div>
      <button class="btn btn-primary" type="submit" style="width:100%;">Đăng nhập vào hệ thống</button>
    </form>
    <div style="margin-top:18px;padding:12px;border-radius:12px;background:var(--surface-2);font-size:12.5px;color:var(--text-muted);">
      <strong>Tài khoản khởi tạo mặc định:</strong><br>
      • Quản trị: <code>admin</code> / <code>Admin@2026</code><br>
      • ĐD Trưởng: <code>ddtruong</code> / <code>Truong@2026</code><br>
      • Nhân viên trực: <code>dieuduong1</code> / <code>CaTruc@2026</code>
    </div>
  </div>
</div>
<script>
document.getElementById('loginForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const res = await fetch('<?= $selfUrl ?>?action=login', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.ok) { location.reload(); }
  else {
    const err = document.getElementById('loginErr');
    err.textContent = data.error || 'Đăng nhập thất bại.';
    err.style.display = 'block';
  }
});
</script>
</body>
</html>
<?php exit; endif; ?>

<!-- ==================== ỨNG DỤNG CHÍNH ==================== -->
<div class="app-shell">
  <!-- Thanh bên (Máy tính / Màn hình phòng trực) -->
  <aside class="sidebar">
    <div style="padding:4px 8px 12px;border-bottom:1px solid var(--sidebar-border);margin-bottom:6px;">
      <div style="font-weight:800;font-size:15.5px;color:#fff;">🏥 Nội Trú 24/24</div>
      <div style="font-size:11.5px;color:var(--sidebar-muted);margin-top:2px;">7 Phòng • 200 BN • SQLite WAL</div>
    </div>
    <?php foreach (AppConfig::MODULES as $idx => $mod): ?>
      <?php if (!$mod['enabled']) continue; ?>
      <?php if (!empty($mod['roles']) && !Security::hasRole($user, $mod['roles'])) continue; ?>
      <button class="nav-item<?= $idx === 0 ? ' active' : '' ?>" data-tab="<?= Security::e($mod['id']) ?>"><?= $mod['icon'] ?> <?= Security::e($mod['label']) ?></button>
    <?php endforeach; ?>
    <div style="margin-top:auto;padding-top:10px;border-top:1px solid var(--sidebar-border);font-size:12px;">
      <div style="font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= Security::e($user['full_name']) ?></div>
      <div style="color:var(--sidebar-muted);margin-bottom:8px;">Vai trò: <?= Security::e($user['role']) ?></div>
      <a class="btn btn-sm" style="width:100%;background:rgba(255,255,255,0.12);color:#fff;border-color:rgba(255,255,255,0.2);" href="<?= $selfUrl ?>?action=logout">Đăng xuất</a>
    </div>
  </aside>

  <div class="main-area">
    <!-- Thanh tiêu đề trên cùng -->
    <header class="topbar">
      <div>
        <div id="pageTitle" style="font-weight:800;font-size:17px;">Dashboard 7 Phòng (Real-time)</div>
        <div id="liveClock" style="font-size:12px;color:var(--text-muted);">Đang đồng bộ thời gian thực...</div>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <button class="btn btn-primary btn-sm" onclick="openVoiceModal()">🎙️ Báo cáo Giọng nói</button>
        <button class="btn btn-sm" id="themeToggle" title="Chuyển Dark/Light Mode trực đêm">🌙</button>
      </div>
    </header>

    <main class="content">
      <!-- 1. TAB DASHBOARD 7 PHÒNG REAL-TIME (THẤY LIỀN KHI ĐĂNG NHẬP) -->
      <section id="tab-dashboard" class="section-view active">
        <div class="live-pulse-bar">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span style="display:inline-flex;align-items:center;gap:6px;font-weight:700;font-size:12.5px;">
              <span class="live-dot"></span> TRỰC TIẾP 24/24
            </span>
            <span id="rtClockBadge" style="font-size:12.5px;opacity:.92;font-weight:600;">--:--:--</span>
            <span id="rtShiftBadge" style="background:rgba(255,255,255,0.15);padding:2px 8px;border-radius:999px;font-size:11.5px;font-weight:600;">Đang tải ca...</span>
            <span id="rtStaffBadge" style="font-size:11.5px;opacity:.85;"></span>
          </div>
          <div class="rt-filter-pills">
            <button class="rt-pill active" data-rfilter="all" onclick="setRoomFilter('all')">Tất cả 7 phòng</button>
            <button class="rt-pill" data-rfilter="red" onclick="setRoomFilter('red')">Đỏ</button>
            <button class="rt-pill" data-rfilter="yellow" onclick="setRoomFilter('yellow')">Vàng</button>
            <button class="rt-pill" data-rfilter="green" onclick="setRoomFilter('green')">Xanh</button>
            <button class="rt-pill" onclick="loadDashboard(true)" title="Đồng bộ ngay">↻ Làm mới</button>
          </div>
        </div>

        <div class="bento-kpis" id="kpiRow"></div>

        <div id="alertBanner" class="card" style="display:none;border-color:var(--red-bd);background:var(--red-bg);padding:10px 14px;margin-bottom:12px;">
          <div style="font-weight:800;color:var(--red);margin-bottom:4px;font-size:13.5px;">🚨 Cảnh báo Sinh hiệu bất thường (24h gần nhất)</div>
          <div id="alertBannerList" style="font-size:13px;"></div>
        </div>

        <div class="rooms-grid" id="roomsGrid"></div>

        <!-- Luồng thông tin thời gian thực: Sinh hiệu + Việc phát sinh & Hoạt động -->
        <div class="rt-feed-grid">
          <div class="rt-feed-card">
            <div class="rt-feed-head">
              <h3 class="rt-feed-title">💓 Sinh hiệu mới ghi nhận (Real-time)</h3>
              <button class="btn btn-sm btn-soft" onclick="switchTab('patients')">Xem tất cả</button>
            </div>
            <div id="rtVitalsFeed"><div style="font-size:12.5px;color:var(--text-muted);">Đang đồng bộ...</div></div>
          </div>
          <div class="rt-feed-card">
            <div class="rt-feed-head">
              <h3 class="rt-feed-title">⚡ Việc phát sinh & Hoạt động hệ thống</h3>
              <button class="btn btn-sm btn-soft" onclick="switchTab('adhoc')">Mở việc phát sinh</button>
            </div>
            <div id="rtOpsFeed"><div style="font-size:12.5px;color:var(--text-muted);">Đang đồng bộ...</div></div>
          </div>
        </div>
      </section>

      <!-- 2. TAB BỆNH NHÂN & THEO DÕI 24/24 (1.5 & 1.6.1.1) -->
      <section id="tab-patients" class="section-view">
        <div class="card">
          <div class="card-head">
            <h2 class="card-title">Hồ sơ Bệnh nhân & Kho lưu trữ (FTS5)</h2>
            <div style="display:flex;flex-wrap:wrap;gap:6px;">
              <button class="btn btn-primary btn-sm" onclick="openPatientModal()">+ Thêm Bệnh nhân</button>
              <a class="btn btn-sm" target="_blank" href="<?= $selfUrl ?>?action=export&type=patients&format=excel">Excel</a>
              <a class="btn btn-sm" target="_blank" href="<?= $selfUrl ?>?action=export&type=patients&format=word">Word</a>
              <a class="btn btn-sm" target="_blank" href="<?= $selfUrl ?>?action=export&type=patients&format=pdf">PDF</a>
              <a class="btn btn-sm" target="_blank" href="<?= $selfUrl ?>?action=export&type=patients&format=web">Web</a>
            </div>
          </div>
          <div class="grid-3" style="margin-bottom:12px;">
            <input class="input" id="patSearch" placeholder="Tìm nhanh FTS5: tên, mã BN, bệnh nền, người nhà...">
            <select class="input" id="patRoomFilter"><option value="0">Tất cả 7 phòng</option></select>
            <select class="input" id="patStatusFilter">
              <option value="active">Đang điều trị nội trú</option>
              <option value="discharged">Đã xuất viện</option>
              <option value="archived">Đã lưu trữ</option>
              <option value="all">Toàn bộ kho dữ liệu</option>
            </select>
          </div>
          <div class="table-wrap">
            <table class="tbl">
              <thead>
                <tr><th>Mã / Họ tên</th><th>Phòng / Giường</th><th>Chăm sóc đặc biệt</th><th>Sinh hiệu gần nhất</th><th>Thao tác</th></tr>
              </thead>
              <tbody id="patientsBody"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- 3. TAB CÔNG VIỆC HÀNG NGÀY (1.2.1) -->
      <section id="tab-daily" class="section-view">
        <div class="card">
          <div class="card-head">
            <h2 class="card-title">Checklist Công việc Hàng ngày (Tự reset theo ngày)</h2>
            <?php if (Security::hasRole($user, ['admin', 'truong_dieu_duong'])): ?>
            <button class="btn btn-primary btn-sm" onclick="openDailyTplModal()">+ Thêm đầu việc mẫu</button>
            <?php endif; ?>
          </div>
          <div class="grid-3" style="margin-bottom:14px;">
            <input type="date" class="input" id="dailyDate">
            <select class="input" id="dailyShift">
              <option value="sang">Ca Sáng (07:00 - 13:30)</option>
              <option value="chieu">Ca Chiều (13:30 - 19:00)</option>
              <option value="toi">Ca Tối (19:00 - 07:00)</option>
            </select>
            <select class="input" id="dailyRoom"></select>
          </div>
          <div id="dailyList"></div>
        </div>
      </section>

      <!-- 4. TAB CÔNG VIỆC PHÁT SINH (1.3.1) -->
      <section id="tab-adhoc" class="section-view">
        <div class="card">
          <div class="card-head">
            <h2 class="card-title">Công việc Phát sinh Đột xuất</h2>
            <button class="btn btn-primary btn-sm" onclick="openAdhocModal()">+ Ghi nhận phát sinh</button>
          </div>
          <div class="grid-3" style="margin-bottom:14px;">
            <select class="input" id="adhocShiftFilter">
              <option value="all">Mọi ca trực</option>
              <option value="sang">Ca Sáng</option>
              <option value="chieu">Ca Chiều</option>
              <option value="toi">Ca Tối</option>
            </select>
            <select class="input" id="adhocRoomFilter"><option value="0">Tất cả 7 phòng</option></select>
            <select class="input" id="adhocStatusFilter">
              <option value="all">Mọi trạng thái</option>
              <option value="moi">Mới tạo</option>
              <option value="dang_xu_ly">Đang xử lý</option>
              <option value="hoan_thanh">Đã hoàn thành</option>
            </select>
          </div>
          <div id="adhocList"></div>
        </div>
      </section>

      <!-- 5. TAB BÀN GIAO CA SÁNG / TỐI (1.5.2.1) -->
      <section id="tab-handover" class="section-view">
        <div class="card">
          <div class="card-head">
            <h2 class="card-title">Bàn giao ca 2 chiều (Khóa chỉnh sửa sau khi chốt)</h2>
            <button class="btn btn-primary btn-sm" onclick="openHandoverModal()">+ Lập biên bản giao ca</button>
          </div>
          <div id="handoverList"></div>
        </div>
      </section>

      <!-- 6. TAB CHAT / TRAO ĐỔI NỘI BỘ + FTS5 (1.6.1.2) -->
      <section id="tab-chat" class="section-view">
        <div class="card">
          <div class="card-head">
            <h2 class="card-title">Trao đổi Nội bộ & Tra cứu toàn văn (FTS5)</h2>
          </div>
          <div class="grid-3" style="margin-bottom:12px;">
            <select class="input" id="chatChannel">
              <option value="toan_vien">Kênh Toàn viện</option>
              <option value="phong">Theo từng Phòng</option>
            </select>
            <select class="input" id="chatRoom" style="display:none;"></select>
            <select class="input" id="chatPeriod">
              <option value="day">Hôm nay</option>
              <option value="week">Tuần này</option>
              <option value="month" selected>Tháng này</option>
              <option value="quarter">Quý này</option>
              <option value="year">Năm nay</option>
            </select>
            <input class="input" id="chatSearch" placeholder="Tìm toàn văn FTS5 trong lịch sử chat...">
          </div>
          <form id="chatForm" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
            <select class="input" id="chatTagPatient" style="flex:1;min-width:180px;"><option value="">-- Đính kèm tag Bệnh nhân (tuỳ chọn) --</option></select>
            <input class="input" id="chatInput" style="flex:3;min-width:220px;" placeholder="Nhập ghi chú trao đổi ca trực..." required>
            <button class="btn btn-primary" type="submit">Gửi</button>
          </form>
          <div id="chatMessages" style="display:flex;flex-direction:column;gap:10px;max-height:520px;overflow-y:auto;"></div>
        </div>
      </section>

      <!-- 7. TAB BÁO CÁO TỔNG HỢP & PHÁT SINH (1.4.1 & 1.4.1.1) -->
      <section id="tab-reports" class="section-view">
        <div class="card">
          <div class="card-head">
            <h2 class="card-title">Báo cáo Tổng hợp & Công việc Phát sinh (1.4)</h2>
            <div style="display:flex;flex-wrap:wrap;gap:6px;">
              <select class="input" id="repPeriod" style="width:auto;">
                <option value="day">Theo Ngày</option>
                <option value="week">Theo Tuần</option>
                <option value="month" selected>Theo Tháng</option>
                <option value="quarter">Theo Quý</option>
                <option value="year">Theo Năm</option>
              </select>
              <input type="date" class="input" id="repDate" style="width:auto;">
              <button class="btn btn-sm" onclick="exportReport('excel')">Excel</button>
              <button class="btn btn-sm" onclick="exportReport('word')">Word</button>
              <button class="btn btn-sm" onclick="exportReport('pdf')">PDF</button>
              <button class="btn btn-sm" onclick="exportReport('web')">Web</button>
            </div>
          </div>
          <div class="bento-kpis" id="repKpis"></div>
          <div class="grid-2">
            <div class="card" style="margin:0;">
              <h3 style="margin:0 0 10px;font-size:15px;">Tần suất công việc phát sinh theo 7 Phòng</h3>
              <div id="repRoomChart"></div>
            </div>
            <div class="card" style="margin:0;">
              <h3 style="margin:0 0 10px;font-size:15px;">Phân bổ theo Ca trực & Tồn đọng</h3>
              <div id="repShiftChart"></div>
              <div id="repBacklogList" style="margin-top:12px;"></div>
            </div>
          </div>
        </div>
      </section>

      <!-- 8. TAB QUẢN TRỊ THƯ VIỆN SQLITE, BACKUP & AI ADAPTER (1.6) -->
      <?php if (Security::hasRole($user, ['admin', 'truong_dieu_duong'])): ?>
      <section id="tab-admin" class="section-view">
        <div class="bento-kpis" id="sqliteKpis"></div>
        <div class="grid-2">
          <div class="card">
            <h3 class="card-title" style="margin-bottom:10px;">Sao lưu & Lưu trữ dài hạn (1.6.1.3)</h3>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
              <button class="btn btn-primary btn-sm" onclick="runBackupNow()">💾 Sao lưu ngay (.zip/.sqlite)</button>
              <?php if (Security::hasRole($user, ['admin'])): ?>
              <button class="btn btn-sm" onclick="runVacuum()">🧹 Tối ưu WAL & VACUUM</button>
              <?php endif; ?>
            </div>
            <div style="padding:12px;border-radius:12px;background:var(--surface-2);margin-bottom:14px;">
              <div style="font-weight:700;font-size:13.5px;margin-bottom:8px;">Xuất gói lưu trữ dài hạn theo Tháng / Năm</div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <input type="number" class="input" id="archYear" value="<?= date('Y') ?>" style="width:100px;">
                <select class="input" id="archMonth" style="width:140px;">
                  <option value="">Cả năm</option>
                  <?php for ($m = 1; $m <= 12; $m++): ?>
                  <option value="<?= $m ?>">Tháng <?= $m ?></option>
                  <?php endfor; ?>
                </select>
                <button class="btn btn-sm btn-primary" onclick="exportArchive()">📦 Xuất gói Archive</button>
              </div>
            </div>
            <div id="backupList" style="font-size:13px;"></div>
          </div>

          <div class="card">
            <h3 class="card-title" style="margin-bottom:10px;">Cấu hình AI Adapter (Voice-to-Action)</h3>
            <form id="aiConfigForm">
              <div style="margin-bottom:10px;">
                <label class="lbl">Chế độ nhận diện & bóc tách giọng nói</label>
                <select class="input" id="aiMode">
                  <option value="local_rule">Offline Local Rule (Tiếng Việt 100% miễn phí, không cần mạng)</option>
                  <option value="gemini">Cloud AI — Google Gemini API</option>
                  <option value="ollama">Local AI — Ollama nội bộ (Bảo mật y tế tuyệt đối)</option>
                </select>
              </div>
              <div style="margin-bottom:10px;">
                <label class="lbl">Gemini API Key (nếu dùng chế độ Gemini)</label>
                <input class="input" id="aiGeminiKey" placeholder="AIza...">
              </div>
              <div class="grid-2" style="margin-bottom:12px;">
                <div><label class="lbl">Ollama URL</label><input class="input" id="aiOllamaUrl"></div>
                <div><label class="lbl">Ollama Model</label><input class="input" id="aiOllamaModel"></div>
              </div>
              <?php if (Security::hasRole($user, ['admin'])): ?>
              <button class="btn btn-primary btn-sm" type="submit">Lưu cấu hình AI Adapter</button>
              <?php else: ?>
              <div style="font-size:12.5px;color:var(--text-muted);">Chỉ Quản trị viên (Admin) được thay đổi cấu hình AI.</div>
              <?php endif; ?>
            </form>
          </div>
        </div>
        <div class="card">
          <h3 class="card-title" style="margin-bottom:10px;">Nhật ký Hệ thống (Audit Log — 40 thao tác gần nhất)</h3>
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th>Thời gian</th><th>Nhân viên</th><th>Hành động</th><th>Bảng / ID</th><th>IP</th></tr></thead>
              <tbody id="auditBody"></tbody>
            </table>
          </div>
        </div>
      </section>
      <?php endif; ?>
    </main>
  </div>
</div>

<!-- Nút Ghi Âm 1 Chạm Nổi Bật -->
<button class="voice-fab" id="voiceFab" title="Nhấn để đọc báo cáo giọng nói tiếng Việt" onclick="openVoiceModal()">🎙️</button>

<!-- Thanh điều hướng dưới cùng cho Mobile 1 tay -->
<nav class="bottom-nav">
  <?php
  $bnavCount = 0;
  foreach (AppConfig::MODULES as $idx => $mod):
      if (!$mod['enabled']) continue;
      if (!empty($mod['roles']) && !Security::hasRole($user, $mod['roles'])) continue;
      if (++$bnavCount > 6) break;
  ?>
  <button class="bnav-btn<?= $idx === 0 ? ' active' : '' ?>" data-tab="<?= Security::e($mod['id']) ?>"><span><?= $mod['icon'] ?></span><?= Security::e($mod['short']) ?></button>
  <?php endforeach; ?>
</nav>

<!-- ==================== CÁC MODAL NGHIỆP VỤ ==================== -->
<!-- Modal 1: Voice-to-Action & Thẻ Xác Nhận 2 Giây -->
<div class="modal-backdrop" id="voiceModal">
  <div class="modal-box">
    <div class="card-head">
      <h3 class="card-title">🎙️ Voice-to-Action (Tiếng Việt)</h3>
      <button class="btn btn-sm" onclick="closeModal('voiceModal')">Đóng</button>
    </div>
    <p style="margin:0 0 10px;font-size:13px;color:var(--text-muted);">
      Ví dụ: <em>"Phòng 3, bệnh nhân Võ Minh Đức, huyết áp 130/80, mạch 80, nhiệt độ 36.8, ăn hết suất, đã uống thuốc"</em>
    </p>
    <div style="display:flex;gap:8px;margin-bottom:10px;">
      <button class="btn btn-primary" id="btnStartRec" style="flex:1;" onclick="toggleSpeechRecognition()">🎙️ Bắt đầu nói</button>
      <button class="btn btn-soft" onclick="parseVoiceText()">✨ Phân tích</button>
    </div>
    <textarea class="input" id="voiceTranscript" rows="3" placeholder="Nội dung giọng nói sẽ hiện ở đây (có thể gõ hoặc dùng micro bàn phím điện thoại)..."></textarea>

    <!-- Thẻ xác nhận nhanh 2 giây -->
    <div id="voiceConfirmCard" style="display:none;margin-top:14px;padding:14px;border-radius:14px;border:2px solid var(--primary);background:var(--surface-2);">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <strong>⚡ Thẻ xác nhận nhanh (Kiểm tra & lưu trong 2 giây)</strong>
        <span id="vcAlertBadge" class="pill pill-green">Ổn định</span>
      </div>
      <div class="grid-2" style="margin-bottom:8px;">
        <div>
          <label class="lbl">Loại ghi nhận</label>
          <select class="input" id="vcIntent">
            <option value="vital">Ghi Sinh hiệu & Chăm sóc (1.5.1.1)</option>
            <option value="adhoc">Tạo Công việc phát sinh (1.3.1)</option>
            <option value="chat">Gửi ghi chú phòng (1.6.1.2)</option>
          </select>
        </div>
        <div><label class="lbl">Phòng</label><select class="input" id="vcRoom"></select></div>
      </div>
      <div style="margin-bottom:8px;">
        <label class="lbl">Bệnh nhân khớp trong SQLite</label>
        <select class="input" id="vcPatient"></select>
      </div>
      <div id="vcVitalFields" class="grid-3" style="margin-bottom:8px;">
        <div><label class="lbl">HA Tâm thu</label><input type="number" class="input" id="vcBpSys"></div>
        <div><label class="lbl">HA Tâm trương</label><input type="number" class="input" id="vcBpDia"></div>
        <div><label class="lbl">Mạch (l/p)</label><input type="number" class="input" id="vcPulse"></div>
        <div><label class="lbl">Nhiệt độ (°C)</label><input type="number" step="0.1" class="input" id="vcTemp"></div>
        <div><label class="lbl">SpO2 (%)</label><input type="number" class="input" id="vcSpo2"></div>
        <div><label class="lbl">Ăn uống</label><input class="input" id="vcEating"></div>
      </div>
      <div id="vcAdhocFields" style="display:none;margin-bottom:8px;">
        <label class="lbl">Mức độ ưu tiên</label>
        <select class="input" id="vcPriority">
          <option value="thuong">Thường</option>
          <option value="gap">Gấp</option>
          <option value="khan_cap">Khẩn cấp (Cảnh báo ĐỎ)</option>
        </select>
      </div>
      <div style="margin-bottom:12px;">
        <label class="lbl">Ghi chú / Nội dung</label>
        <input class="input" id="vcNote">
      </div>
      <button class="btn btn-primary" style="width:100%;min-height:48px;font-size:16px;" onclick="confirmVoiceSave()">✅ XÁC NHẬN LƯU VÀO SQLITE</button>
    </div>
  </div>
</div>

<!-- Modal 2: Thêm/Sửa Bệnh nhân -->
<div class="modal-backdrop" id="patientModal">
  <div class="modal-box">
    <div class="card-head">
      <h3 class="card-title" id="patModalTitle">Hồ sơ Bệnh nhân</h3>
      <button class="btn btn-sm" onclick="closeModal('patientModal')">Đóng</button>
    </div>
    <form id="patientForm">
      <input type="hidden" id="pfId">
      <div class="grid-2" style="margin-bottom:10px;">
        <div><label class="lbl">Mã Bệnh nhân *</label><input class="input" id="pfCode" required placeholder="VD: BN015"></div>
        <div><label class="lbl">Họ và Tên *</label><input class="input" id="pfName" required></div>
      </div>
      <div class="grid-3" style="margin-bottom:10px;">
        <div><label class="lbl">Năm sinh</label><input type="number" class="input" id="pfBirth" placeholder="1960"></div>
        <div><label class="lbl">Giới tính</label><select class="input" id="pfGender"><option>Nam</option><option>Nữ</option></select></div>
        <div><label class="lbl">Trạng thái</label><select class="input" id="pfStatus"><option value="active">Đang điều trị</option><option value="discharged">Xuất viện</option><option value="archived">Lưu trữ</option></select></div>
      </div>
      <div class="grid-2" style="margin-bottom:10px;">
        <div><label class="lbl">Phòng chức năng</label><select class="input" id="pfRoom"></select></div>
        <div><label class="lbl">Số giường</label><input class="input" id="pfBed" placeholder="VD: G03"></div>
      </div>
      <div style="margin-bottom:10px;"><label class="lbl">Tiền sử bệnh</label><textarea class="input" id="pfHistory" rows="2"></textarea></div>
      <div style="margin-bottom:10px;"><label class="lbl">Chế độ chăm sóc đặc biệt</label><textarea class="input" id="pfSpecial" rows="2"></textarea></div>
      <div style="margin-bottom:14px;"><label class="lbl">Thông tin người nhà (Tên & SĐT)</label><input class="input" id="pfFamily"></div>
      <button class="btn btn-primary" type="submit" style="width:100%;">Lưu Hồ sơ Bệnh nhân</button>
    </form>
  </div>
</div>

<!-- Modal 3: Theo dõi 24/24 & Biểu đồ xu hướng sinh hiệu Bệnh nhân -->
<div class="modal-backdrop" id="vitalModal">
  <div class="modal-box" style="max-width:760px;">
    <div class="card-head">
      <h3 class="card-title" id="vitalModalTitle">Theo dõi 24/24</h3>
      <button class="btn btn-sm" onclick="closeModal('vitalModal')">Đóng</button>
    </div>
    <form id="vitalForm" style="padding:12px;border-radius:12px;background:var(--surface-2);margin-bottom:14px;">
      <input type="hidden" id="vfPatientId">
      <input type="hidden" id="vfRoomId">
      <div class="grid-3" style="margin-bottom:8px;">
        <div><label class="lbl">Huyết áp tâm thu</label><input type="number" class="input" id="vfSys" placeholder="120"></div>
        <div><label class="lbl">Huyết áp tâm trương</label><input type="number" class="input" id="vfDia" placeholder="80"></div>
        <div><label class="lbl">Mạch (l/p)</label><input type="number" class="input" id="vfPulse" placeholder="78"></div>
        <div><label class="lbl">Nhiệt độ (°C)</label><input type="number" step="0.1" class="input" id="vfTemp" placeholder="36.6"></div>
        <div><label class="lbl">Nhịp thở (l/p)</label><input type="number" class="input" id="vfResp" placeholder="18"></div>
        <div><label class="lbl">SpO2 (%)</label><input type="number" class="input" id="vfSpo2" placeholder="98"></div>
      </div>
      <div class="grid-3" style="margin-bottom:8px;">
        <div>
          <label class="lbl">Ăn uống</label>
          <select class="input" id="vfEating"><option>Ăn hết suất</option><option>Ăn 1/2 suất</option><option>Ăn ít</option><option>Bỏ ăn / Không ăn</option><option>Ăn qua sonde</option></select>
        </div>
        <div><label class="lbl">Giấc ngủ (giờ)</label><input type="number" step="0.5" class="input" id="vfSleepH" placeholder="7"></div>
        <div>
          <label class="lbl">Chất lượng ngủ</label>
          <select class="input" id="vfSleepQ"><option>Ngủ ngon</option><option>Ngủ chập chờn</option><option>Khó ngủ / Mất ngủ</option></select>
        </div>
      </div>
      <div style="margin-bottom:10px;"><label class="lbl">Diễn biến & Ghi chú lâm sàng</label><input class="input" id="vfNote" placeholder="Đã uống thuốc, thở đều..."></div>
      <button class="btn btn-primary btn-sm" type="submit">💾 Lưu Sinh hiệu (Tự kiểm tra cảnh báo đỏ)</button>
    </form>
    <div id="vitalTrendChart" style="margin-bottom:12px;"></div>
    <div id="vitalHistoryList" style="font-size:13px;"></div>
  </div>
</div>

<!-- Modal 4: Tạo Công việc phát sinh -->
<div class="modal-backdrop" id="adhocModal">
  <div class="modal-box">
    <div class="card-head">
      <h3 class="card-title">⚡ Ghi nhận Công việc Phát sinh</h3>
      <button class="btn btn-sm" onclick="closeModal('adhocModal')">Đóng</button>
    </div>
    <form id="adhocForm">
      <div class="grid-2" style="margin-bottom:10px;">
        <div><label class="lbl">Phòng *</label><select class="input" id="afRoom"></select></div>
        <div><label class="lbl">Ca trực</label><select class="input" id="afShift"><option value="sang">Ca Sáng</option><option value="chieu">Ca Chiều</option><option value="toi">Ca Tối</option></select></div>
      </div>
      <div class="grid-2" style="margin-bottom:10px;">
        <div><label class="lbl">Mức độ ưu tiên</label><select class="input" id="afPrio"><option value="thuong">Thường</option><option value="gap">Gấp</option><option value="khan_cap">Khẩn cấp (Cảnh báo ĐỎ)</option></select></div>
        <div><label class="lbl">Gán người xử lý</label><select class="input" id="afAssign"></select></div>
      </div>
      <div style="margin-bottom:10px;"><label class="lbl">Liên quan bệnh nhân (tùy chọn)</label><select class="input" id="afPatient"><option value="">-- Không chọn --</option></select></div>
      <div style="margin-bottom:10px;"><label class="lbl">Tiêu đề sự cố / đầu việc *</label><input class="input" id="afTitle" required></div>
      <div style="margin-bottom:14px;"><label class="lbl">Mô tả chi tiết</label><textarea class="input" id="afDesc" rows="2"></textarea></div>
      <button class="btn btn-primary" type="submit" style="width:100%;">Tạo & Đẩy lên Dashboard</button>
    </form>
  </div>
</div>

<!-- Modal 5: Lập Biên bản Bàn giao ca -->
<div class="modal-backdrop" id="handoverModal">
  <div class="modal-box">
    <div class="card-head">
      <h3 class="card-title">🔄 Lập Biên bản Bàn giao Ca</h3>
      <button class="btn btn-sm" onclick="closeModal('handoverModal')">Đóng</button>
    </div>
    <form id="handoverForm">
      <div class="grid-2" style="margin-bottom:10px;">
        <div><label class="lbl">Ngày bàn giao</label><input type="date" class="input" id="hfDate" required></div>
        <div><label class="lbl">Ca bàn giao</label><select class="input" id="hfShift"><option value="sang">Bàn giao Ca Sáng</option><option value="toi">Bàn giao Ca Tối</option></select></div>
      </div>
      <div id="handoverPreviewBox" style="padding:12px;border-radius:12px;background:var(--surface-2);font-size:13px;margin-bottom:10px;">Đang tổng hợp dữ liệu tự động...</div>
      <div style="margin-bottom:12px;"><label class="lbl">Dặn dò bổ sung cho ca tiếp theo</label><textarea class="input" id="hfNotes" rows="3" placeholder="Ghi chú đặc biệt cần lưu ý..."></textarea></div>
      <button class="btn btn-primary" type="submit" style="width:100%;">Ký Giao ca (Chờ người nhận xác nhận)</button>
    </form>
  </div>
</div>

<!-- Modal 6: Thêm mẫu Công việc hàng ngày -->
<div class="modal-backdrop" id="dailyTplModal">
  <div class="modal-box">
    <div class="card-head">
      <h3 class="card-title">+ Thêm Mẫu Công việc Định kỳ</h3>
      <button class="btn btn-sm" onclick="closeModal('dailyTplModal')">Đóng</button>
    </div>
    <form id="dailyTplForm">
      <div class="grid-2" style="margin-bottom:10px;">
        <div><label class="lbl">Ca trực</label><select class="input" id="dtfShift"><option value="sang">Ca Sáng</option><option value="chieu">Ca Chiều</option><option value="toi">Ca Tối</option></select></div>
        <div><label class="lbl">Áp dụng cho phòng</label><select class="input" id="dtfRoom"><option value="">Tất cả 7 phòng</option></select></div>
      </div>
      <div style="margin-bottom:12px;"><label class="lbl">Nội dung công việc *</label><input class="input" id="dtfTitle" required placeholder="VD: Kiểm tra nhiệt độ tủ thuốc phòng trực"></div>
      <button class="btn btn-primary" type="submit" style="width:100%;">Lưu mẫu công việc</button>
    </form>
  </div>
</div>

<script>
// ============================================================================
// FRONTEND ENGINE THUẦN VANILLA JS — ZERO CDN, PWA-READY, OFFLINE SVG CHARTS
// ============================================================================
const APP_URL = <?= json_encode(basename(__FILE__)) ?>;
const CSRF_TOKEN = <?= json_encode($csrf) ?>;
const CURRENT_USER = <?= json_encode($user, JSON_UNESCAPED_UNICODE) ?>;

const STATE = {
  rooms: [],
  staff: [],
  patientsCache: [],
  activeTab: 'dashboard',
  sse: null,
  pollTimer: null,
  recognition: null,
  isRecording: false,
  isCliServer: false,
};

// --- TIỆN ÍCH CHUNG ---
function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));
}
function showToast(msg, isErr = false) {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.style.background = isErr ? '#b91c1c' : '#0f766e';
  el.style.display = 'block';
  clearTimeout(el._t);
  el._t = setTimeout(() => { el.style.display = 'none'; }, 3200);
}
async function apiGet(op, params = {}) {
  const qs = new URLSearchParams({ action: 'api', op, ...params }).toString();
  const r = await fetch(`${APP_URL}?${qs}`);
  const j = await r.json();
  if (!j.ok) throw new Error(j.error || 'Lỗi tải dữ liệu');
  return j.data;
}
async function apiPost(op, payload = {}) {
  const r = await fetch(`${APP_URL}?action=api&op=${encodeURIComponent(op)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    body: JSON.stringify(payload),
  });
  const j = await r.json();
  if (!j.ok) throw new Error(j.error || 'Lỗi xử lý');
  return j;
}
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function formatBytes(b) {
  if (!b) return '0 KB';
  if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
  return (b / (1024 * 1024)).toFixed(2) + ' MB';
}

// --- DARK / LIGHT MODE (BẢO VỆ MẮT KHI TRỰC ĐÊM) ---
function initTheme() {
  const saved = localStorage.getItem('noitru_theme') || 'light';
  document.documentElement.setAttribute('data-theme', saved);
  document.getElementById('themeToggle').textContent = saved === 'dark' ? '☀️' : '🌙';
  document.getElementById('themeToggle').addEventListener('click', () => {
    const cur = document.documentElement.getAttribute('data-theme');
    const next = cur === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('noitru_theme', next);
    document.getElementById('themeToggle').textContent = next === 'dark' ? '☀️' : '🌙';
  });
}

// --- ĐIỀU HƯỚNG TAB ---
const TAB_TITLES = <?= json_encode(array_column(AppConfig::MODULES, 'title', 'id'), JSON_UNESCAPED_UNICODE) ?>;
function switchTab(tab) {
  STATE.activeTab = tab;
  document.querySelectorAll('.section-view').forEach(s => s.classList.remove('active'));
  const target = document.getElementById('tab-' + tab);
  if (target) target.classList.add('active');
  document.querySelectorAll('[data-tab]').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.getElementById('pageTitle').textContent = TAB_TITLES[tab] || 'Hệ thống Nội trú';

  if (tab === 'dashboard') loadDashboard();
  else if (tab === 'patients') loadPatients();
  else if (tab === 'daily') loadDailyTasks();
  else if (tab === 'adhoc') loadAdhocTasks();
  else if (tab === 'handover') loadHandovers();
  else if (tab === 'chat') loadChat();
  else if (tab === 'reports') loadReports();
  else if (tab === 'admin') loadSqliteAdmin();
}

// --- DASHBOARD 7 PHÒNG REAL-TIME (SSE + SMART POLLING) ---
let LAST_DASH_DATA = null;
let ROOM_FILTER = 'all';

function setRoomFilter(filter) {
  ROOM_FILTER = filter;
  document.querySelectorAll('.rt-pill[data-rfilter]').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.rfilter === filter);
  });
  if (LAST_DASH_DATA) renderDashboard(LAST_DASH_DATA);
}

function updateLivePulseClock() {
  const el = document.getElementById('rtClockBadge');
  if (!el) return;
  const now = new Date();
  el.textContent = now.toLocaleTimeString('vi-VN', { hour12: false }) + ' • ' + now.toLocaleDateString('vi-VN');
}
setInterval(updateLivePulseClock, 1000);
updateLivePulseClock();

function renderDashboard(data) {
  LAST_DASH_DATA = data;
  document.getElementById('liveClock').textContent = `${data.shift_label} • Đồng bộ lúc ${data.server_time}`;
  const shiftEl = document.getElementById('rtShiftBadge');
  if (shiftEl) shiftEl.textContent = `${data.shift_label}${data.shift_time ? ' (' + data.shift_time + ')' : ''}`;
  const staffEl = document.getElementById('rtStaffBadge');
  if (staffEl && data.active_staff_count !== undefined) staffEl.textContent = `• ${data.active_staff_count} nhân sự hoạt động`;

  const s = data.summary;
  document.getElementById('kpiRow').innerHTML = `
    <div class="kpi-card" onclick="setRoomFilter('all')"><div class="kpi-lbl">Bệnh nhân nội trú</div><div class="kpi-val">${s.active_patients}</div></div>
    <div class="kpi-card" style="border-color:var(--red-bd);" onclick="setRoomFilter('red')"><div class="kpi-lbl">Phòng cảnh báo ĐỎ</div><div class="kpi-val" style="color:var(--red);">${s.rooms_red}</div></div>
    <div class="kpi-card" style="border-color:var(--yellow-bd);" onclick="setRoomFilter('yellow')"><div class="kpi-lbl">Phòng cần chú ý VÀNG</div><div class="kpi-val" style="color:var(--yellow);">${s.rooms_yellow}</div></div>
    <div class="kpi-card" style="border-color:var(--green-bd);" onclick="setRoomFilter('green')"><div class="kpi-lbl">Phòng ổn định XANH</div><div class="kpi-val" style="color:var(--green);">${s.rooms_green}</div></div>
    <div class="kpi-card" onclick="switchTab('adhoc')"><div class="kpi-lbl">Việc phát sinh đang mở</div><div class="kpi-val">${s.open_adhoc}</div></div>
  `;

  const banner = document.getElementById('alertBanner');
  if (data.recent_alerts && data.recent_alerts.length > 0) {
    banner.style.display = 'block';
    document.getElementById('alertBannerList').innerHTML = data.recent_alerts.map(a =>
      `<div style="margin:2px 0;">• <span class="rt-badge" style="background:var(--red);">${esc(a.room_code)}</span> <strong>${esc(a.patient_code)} - ${esc(a.full_name)}:</strong> ${esc(a.abnormal_reasons)} <span style="opacity:.75;">(${esc(a.recorded_at)})</span></div>`
    ).join('');
  } else {
    banner.style.display = 'none';
  }

  const visibleRooms = ROOM_FILTER === 'all' ? data.rooms : data.rooms.filter(r => r.status === ROOM_FILTER);
  document.getElementById('roomsGrid').innerHTML = visibleRooms.length ? visibleRooms.map(r => {
    const pct = r.tasks_total > 0 ? Math.round((r.tasks_done / r.tasks_total) * 100) : 100;
    const pillCls = r.status === 'red' ? 'pill-red' : (r.status === 'yellow' ? 'pill-yellow' : 'pill-green');
    const cleanName = String(r.name || '').replace(/^Phòng\s+\d+\s*[—–-]\s*/i, '');
    return `
      <div class="room-card status-${r.status}" onclick="jumpToRoom(${r.id})">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;">
          <div style="display:flex;align-items:center;gap:7px;min-width:0;">
            <span class="rt-badge">${esc(r.code)}</span>
            <div style="font-weight:800;font-size:14.5px;color:var(--navy);line-height:1.3;" title="${esc(r.name)}">${esc(cleanName)}</div>
          </div>
          <span class="pill ${pillCls}">${esc(r.status_label)}</span>
        </div>
        <div style="display:flex;justify-content:space-between;gap:8px;font-size:12px;color:var(--text-muted);background:var(--surface-2);padding:6px 8px;border-radius:8px;">
          <span>🛏️ <strong>${r.patient_count}</strong>/${r.capacity} BN</span>
          <span>⚡ PS: <strong>${r.adhoc_open}</strong></span>
          <span>🚨 BT: <strong style="color:${r.abnormal_count ? 'var(--red)' : 'inherit'}">${r.abnormal_count}</strong></span>
        </div>
        <div style="font-size:12.5px;font-weight:600;color:${r.status === 'red' ? 'var(--red)' : 'var(--text)'};">${esc(r.alert_summary)}</div>
        <div>
          <div style="display:flex;justify-content:space-between;font-size:11.5px;color:var(--text-muted);margin-bottom:3px;">
            <span>Tiến độ ca trực</span><span style="font-weight:700;color:var(--primary);">${r.tasks_done}/${r.tasks_total} (${pct}%)</span>
          </div>
          <div class="progress-bar"><div class="progress-fill" style="width:${pct}%"></div></div>
        </div>
      </div>
    `;
  }).join('') : `<div class="card" style="grid-column:1/-1;text-align:center;color:var(--text-muted);font-size:13px;">Không có phòng nào ở trạng thái đang lọc.</div>`;

  // Render Luồng Sinh hiệu Real-time
  const vFeed = document.getElementById('rtVitalsFeed');
  if (vFeed) {
    const vitals = data.recent_vitals || [];
    vFeed.innerHTML = vitals.length ? vitals.map(v => {
      const abn = Number(v.is_abnormal) === 1;
      const metrics = [
        v.pulse ? `Mạch ${v.pulse}` : '',
        v.temperature ? `${v.temperature}°C` : '',
        v.spo2 ? `SpO2 ${v.spo2}%` : '',
        (v.bp_sys && v.bp_dia) ? `HA ${v.bp_sys}/${v.bp_dia}` : ''
      ].filter(Boolean).join(' • ');
      return `
        <div class="rt-row">
          <div style="min-width:0;">
            <div><span class="rt-badge">${esc(v.room_code)}</span> <strong>${esc(v.patient_code)} - ${esc(v.full_name)}</strong></div>
            <div style="color:${abn ? 'var(--red)' : 'var(--text-muted)'};font-weight:${abn ? '700' : '500'};margin-top:2px;">${esc(metrics || 'Đã ghi nhận')}${abn && v.abnormal_reasons ? ' (' + esc(v.abnormal_reasons) + ')' : ''}</div>
          </div>
          <div style="text-align:right;flex-shrink:0;">
            <span class="pill ${abn ? 'pill-red' : 'pill-green'}" style="font-size:10.5px;padding:2px 7px;">${abn ? 'Bất thường' : 'Ổn định'}</span>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">${esc((v.recorded_at || '').slice(11, 16))}</div>
          </div>
        </div>
      `;
    }).join('') : '<div style="font-size:12.5px;color:var(--text-muted);">Chưa có dữ liệu sinh hiệu hôm nay.</div>';
  }

  // Render Luồng Việc phát sinh & Hoạt động
  const oFeed = document.getElementById('rtOpsFeed');
  if (oFeed) {
    const adhoc = data.recent_adhoc || [];
    const logs = data.recent_logs || [];
    let html = '';
    if (adhoc.length) {
      html += adhoc.map(t => {
        const pCls = t.priority === 'khan_cap' ? 'pill-red' : (t.priority === 'gap' ? 'pill-yellow' : 'pill-neutral');
        const pLbl = t.priority === 'khan_cap' ? 'Khẩn cấp' : (t.priority === 'gap' ? 'Gấp' : 'Thường');
        return `
          <div class="rt-row">
            <div style="min-width:0;">
              <div><span class="rt-badge">${esc(t.room_code)}</span> <strong>${esc(t.title)}</strong></div>
              <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px;">Phụ trách: ${esc(t.assignee_name)}${t.patient_name ? ' • BN: ' + esc(t.patient_name) : ''}</div>
            </div>
            <span class="pill ${pCls}" style="font-size:10.5px;padding:2px 7px;">${pLbl}</span>
          </div>
        `;
      }).join('');
    }
    if (logs.length) {
      html += `<div style="margin-top:8px;padding-top:6px;border-top:1px solid var(--border);font-size:11.5px;color:var(--text-muted);">` +
        logs.slice(0, 3).map(l => `<div style="margin:2px 0;">• <strong>${esc(l.actor_name)}</strong>: ${esc(l.action)} (${esc(l.table_name)}) <span style="opacity:.75;">${esc((l.created_at || '').slice(11, 16))}</span></div>`).join('') +
        `</div>`;
    }
    oFeed.innerHTML = html || '<div style="font-size:12.5px;color:var(--text-muted);">Chưa có việc phát sinh đang mở.</div>';
  }
}

async function loadDashboard(manual = false) {
  try {
    const data = await apiGet('dashboard');
    renderDashboard(data);
    if (manual) showToast('Đã đồng bộ dữ liệu thời gian thực.');
  } catch (e) { console.error(e); }
}

function startRealtimeDashboard() {
  if (!STATE.isCliServer && typeof EventSource !== 'undefined') {
    STATE.sse = new EventSource(`${APP_URL}?action=sse`);
    STATE.sse.addEventListener('dashboard', ev => {
      if (STATE.activeTab === 'dashboard') {
        renderDashboard(JSON.parse(ev.data));
      }
    });
    STATE.sse.onerror = () => {
      STATE.sse.close();
      if (!STATE.pollTimer) STATE.pollTimer = setInterval(() => { if (STATE.activeTab === 'dashboard') loadDashboard(); }, 6000);
    };
  } else {
    // Smart Polling tự thích ứng cho PHP built-in server đơn luồng
    STATE.pollTimer = setInterval(() => { if (STATE.activeTab === 'dashboard') loadDashboard(); }, 5000);
  }
}

function jumpToRoom(roomId) {
  document.getElementById('patRoomFilter').value = String(roomId);
  switchTab('patients');
}

// --- MODULE 1.5: BỆNH NHÂN & THEO DÕI SINH HIỆU 24/24 ---
async function loadPatients() {
  const q = document.getElementById('patSearch').value.trim();
  const room_id = document.getElementById('patRoomFilter').value;
  const status = document.getElementById('patStatusFilter').value;
  const rows = await apiGet('patients_list', { q, room_id, status });
  STATE.patientsCache = rows;
  populatePatientSelects(rows);

  const tb = document.getElementById('patientsBody');
  if (!rows.length) {
    tb.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);">Không tìm thấy hồ sơ bệnh nhân phù hợp.</td></tr>';
    return;
  }
  tb.innerHTML = rows.map(p => `
    <tr>
      <td>
        <strong>${esc(p.patient_code)}</strong> — ${esc(p.full_name)}
        <div style="font-size:12px;color:var(--text-muted);">${esc(p.gender)} • SN ${esc(p.birth_year || '-')} • ${esc(p.family_contact || '')}</div>
      </td>
      <td><span class="pill pill-neutral">${esc(p.room_code)} / ${esc(p.bed_number || '-')}</span></td>
      <td style="max-width:240px;font-size:13px;">${esc(p.special_care || p.medical_history || '-')}</td>
      <td>
        ${p.latest_bp ? `<span class="pill ${p.latest_abnormal == 1 ? 'pill-red' : 'pill-green'}">HA ${esc(p.latest_bp)}</span>` : '<span class="pill pill-neutral">Chưa đo</span>'}
        <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px;">${esc(p.latest_vital_at || '')}</div>
      </td>
      <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
          <button class="btn btn-primary btn-sm" onclick="openVitalModal(${p.id}, ${p.room_id}, '${esc(p.patient_code)} - ${esc(p.full_name)}')">❤️ 24/24</button>
          <button class="btn btn-sm" onclick='openPatientModal(${JSON.stringify(p)})'>Sửa</button>
        </div>
      </td>
    </tr>
  `).join('');
}

function populatePatientSelects(rows) {
  const active = rows.filter(r => r.status === 'active');
  const opts = active.map(p => `<option value="${p.id}" data-room="${p.room_id}">[${esc(p.room_code)}] ${esc(p.patient_code)} - ${esc(p.full_name)}</option>`).join('');
  ['vcPatient', 'afPatient', 'chatTagPatient'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    const prev = el.value;
    el.innerHTML = `<option value="">-- Chọn bệnh nhân --</option>${opts}`;
    if (prev) el.value = prev;
  });
}

function openPatientModal(p = null) {
  document.getElementById('patModalTitle').textContent = p ? `Cập nhật: ${p.patient_code}` : 'Thêm Bệnh nhân Nội trú mới';
  document.getElementById('pfId').value = p ? p.id : '';
  document.getElementById('pfCode').value = p ? p.patient_code : '';
  document.getElementById('pfName').value = p ? p.full_name : '';
  document.getElementById('pfBirth').value = p ? (p.birth_year || '') : '';
  document.getElementById('pfGender').value = p ? p.gender : 'Nam';
  document.getElementById('pfRoom').value = p ? p.room_id : (STATE.rooms[0]?.id || 1);
  document.getElementById('pfBed').value = p ? (p.bed_number || '') : '';
  document.getElementById('pfHistory').value = p ? (p.medical_history || '') : '';
  document.getElementById('pfSpecial').value = p ? (p.special_care || '') : '';
  document.getElementById('pfFamily').value = p ? (p.family_contact || '') : '';
  document.getElementById('pfStatus').value = p ? p.status : 'active';
  openModal('patientModal');
}

document.getElementById('patientForm').addEventListener('submit', async e => {
  e.preventDefault();
  try {
    await apiPost('patient_save', {
      id: document.getElementById('pfId').value,
      patient_code: document.getElementById('pfCode').value,
      full_name: document.getElementById('pfName').value,
      birth_year: document.getElementById('pfBirth').value,
      gender: document.getElementById('pfGender').value,
      room_id: document.getElementById('pfRoom').value,
      bed_number: document.getElementById('pfBed').value,
      medical_history: document.getElementById('pfHistory').value,
      special_care: document.getElementById('pfSpecial').value,
      family_contact: document.getElementById('pfFamily').value,
      status: document.getElementById('pfStatus').value,
    });
    closeModal('patientModal');
    showToast('Đã lưu hồ sơ bệnh nhân vào SQLite.');
    loadPatients();
    loadDashboard();
  } catch (err) { showToast(err.message, true); }
});

async function openVitalModal(patientId, roomId, title) {
  document.getElementById('vitalModalTitle').textContent = 'Theo dõi 24/24: ' + title;
  document.getElementById('vfPatientId').value = patientId;
  document.getElementById('vfRoomId').value = roomId;
  document.getElementById('vitalForm').reset();
  openModal('vitalModal');
  await refreshVitalHistory(patientId);
}

async function refreshVitalHistory(patientId) {
  const logs = await apiGet('patient_vitals', { patient_id: patientId });
  renderVitalTrendSvg(logs);
  document.getElementById('vitalHistoryList').innerHTML = logs.length
    ? logs.map(v => `
      <div style="padding:10px;border:1px solid ${v.is_abnormal == 1 ? 'var(--red-bd)' : 'var(--border)'};background:${v.is_abnormal == 1 ? 'var(--red-bg)' : 'var(--surface-2)'};border-radius:12px;margin-bottom:8px;">
        <div style="display:flex;justify-content:space-between;font-weight:700;">
          <span>${esc(v.recorded_at)} • ${esc(v.recorder_name)}</span>
          ${v.is_abnormal == 1 ? `<span class="pill pill-red">🚨 ${esc(v.abnormal_reasons)}</span>` : '<span class="pill pill-green">Ổn định</span>'}
        </div>
        <div style="margin-top:4px;">
          HA: <strong>${v.bp_sys ?? '-'}/${v.bp_dia ?? '-'}</strong> • Mạch: <strong>${v.pulse ?? '-'}</strong> • Nhiệt: <strong>${v.temperature ?? '-'}°C</strong> • Thở: <strong>${v.resp_rate ?? '-'}</strong> • SpO2: <strong>${v.spo2 ?? '-'}%</strong>
        </div>
        <div style="font-size:12.5px;color:var(--text-muted);margin-top:3px;">
          Ăn: ${esc(v.eating_status)} • Ngủ: ${v.sleep_hours ?? '-'}h (${esc(v.sleep_quality)}) • ${esc(v.clinical_note)}
        </div>
      </div>
    `).join('')
    : '<div style="color:var(--text-muted);">Chưa có dữ liệu theo dõi sinh hiệu.</div>';
}

// Biểu đồ xu hướng SVG thuần (100% offline, vẽ Huyết áp tâm thu & Mạch theo thời gian)
function renderVitalTrendSvg(logs) {
  const box = document.getElementById('vitalTrendChart');
  const pts = logs.slice().reverse().filter(l => l.bp_sys || l.pulse);
  if (pts.length < 2) { box.innerHTML = ''; return; }
  const W = 680, H = 160, pad = 28;
  const maxV = Math.max(160, ...pts.map(p => Math.max(p.bp_sys || 0, p.pulse || 0)));
  const minV = 40;
  const xStep = (W - pad * 2) / (pts.length - 1);
  const yMap = v => H - pad - ((v - minV) / (maxV - minV)) * (H - pad * 2);

  const sysPts = pts.map((p, i) => p.bp_sys ? `${pad + i * xStep},${yMap(p.bp_sys)}` : null).filter(Boolean).join(' ');
  const pulsePts = pts.map((p, i) => p.pulse ? `${pad + i * xStep},${yMap(p.pulse)}` : null).filter(Boolean).join(' ');

  box.innerHTML = `
    <div style="font-size:12.5px;font-weight:700;margin-bottom:4px;">Xu hướng Sinh hiệu gần đây (<span style="color:#dc2626;">● HA Tâm thu</span> | <span style="color:#0f766e;">● Mạch</span>)</div>
    <svg viewBox="0 0 ${W} ${H}" style="width:100%;height:auto;background:var(--surface-2);border:1px solid var(--border);border-radius:12px;">
      <line x1="${pad}" y1="${yMap(140)}" x2="${W - pad}" y2="${yMap(140)}" stroke="#f87171" stroke-dasharray="4 4" stroke-width="1"/>
      <polyline fill="none" stroke="#dc2626" stroke-width="2.5" points="${sysPts}"/>
      <polyline fill="none" stroke="#0f766e" stroke-width="2.5" points="${pulsePts}"/>
      ${pts.map((p, i) => `<text x="${pad + i * xStep}" y="${H - 6}" text-anchor="middle" font-size="10" fill="#64748b">${esc((p.recorded_at || '').slice(11,16))}</text>`).join('')}
    </svg>
  `;
}

document.getElementById('vitalForm').addEventListener('submit', async e => {
  e.preventDefault();
  try {
    const pid = document.getElementById('vfPatientId').value;
    const res = await apiPost('vital_save', {
      patient_id: pid,
      room_id: document.getElementById('vfRoomId').value,
      bp_sys: document.getElementById('vfSys').value,
      bp_dia: document.getElementById('vfDia').value,
      pulse: document.getElementById('vfPulse').value,
      temperature: document.getElementById('vfTemp').value,
      resp_rate: document.getElementById('vfResp').value,
      spo2: document.getElementById('vfSpo2').value,
      eating_status: document.getElementById('vfEating').value,
      sleep_hours: document.getElementById('vfSleepH').value,
      sleep_quality: document.getElementById('vfSleepQ').value,
      clinical_note: document.getElementById('vfNote').value,
      med_taken: 1,
    });
    showToast(res.is_abnormal ? '🚨 CẢNH BÁO ĐỎ: ' + res.abnormal_reasons : 'Đã lưu chỉ số sinh hiệu an toàn.', !!res.is_abnormal);
    await refreshVitalHistory(pid);
    loadDashboard();
    loadPatients();
  } catch (err) { showToast(err.message, true); }
});

// --- VOICE-TO-ACTION TIẾNG VIỆT + THẺ XÁC NHẬN 2 GIÂY ---
function openVoiceModal() {
  document.getElementById('voiceConfirmCard').style.display = 'none';
  openModal('voiceModal');
}

function toggleSpeechRecognition() {
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR) {
    showToast('Trình duyệt không hỗ trợ Web Speech API. Bạn hãy bấm nút Micro trên bàn phím điện thoại hoặc gõ trực tiếp rồi bấm Phân tích.', true);
    return;
  }
  if (STATE.isRecording && STATE.recognition) {
    STATE.recognition.stop();
    return;
  }
  const rec = new SR();
  rec.lang = 'vi-VN';
  rec.interimResults = true;
  rec.continuous = false;
  STATE.recognition = rec;
  STATE.isRecording = true;
  document.getElementById('btnStartRec').textContent = '⏹️ Đang nghe... (Nhấn để dừng)';
  document.getElementById('voiceFab').classList.add('recording');

  rec.onresult = ev => {
    let txt = '';
    for (let i = 0; i < ev.results.length; i++) txt += ev.results[i][0].transcript;
    document.getElementById('voiceTranscript').value = txt;
  };
  rec.onend = () => {
    STATE.isRecording = false;
    document.getElementById('btnStartRec').textContent = '🎙️ Bắt đầu nói';
    document.getElementById('voiceFab').classList.remove('recording');
    if (document.getElementById('voiceTranscript').value.trim()) parseVoiceText();
  };
  rec.onerror = err => {
    STATE.isRecording = false;
    document.getElementById('btnStartRec').textContent = '🎙️ Bắt đầu nói';
    document.getElementById('voiceFab').classList.remove('recording');
    showToast('Lỗi micro: ' + err.error + ' (Trên điện thoại cần truy cập qua HTTPS)', true);
  };
  rec.start();
}

async function parseVoiceText() {
  const text = document.getElementById('voiceTranscript').value.trim();
  if (!text) return showToast('Vui lòng nói hoặc nhập nội dung báo cáo.', true);
  try {
    const res = await apiPost('voice_parse', { text });
    const d = res.data;
    document.getElementById('vcIntent').value = d.intent || 'vital';
    document.getElementById('vcRoom').value = d.room_id || 1;
    document.getElementById('vcPatient').value = d.patient_id || '';
    document.getElementById('vcBpSys').value = d.bp_sys ?? '';
    document.getElementById('vcBpDia').value = d.bp_dia ?? '';
    document.getElementById('vcPulse').value = d.pulse ?? '';
    document.getElementById('vcTemp').value = d.temperature ?? '';
    document.getElementById('vcSpo2').value = d.spo2 ?? '';
    document.getElementById('vcEating').value = d.eating_status || 'Ăn hết suất';
    document.getElementById('vcPriority').value = d.priority || 'thuong';
    document.getElementById('vcNote').value = d.clinical_note || text;

    const badge = document.getElementById('vcAlertBadge');
    if (d.is_abnormal) {
      badge.className = 'pill pill-red';
      badge.textContent = '🚨 ' + d.abnormal_reasons;
    } else {
      badge.className = 'pill pill-green';
      badge.textContent = 'Ổn định (' + d.adapter_used + ')';
    }
    toggleVoiceIntentFields();
    document.getElementById('voiceConfirmCard').style.display = 'block';
  } catch (err) { showToast(err.message, true); }
}

function toggleVoiceIntentFields() {
  const intent = document.getElementById('vcIntent').value;
  document.getElementById('vcVitalFields').style.display = intent === 'vital' ? 'grid' : 'none';
  document.getElementById('vcAdhocFields').style.display = intent === 'adhoc' ? 'block' : 'none';
}
document.getElementById('vcIntent').addEventListener('change', toggleVoiceIntentFields);

async function confirmVoiceSave() {
  const intent = document.getElementById('vcIntent').value;
  const room_id = document.getElementById('vcRoom').value;
  const patient_id = document.getElementById('vcPatient').value;
  const raw_voice_text = document.getElementById('voiceTranscript').value.trim();
  const note = document.getElementById('vcNote').value.trim();

  try {
    if (intent === 'vital') {
      if (!patient_id) return showToast('Vui lòng chọn đúng Bệnh nhân trên thẻ xác nhận.', true);
      await apiPost('vital_save', {
        patient_id, room_id,
        bp_sys: document.getElementById('vcBpSys').value,
        bp_dia: document.getElementById('vcBpDia').value,
        pulse: document.getElementById('vcPulse').value,
        temperature: document.getElementById('vcTemp').value,
        spo2: document.getElementById('vcSpo2').value,
        eating_status: document.getElementById('vcEating').value,
        clinical_note: note, raw_voice_text, med_taken: 1,
      });
    } else if (intent === 'adhoc') {
      await apiPost('adhoc_save', {
        room_id, patient_id: patient_id || null,
        priority: document.getElementById('vcPriority').value,
        title: note || raw_voice_text, description: raw_voice_text,
      });
    } else {
      await apiPost('chat_send', { channel_type: 'phong', room_id, patient_id: patient_id || null, message: note || raw_voice_text });
    }
    closeModal('voiceModal');
    document.getElementById('voiceTranscript').value = '';
    showToast('✅ Đã xác nhận & lưu báo cáo giọng nói vào SQLite!');
    loadDashboard();
    if (STATE.activeTab === 'adhoc') loadAdhocTasks();
    if (STATE.activeTab === 'chat') loadChat();
  } catch (err) { showToast(err.message, true); }
}

// --- MODULE 1.2.1: CÔNG VIỆC HÀNG NGÀY ---
async function loadDailyTasks() {
  const date = document.getElementById('dailyDate').value;
  const shift = document.getElementById('dailyShift').value;
  const room_id = document.getElementById('dailyRoom').value;
  const rows = await apiGet('daily_tasks_list', { date, shift, room_id });
  const box = document.getElementById('dailyList');
  if (!rows.length) {
    box.innerHTML = '<div style="color:var(--text-muted);">Chưa có đầu việc mẫu cho ca này.</div>';
    return;
  }
  box.innerHTML = rows.map(r => `
    <label style="display:flex;align-items:center;gap:12px;padding:12px;border:1px solid var(--border);border-radius:12px;margin-bottom:8px;background:${r.status === 'done' ? 'var(--green-bg)' : 'var(--surface-2)'};cursor:pointer;">
      <input type="checkbox" style="width:22px;height:22px;accent-color:var(--primary);" ${r.status === 'done' ? 'checked' : ''} onchange="toggleDailyTask(${r.template_id}, this.checked)">
      <div style="flex:1;">
        <div style="font-weight:700;${r.status === 'done' ? 'text-decoration:line-through;opacity:.75;' : ''}">${esc(r.title)}</div>
        <div style="font-size:12px;color:var(--text-muted);">${r.status === 'done' ? `Hoàn thành bởi ${esc(r.completed_by_name)} lúc ${esc(r.completed_at)}` : 'Chưa thực hiện'}</div>
      </div>
    </label>
  `).join('');
}

async function toggleDailyTask(template_id, done) {
  try {
    await apiPost('daily_task_toggle', {
      template_id, done,
      task_date: document.getElementById('dailyDate').value,
      shift_code: document.getElementById('dailyShift').value,
      room_id: document.getElementById('dailyRoom').value,
    });
    loadDailyTasks();
    loadDashboard();
  } catch (err) { showToast(err.message, true); }
}
function openDailyTplModal() { openModal('dailyTplModal'); }
document.getElementById('dailyTplForm').addEventListener('submit', async e => {
  e.preventDefault();
  try {
    await apiPost('daily_template_add', {
      shift_code: document.getElementById('dtfShift').value,
      room_id: document.getElementById('dtfRoom').value || null,
      title: document.getElementById('dtfTitle').value,
    });
    closeModal('dailyTplModal');
    e.target.reset();
    showToast('Đã thêm đầu việc định kỳ mới.');
    loadDailyTasks();
  } catch (err) { showToast(err.message, true); }
});

// --- MODULE 1.3.1: CÔNG VIỆC PHÁT SINH ---
async function loadAdhocTasks() {
  const rows = await apiGet('adhoc_list', {
    shift: document.getElementById('adhocShiftFilter').value,
    room_id: document.getElementById('adhocRoomFilter').value,
    status: document.getElementById('adhocStatusFilter').value,
  });
  const box = document.getElementById('adhocList');
  if (!rows.length) { box.innerHTML = '<div style="color:var(--text-muted);">Không có công việc phát sinh nào.</div>'; return; }
  const prioPill = { khan_cap: ['pill-red','KHẨN CẤP'], gap: ['pill-yellow','GẤP'], thuong: ['pill-neutral','Thường'] };
  const stLabel = { moi: 'Mới', dang_xu_ly: 'Đang xử lý', hoan_thanh: 'Hoàn thành' };
  box.innerHTML = rows.map(a => {
    const [cls, txt] = prioPill[a.priority] || prioPill.thuong;
    return `
      <div style="padding:14px;border:1px solid var(--border);border-radius:14px;margin-bottom:10px;background:var(--surface-2);">
        <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
          <div>
            <span class="pill ${cls}">${txt}</span>
            <span class="pill pill-neutral">${esc(a.room_code)} • Ca ${esc(a.shift_code)}</span>
            <strong style="margin-left:6px;">${esc(a.title)}</strong>
          </div>
          <span class="pill ${a.status === 'hoan_thanh' ? 'pill-green' : 'pill-yellow'}">${stLabel[a.status]}</span>
        </div>
        <div style="font-size:13px;color:var(--text-muted);margin:6px 0;">
          ${esc(a.description)} ${a.patient_name ? `| BN: <strong>${esc(a.patient_code)} - ${esc(a.patient_name)}</strong>` : ''}
          | Phụ trách: <strong>${esc(a.assignee_name || 'Chưa gán')}</strong> • Tạo lúc ${esc(a.created_at)}
        </div>
        ${a.status !== 'hoan_thanh' ? `
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
            ${a.status === 'moi' ? `<button class="btn btn-sm" onclick="updateAdhoc(${a.id}, 'dang_xu_ly')">Nhận xử lý</button>` : ''}
            <button class="btn btn-primary btn-sm" onclick="updateAdhoc(${a.id}, 'hoan_thanh')">✅ Đánh dấu Hoàn thành</button>
            <button class="btn btn-sm btn-soft" onclick="noteBacklog(${a.id})">Ghi lý do tồn đọng</button>
          </div>
        ` : `<div style="font-size:12px;color:var(--green);">Hoàn thành lúc: ${esc(a.completed_at)}</div>`}
        ${a.backlog_reason ? `<div style="font-size:12.5px;color:var(--yellow);margin-top:4px;">Lý do tồn: ${esc(a.backlog_reason)}</div>` : ''}
      </div>
    `;
  }).join('');
}

function openAdhocModal() { openModal('adhocModal'); }
document.getElementById('adhocForm').addEventListener('submit', async e => {
  e.preventDefault();
  try {
    await apiPost('adhoc_save', {
      room_id: document.getElementById('afRoom').value,
      shift_code: document.getElementById('afShift').value,
      priority: document.getElementById('afPrio').value,
      assigned_to: document.getElementById('afAssign').value || null,
      patient_id: document.getElementById('afPatient').value || null,
      title: document.getElementById('afTitle').value,
      description: document.getElementById('afDesc').value,
    });
    closeModal('adhocModal');
    e.target.reset();
    showToast('Đã tạo công việc phát sinh & cập nhật Dashboard.');
    loadAdhocTasks();
    loadDashboard();
  } catch (err) { showToast(err.message, true); }
});

async function updateAdhoc(id, status, backlog_reason = '') {
  try {
    await apiPost('adhoc_update_status', { id, status, backlog_reason });
    showToast('Đã cập nhật trạng thái công việc.');
    loadAdhocTasks();
    loadDashboard();
  } catch (err) { showToast(err.message, true); }
}
function noteBacklog(id) {
  const reason = prompt('Nhập nguyên nhân tồn đọng công việc này:');
  if (reason !== null && reason.trim() !== '') updateAdhoc(id, 'dang_xu_ly', reason.trim());
}

// --- MODULE 1.5.2.1: BÀN GIAO CA SÁNG / TỐI 2 CHIỀU ---
async function openHandoverModal() {
  document.getElementById('hfDate').value = new Date().toISOString().slice(0, 10);
  openModal('handoverModal');
  const snap = await apiGet('handover_preview');
  document.getElementById('handoverPreviewBox').innerHTML = `
    <strong>Tổng hợp tự động tại thời điểm chốt:</strong><br>
    • Tổng BN đang điều trị: <strong>${snap.active_patients}</strong><br>
    • BN có sinh hiệu bất thường (12h qua): <strong style="color:var(--red);">${snap.abnormal_patients.length}</strong><br>
    • Công việc phát sinh chưa xử lý xong: <strong style="color:var(--yellow);">${snap.open_adhoc.length}</strong><br>
    • BN chế độ chăm sóc đặc biệt: <strong>${snap.special_care.length}</strong>
  `;
}
document.getElementById('handoverForm').addEventListener('submit', async e => {
  e.preventDefault();
  try {
    await apiPost('handover_create', {
      handover_date: document.getElementById('hfDate').value,
      shift_code: document.getElementById('hfShift').value,
      notes: document.getElementById('hfNotes').value,
    });
    closeModal('handoverModal');
    e.target.reset();
    showToast('Đã lập biên bản giao ca. Đang chờ người nhận ca xác nhận.');
    loadHandovers();
  } catch (err) { showToast(err.message, true); }
});

async function loadHandovers() {
  const rows = await apiGet('handovers_list');
  const box = document.getElementById('handoverList');
  if (!rows.length) { box.innerHTML = '<div style="color:var(--text-muted);">Chưa có biên bản bàn giao ca nào.</div>'; return; }
  box.innerHTML = rows.map(h => {
    const snap = JSON.parse(h.summary_json || '{}');
    const shiftTxt = h.shift_code === 'sang' ? 'Ca Sáng' : 'Ca Tối';
    return `
      <div style="padding:14px;border:1px solid var(--border);border-radius:14px;margin-bottom:10px;background:var(--surface-2);">
        <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:center;">
          <div>
            <strong>Biên bản ${shiftTxt} — ${esc(h.handover_date)}</strong>
            ${h.is_locked == 1 ? '<span class="pill pill-green">🔒 Đã chốt & khóa</span>' : '<span class="pill pill-yellow">⏳ Chờ nhận ca</span>'}
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            ${h.is_locked == 0 ? `<button class="btn btn-primary btn-sm" onclick="acceptHandover(${h.id})">✍️ Xác nhận Nhận ca & Khóa</button>` : ''}
            <a class="btn btn-sm" target="_blank" href="${APP_URL}?action=export&type=handover&id=${h.id}&format=excel">Excel</a>
            <a class="btn btn-sm" target="_blank" href="${APP_URL}?action=export&type=handover&id=${h.id}&format=word">Word</a>
            <a class="btn btn-sm" target="_blank" href="${APP_URL}?action=export&type=handover&id=${h.id}&format=pdf">PDF</a>
            <a class="btn btn-sm" target="_blank" href="${APP_URL}?action=export&type=handover&id=${h.id}&format=web">Web</a>
          </div>
        </div>
        <div style="font-size:13px;color:var(--text-muted);margin-top:6px;">
          Giao ca: <strong>${esc(h.from_name)}</strong> (${esc(h.handed_over_at)}) |
          Nhận ca: <strong>${esc(h.to_name || 'Chưa xác nhận')}</strong> ${h.accepted_at ? `(${esc(h.accepted_at)})` : ''}<br>
          BN bất thường: <strong>${(snap.abnormal_patients || []).length}</strong> • Việc tồn: <strong>${(snap.open_adhoc || []).length}</strong> • Dặn dò: ${esc(h.notes || 'Không có')}
        </div>
      </div>
    `;
  }).join('');
}

async function acceptHandover(id) {
  if (!confirm('Xác nhận đã kiểm tra và NHẬN CA? Sau khi chốt, biên bản sẽ bị khóa chỉnh sửa và ghi vào Audit Log.')) return;
  try {
    await apiPost('handover_accept', { id });
    showToast('🔒 Đã chốt bàn giao ca thành công.');
    loadHandovers();
  } catch (err) { showToast(err.message, true); }
}

// --- MODULE 1.6.1.2: CHAT NỘI BỘ + FTS5 ---
async function loadChat() {
  const channel = document.getElementById('chatChannel').value;
  document.getElementById('chatRoom').style.display = channel === 'phong' ? 'block' : 'none';
  const rows = await apiGet('chat_list', {
    channel,
    room_id: document.getElementById('chatRoom').value,
    period: document.getElementById('chatPeriod').value,
    q: document.getElementById('chatSearch').value.trim(),
  });
  const box = document.getElementById('chatMessages');
  if (!rows.length) { box.innerHTML = '<div style="color:var(--text-muted);">Chưa có tin nhắn nào trong khoảng thời gian này.</div>'; return; }
  box.innerHTML = rows.map(c => `
    <div style="padding:11px 13px;border-radius:12px;border:1px solid var(--border);background:var(--surface-2);">
      <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:4px;">
        <strong>${esc(c.sender_name)} ${c.room_code ? `[${esc(c.room_code)}]` : '[Toàn viện]'}</strong>
        <span style="color:var(--text-muted);">${esc(c.created_at)}</span>
      </div>
      <div>${esc(c.message)}</div>
      ${c.patient_code ? `<div style="margin-top:5px;"><span class="pill pill-neutral">🏷️ ${esc(c.patient_code)} - ${esc(c.patient_name)}</span></div>` : ''}
    </div>
  `).join('');
}
document.getElementById('chatForm').addEventListener('submit', async e => {
  e.preventDefault();
  try {
    await apiPost('chat_send', {
      channel_type: document.getElementById('chatChannel').value,
      room_id: document.getElementById('chatRoom').value,
      patient_id: document.getElementById('chatTagPatient').value || null,
      message: document.getElementById('chatInput').value,
    });
    document.getElementById('chatInput').value = '';
    loadChat();
  } catch (err) { showToast(err.message, true); }
});

// --- MODULE 1.4: BÁO CÁO TỔNG HỢP & SVG CHARTS OFFLINE ---
async function loadReports() {
  const period = document.getElementById('repPeriod').value;
  const date = document.getElementById('repDate').value;
  const d = await apiGet('reports', { period, date });
  document.getElementById('repKpis').innerHTML = `
    <div class="kpi-card"><div class="kpi-lbl">Kỳ báo cáo</div><div class="kpi-val" style="font-size:17px;">${esc(d.range.label)}</div></div>
    <div class="kpi-card"><div class="kpi-lbl">Việc phát sinh</div><div class="kpi-val">${d.adhoc_stats.total}</div></div>
    <div class="kpi-card"><div class="kpi-lbl">Tỷ lệ đúng hạn</div><div class="kpi-val" style="color:var(--green);">${d.adhoc_stats.ontime_rate}%</div></div>
    <div class="kpi-card"><div class="kpi-lbl">Ca khẩn cấp</div><div class="kpi-val" style="color:var(--red);">${d.adhoc_stats.urgent}</div></div>
    <div class="kpi-card"><div class="kpi-lbl">Lượt đo sinh hiệu (Bất thường)</div><div class="kpi-val">${d.vital_stats.total} <span style="font-size:15px;color:var(--red);">(${d.vital_stats.abnormal})</span></div></div>
  `;

  // Vẽ biểu đồ cột ngang theo 7 phòng (100% offline, không cần CDN Chart.js)
  const maxR = Math.max(1, ...d.by_room.map(r => Number(r.total)));
  document.getElementById('repRoomChart').innerHTML = d.by_room.map(r => `
    <div style="margin-bottom:8px;">
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:3px;">
        <strong>${esc(r.code)}</strong><span>${r.total} sự cố (Xong: ${r.done_cnt} • Khẩn: ${r.urgent_cnt})</span>
      </div>
      <div class="progress-bar"><div class="progress-fill" style="width:${Math.round((r.total / maxR) * 100)}%;background:${r.urgent_cnt > 0 ? 'var(--red)' : 'var(--primary)'};"></div></div>
    </div>
  `).join('');

  document.getElementById('repShiftChart').innerHTML = d.by_shift.length
    ? d.by_shift.map(s => `<span class="pill pill-neutral" style="margin:3px;">Ca ${esc(s.shift_code)}: <strong>${s.total}</strong> (Xong ${s.done_cnt})</span>`).join('')
    : '<div style="font-size:13px;color:var(--text-muted);">Chưa có dữ liệu phát sinh theo ca trong kỳ.</div>';

  document.getElementById('repBacklogList').innerHTML = d.backlogs.length
    ? '<div style="font-weight:700;font-size:13px;margin-bottom:6px;">Nguyên nhân tồn đọng / Việc chưa xong:</div>' + d.backlogs.map(b =>
      `<div style="font-size:12.5px;padding:6px 0;border-bottom:1px solid var(--border);"><strong>[${esc(b.room_code)}]</strong> ${esc(b.title)} — <em style="color:var(--yellow);">${esc(b.backlog_reason || 'Đang xử lý')}</em></div>`
    ).join('')
    : '<div style="font-size:13px;color:var(--green);">Không có tồn đọng trong kỳ này.</div>';
}
function exportReport(format) {
  const period = document.getElementById('repPeriod').value;
  const date = document.getElementById('repDate').value;
  window.open(`${APP_URL}?action=export&type=report&period=${encodeURIComponent(period)}&date=${encodeURIComponent(date)}&format=${format}`, '_blank');
}

// --- MODULE 1.6: QUẢN TRỊ SQLITE, BACKUP & AI ADAPTER ---
async function loadSqliteAdmin() {
  try {
    const d = await apiGet('sqlite_admin');
    document.getElementById('sqliteKpis').innerHTML = `
      <div class="kpi-card"><div class="kpi-lbl">Chế độ SQLite</div><div class="kpi-val" style="font-size:20px;color:var(--green);">${esc(String(d.journal_mode).toUpperCase())}</div></div>
      <div class="kpi-card"><div class="kpi-lbl">Dung lượng DB / WAL</div><div class="kpi-val" style="font-size:18px;">${formatBytes(d.db_size)} / ${formatBytes(d.wal_size)}</div></div>
      <div class="kpi-card"><div class="kpi-lbl">Auto-Backup gần nhất</div><div class="kpi-val" style="font-size:17px;">${esc(d.last_backup)}</div></div>
      <div class="kpi-card"><div class="kpi-lbl">Lần VACUUM gần nhất</div><div class="kpi-val" style="font-size:15px;">${esc(d.last_vacuum)}</div></div>
    `;
    document.getElementById('backupList').innerHTML = '<strong>Danh sách bản sao lưu xoay vòng:</strong>' + (d.backups.length
      ? d.backups.map(b => `<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);"><span>${esc(b.name)} (${formatBytes(b.size)})</span><a href="${APP_URL}?action=download_backup&kind=backup&file=${encodeURIComponent(b.name)}">Tải về</a></div>`).join('')
      : '<div style="color:var(--text-muted);margin-top:4px;">Chưa có bản sao lưu nào.</div>');

    document.getElementById('aiMode').value = d.ai_config.mode || 'local_rule';
    document.getElementById('aiGeminiKey').value = d.ai_config.gemini_api_key || '';
    document.getElementById('aiOllamaUrl').value = d.ai_config.ollama_url || '';
    document.getElementById('aiOllamaModel').value = d.ai_config.ollama_model || '';

    document.getElementById('auditBody').innerHTML = d.audits.map(a => `
      <tr><td>${esc(a.created_at)}</td><td>${esc(a.actor_name || 'System')}</td><td><span class="pill pill-neutral">${esc(a.action)}</span></td><td>${esc(a.table_name)} #${a.record_id ?? '-'}</td><td>${esc(a.ip_address)}</td></tr>
    `).join('');
  } catch (err) { showToast(err.message, true); }
}

async function runVacuum() {
  try {
    const r = await apiPost('sqlite_vacuum');
    showToast(r.message);
    loadSqliteAdmin();
  } catch (err) { showToast(err.message, true); }
}
async function runBackupNow() {
  try {
    const r = await apiPost('sqlite_backup_now');
    showToast('Đã tạo bản sao lưu: ' + r.data.file);
    loadSqliteAdmin();
  } catch (err) { showToast(err.message, true); }
}
async function exportArchive() {
  try {
    const r = await apiPost('sqlite_archive_export', {
      year: document.getElementById('archYear').value,
      month: document.getElementById('archMonth').value || null,
    });
    showToast('Đang tải gói lưu trữ: ' + r.data.file);
    window.location.href = `${APP_URL}?action=download_backup&kind=archive&file=${encodeURIComponent(r.data.file)}`;
  } catch (err) { showToast(err.message, true); }
}
const aiForm = document.getElementById('aiConfigForm');
if (aiForm) {
  aiForm.addEventListener('submit', async e => {
    e.preventDefault();
    try {
      await apiPost('ai_config_save', {
        mode: document.getElementById('aiMode').value,
        gemini_api_key: document.getElementById('aiGeminiKey').value,
        ollama_url: document.getElementById('aiOllamaUrl').value,
        ollama_model: document.getElementById('aiOllamaModel').value,
      });
      showToast('Đã lưu cấu hình AI Adapter.');
    } catch (err) { showToast(err.message, true); }
  });
}

// --- KHỞI ĐỘNG HỆ THỐNG ---
async function initApp() {
  initTheme();
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(`${APP_URL}?action=sw`).catch(() => {});
  }

  document.querySelectorAll('[data-tab]').forEach(btn => {
    btn.addEventListener('click', () => switchTab(btn.dataset.tab));
  });

  const today = new Date().toISOString().slice(0, 10);
  document.getElementById('dailyDate').value = today;
  document.getElementById('repDate').value = today;

  const boot = await apiGet('bootstrap');
  STATE.rooms = boot.rooms;
  STATE.staff = boot.staff;
  STATE.isCliServer = !!boot.is_cli_srv;

  const roomOpts = STATE.rooms.map(r => `<option value="${r.id}">${esc(r.code)} — ${esc(r.name)}</option>`).join('');
  ['dailyRoom', 'pfRoom', 'afRoom', 'vcRoom', 'chatRoom'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = roomOpts;
  });
  ['patRoomFilter', 'adhocRoomFilter'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = `<option value="0">Tất cả 7 phòng</option>${roomOpts}`;
  });
  const dtfRoom = document.getElementById('dtfRoom');
  if (dtfRoom) dtfRoom.innerHTML = `<option value="">Tất cả 7 phòng</option>${roomOpts}`;

  const staffOpts = STATE.staff.map(s => `<option value="${s.id}">${esc(s.full_name)} (${esc(s.shift_group)})</option>`).join('');
  document.getElementById('afAssign').innerHTML = `<option value="">-- Chưa gán --</option>${staffOpts}`;

  // Sự kiện bộ lọc
  ['patSearch', 'patRoomFilter', 'patStatusFilter'].forEach(id => document.getElementById(id).addEventListener('input', loadPatients));
  ['dailyDate', 'dailyShift', 'dailyRoom'].forEach(id => document.getElementById(id).addEventListener('change', loadDailyTasks));
  ['adhocShiftFilter', 'adhocRoomFilter', 'adhocStatusFilter'].forEach(id => document.getElementById(id).addEventListener('change', loadAdhocTasks));
  ['chatChannel', 'chatRoom', 'chatPeriod', 'chatSearch'].forEach(id => document.getElementById(id).addEventListener('input', loadChat));
  ['repPeriod', 'repDate'].forEach(id => document.getElementById(id).addEventListener('change', loadReports));

  // Tải sẵn danh sách bệnh nhân nền cho Voice-to-Action và Dashboard
  loadPatients();
  await loadDashboard();
  startRealtimeDashboard();
}
initApp();
</script>
</body>
</html>