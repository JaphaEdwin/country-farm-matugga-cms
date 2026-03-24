<?php
session_start();

ini_set('session.cookie_httponly', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
ini_set('session.use_strict_mode', '1');

// ── Credentials (change password via admin > settings) ──────────────────
const ADMIN_EMAIL_FILE = __DIR__ . '/data/admin-email.txt';
const ADMIN_PASS_FILE  = __DIR__ . '/data/admin-pass.txt';

// Default credentials if files don't exist yet
const DEFAULT_ADMIN_EMAIL = 'admin@countryfarmmatugga.com';
// bcrypt of "ChangeMe123!" — admin MUST change this on first login
const DEFAULT_ADMIN_PASS_HASH = '$2y$10$7kfRsmLh9CjQxVfH5mQq6elPCbPfrC66lq9hyMFiJTeic9q0S4j1u';

// ── Paths ────────────────────────────────────────────────────────────────
const DATA_DIR      = __DIR__ . '/data/';
const UPLOADS_DIR   = __DIR__ . '/../uploads/';
const UPLOADS_URL   = 'uploads/';   // relative URL from site root

const SETTINGS_FILE  = DATA_DIR . 'website-settings.json';
const PRODUCTS_FILE  = DATA_DIR . 'products.json';
const QUOTES_FILE    = DATA_DIR . 'quotes.json';
const CONTENT_FILE   = DATA_DIR . 'content.json';
const MEDIA_FILE     = DATA_DIR . 'media.json';

// ── Bootstrap dirs ───────────────────────────────────────────────────────
foreach ([DATA_DIR, UPLOADS_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['success' => false, 'message' => 'Method not allowed'], 405);
    }
}

function require_auth(): void {
    if (empty($_SESSION['cfm_admin_authenticated'])) {
        json_response(['success' => false, 'message' => 'Unauthorized'], 401);
    }
}

function read_json(string $path, $default = []) {
    if (!file_exists($path)) return $default;
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') return $default;
    $decoded = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
}

function write_json(string $path, $data): bool {
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded !== false && file_put_contents($path, $encoded, LOCK_EX) !== false;
}

function sanitize(mixed $value, int $max = 255): string {
    return mb_substr(strip_tags(trim((string) $value)), 0, $max);
}

function get_admin_email(): string {
    if (file_exists(ADMIN_EMAIL_FILE)) {
        $e = trim(file_get_contents(ADMIN_EMAIL_FILE));
        if ($e) return $e;
    }
    return DEFAULT_ADMIN_EMAIL;
}

function get_admin_pass_hash(): string {
    if (file_exists(ADMIN_PASS_FILE)) {
        $h = trim(file_get_contents(ADMIN_PASS_FILE));
        if ($h) return $h;
    }
    return DEFAULT_ADMIN_PASS_HASH;
}

// ── Defaults ─────────────────────────────────────────────────────────────
function default_settings(): array {
    return [
        'phone'     => '+256 700 000 000',
        'email'     => 'info@countryfarmmatugga.com',
        'location'  => 'Matugga, Uganda',
        'hours'     => 'Mon - Sat, 8:00 AM - 6:00 PM',
        'whatsapp'  => '256700000000',
    ];
}

function default_products(): array {
    return [
        ['id' => 1, 'name' => 'Broiler Chicks',   'price' => 1800, 'badge' => 'Fast growth',    'description' => 'Ideal for meat production with strong early growth and efficient feed conversion.', 'image' => ''],
        ['id' => 2, 'name' => 'Layer Chicks',    'price' => 2000, 'badge' => 'Egg production', 'description' => 'Built for long-term egg production for structured laying operations.',               'image' => ''],
        ['id' => 3, 'name' => 'Kuroiler Chicks', 'price' => 2500, 'badge' => 'Hardy option',   'description' => 'A versatile dual-purpose option suited for mixed farmer demand.',                   'image' => ''],
    ];
}

function default_content(): array {
    return [
        'farmName'       => 'Country Farm Matugga',
        'logoImage'      => 'Logo.png',
        'heroTitle'      => 'Premium day-old chicks for serious farmers.',
        'heroSubtitle'   => 'Healthy stock, dependable guidance, and a clean ordering process.',
        'heroBg'         => '',
        'statsYears'     => '15+',
        'statsChicks'    => '500K+',
        'statsSurvival'  => '95%',
        'statsFarmers'   => '2,000+',
        'aboutImage'     => 'Logo.png',
        'aboutPara1'     => 'Country Farm Matugga serves farmers who want quality chicks, consistent communication, and a supplier that looks like it takes its work seriously.',
        'aboutPara2'     => 'This version of the site keeps things simple while improving credibility, structure, and buyer confidence.',
        'aboutPara3'     => '',
        'contactAddress' => 'Matugga, Uganda',
        'contactPhone'   => '+256 700 000 000',
        'contactEmail'   => 'info@countryfarmmatugga.com',
        'contactWhatsApp'=> '256700000000',
        'socialFacebook' => '',
        'socialInstagram'=> '',
        'testimonials'   => [
            ['stars' => 5, 'text' => 'The response was quick and the ordering process was clear.', 'author' => 'Farmer in Wakiso',   'context' => 'Broiler order'],
            ['stars' => 5, 'text' => 'The chicks arrived in good condition and I appreciated the guidance.', 'author' => 'Farmer in Kampala', 'context' => 'Layer order'],
            ['stars' => 5, 'text' => 'Professional communication, simple quote flow, and no guessing games.', 'author' => 'Farmer in Mukono',  'context' => 'Kuroiler order'],
        ],
        'faqs' => [
            ['q' => 'What is the minimum order quantity?', 'a' => 'We accept orders starting from 50 chicks. Contact us for bulk pricing.'],
            ['q' => 'Do you guide farmers after purchase?', 'a' => 'Yes. We provide support on selection, planning, and early-stage management guidance.'],
            ['q' => 'How do I place an order?',            'a' => 'Use the quote form or the WhatsApp button for a faster, structured request.'],
            ['q' => 'Do you serve areas outside Matugga?', 'a' => 'Yes. We serve Kampala and surrounding districts. Contact us for delivery details.'],
        ],
    ];
}

// ── Initialise missing files ──────────────────────────────────────────────
if (!file_exists(SETTINGS_FILE)) write_json(SETTINGS_FILE, default_settings());
if (!file_exists(PRODUCTS_FILE)) write_json(PRODUCTS_FILE, default_products());
if (!file_exists(QUOTES_FILE))   write_json(QUOTES_FILE,   []);
if (!file_exists(CONTENT_FILE))  write_json(CONTENT_FILE,  default_content());
if (!file_exists(MEDIA_FILE))    write_json(MEDIA_FILE,    []);
