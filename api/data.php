<?php
require_once __DIR__ . '/config.php';

// ── GET endpoints ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    // Public: all content for index.html
    if ($action === 'public') {
        $settings = read_json(SETTINGS_FILE, default_settings());
        $products  = read_json(PRODUCTS_FILE, default_products());
        $content   = read_json(CONTENT_FILE,  default_content());

        // Merge settings into content so front-end has one object
        $content['contactPhone']    = $settings['phone']    ?? $content['contactPhone'];
        $content['contactEmail']    = $settings['email']    ?? $content['contactEmail'];
        $content['contactAddress']  = $settings['location'] ?? $content['contactAddress'];
        $content['contactHours']    = $settings['hours']    ?? '';
        $content['contactWhatsApp'] = $settings['whatsapp'] ?? $content['contactWhatsApp'];

        json_response(['success' => true, 'content' => $content, 'products' => $products]);
    }

    // Admin: dashboard data (auth required)
    if ($action === 'dashboard') {
        require_auth();
        json_response([
            'success'  => true,
            'quotes'   => read_json(QUOTES_FILE,   []),
            'settings' => read_json(SETTINGS_FILE, default_settings()),
            'products' => read_json(PRODUCTS_FILE, default_products()),
            'content'  => read_json(CONTENT_FILE,  default_content()),
            'media'    => read_json(MEDIA_FILE,     []),
            'email'    => get_admin_email(),
        ]);
    }

    // Serve uploaded file securely (auth required for non-images, optional)
    if ($action === 'media' && !empty($_GET['file'])) {
        require_auth();
        $name = basename($_GET['file']);
        $path = UPLOADS_DIR . $name;
        if (!file_exists($path)) {
            http_response_code(404); echo '{"success":false,"message":"Not found"}'; exit;
        }
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=86400');
        readfile($path);
        exit;
    }

    json_response(['success' => true, 'message' => 'Country Farm Matugga API']);
}

// ── POST endpoints ────────────────────────────────────────────────────────
require_post();

// ── File upload (multipart/form-data, auth required) ─────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'upload_media') {
    require_auth();

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_response(['success' => false, 'message' => 'No file or upload error'], 400);
    }

    $file     = $_FILES['file'];
    $origName = preg_replace('/[^A-Za-z0-9_.\-]/', '_', basename($file['name']));
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    $allowed_images = ['jpg','jpeg','png','webp','gif','svg'];
    $allowed_videos = ['mp4','mov','webm','ogg'];
    $allowed = array_merge($allowed_images, $allowed_videos);

    if (!in_array($ext, $allowed)) {
        json_response(['success' => false, 'message' => 'File type not allowed'], 415);
    }

    // Validate MIME
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $ok_mimes = [
        'image/jpeg','image/png','image/webp','image/gif','image/svg+xml',
        'video/mp4','video/quicktime','video/webm','video/ogg',
    ];
    if (!in_array($mime, $ok_mimes)) {
        json_response(['success' => false, 'message' => 'Invalid file type'], 415);
    }

    // Size limits: 10MB images, 200MB videos
    $max = str_starts_with($mime, 'video/') ? 200 * 1024 * 1024 : 10 * 1024 * 1024;
    if ($file['size'] > $max) {
        json_response(['success' => false, 'message' => 'File too large'], 413);
    }

    // Unique filename
    $unique   = uniqid('cfm_', true) . '.' . $ext;
    $destPath = UPLOADS_DIR . $unique;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        json_response(['success' => false, 'message' => 'Failed to save file'], 500);
    }

    // Save metadata
    $media   = read_json(MEDIA_FILE, []);
    $entry   = [
        'id'       => uniqid(),
        'name'     => $origName,
        'file'     => $unique,
        'url'      => UPLOADS_URL . $unique,
        'mime'     => $mime,
        'size'     => $file['size'],
        'type'     => str_starts_with($mime, 'video/') ? 'video' : 'image',
        'uploaded' => date('c'),
    ];
    $media[] = $entry;
    write_json(MEDIA_FILE, $media);

    json_response(['success' => true, 'file' => $entry]);
}

// ── All other POSTs: JSON body ────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    json_response(['success' => false, 'message' => 'Invalid JSON body'], 400);
}
$action = $data['action'] ?? '';

// ── Login ─────────────────────────────────────────────────────────────────
if ($action === 'login') {
    $email    = sanitize($data['email'] ?? '', 120);
    $password = (string)($data['password'] ?? '');

    if ($email !== get_admin_email() || !password_verify($password, get_admin_pass_hash())) {
        json_response(['success' => false, 'message' => 'Invalid email or password.'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['cfm_admin_authenticated'] = true;
    $_SESSION['cfm_admin_email']         = $email;

    json_response(['success' => true, 'message' => 'Login successful.']);
}

// ── Save quote (public — no auth) ─────────────────────────────────────────
if ($action === 'save_quote') {
    $p = $data['payload'] ?? [];
    $entry = [
        'id'             => uniqid(),
        'name'           => sanitize($p['name']     ?? '', 120),
        'phone'          => sanitize($p['phone']    ?? '', 40),
        'farmType'       => sanitize($p['farmType'] ?? '', 60),
        'product'        => sanitize($p['product']  ?? '', 60),
        'quantity'       => max(1, (int)($p['quantity']       ?? 1)),
        'district'       => sanitize($p['district'] ?? '', 80),
        'message'        => sanitize($p['message']  ?? '', 500),
        'estimatedTotal' => max(0, (int)($p['estimatedTotal'] ?? 0)),
        'createdAt'      => date('c'),
        'status'         => 'New',
    ];
    if (!$entry['name'] || !$entry['phone'] || !$entry['product']) {
        json_response(['success' => false, 'message' => 'Missing required fields.'], 422);
    }
    $quotes   = read_json(QUOTES_FILE, []);
    $quotes[] = $entry;
    if (!write_json(QUOTES_FILE, $quotes)) {
        json_response(['success' => false, 'message' => 'Could not save quote.'], 500);
    }
    json_response(['success' => true, 'message' => 'Quote saved.']);
}

// ── All routes below require auth ─────────────────────────────────────────
require_auth();

// ── Save settings ─────────────────────────────────────────────────────────
if ($action === 'save_settings') {
    $p = $data['payload'] ?? [];
    $settings = [
        'phone'    => sanitize($p['phone']    ?? '', 40),
        'email'    => sanitize($p['email']    ?? '', 120),
        'location' => sanitize($p['location'] ?? '', 120),
        'hours'    => sanitize($p['hours']    ?? '', 120),
        'whatsapp' => preg_replace('/[^0-9]/', '', (string)($p['whatsapp'] ?? '')),
    ];
    if (!$settings['phone'] || !$settings['email'] || !$settings['whatsapp']) {
        json_response(['success' => false, 'message' => 'Required fields missing.'], 422);
    }
    if (!write_json(SETTINGS_FILE, $settings)) {
        json_response(['success' => false, 'message' => 'Could not save settings.'], 500);
    }
    json_response(['success' => true, 'message' => 'Settings saved.']);
}

// ── Save products ─────────────────────────────────────────────────────────
if ($action === 'save_products') {
    $incoming = $data['payload'] ?? [];
    if (!is_array($incoming) || empty($incoming)) {
        json_response(['success' => false, 'message' => 'No products provided.'], 422);
    }
    $clean = [];
    foreach ($incoming as $i => $item) {
        if (!is_array($item)) continue;
        $clean[] = [
            'id'          => (int)($item['id'] ?? ($i + 1)) ?: ($i + 1),
            'name'        => sanitize($item['name']        ?? '', 80),
            'price'       => max(0, (int)($item['price']   ?? 0)),
            'badge'       => sanitize($item['badge']       ?? '', 50),
            'description' => sanitize($item['description'] ?? '', 400),
            'image'       => sanitize($item['image']       ?? '', 300),
        ];
    }
    if (empty($clean)) {
        json_response(['success' => false, 'message' => 'No valid products.'], 422);
    }
    if (!write_json(PRODUCTS_FILE, $clean)) {
        json_response(['success' => false, 'message' => 'Could not save products.'], 500);
    }
    json_response(['success' => true, 'message' => 'Products saved.']);
}

// ── Save full website content ─────────────────────────────────────────────
if ($action === 'save_content') {
    $p = $data['payload'] ?? [];

    // Testimonials
    $testimonials = [];
    foreach ((array)($p['testimonials'] ?? []) as $t) {
        if (!is_array($t)) continue;
        $testimonials[] = [
            'stars'   => max(1, min(5, (int)($t['stars']   ?? 5))),
            'text'    => sanitize($t['text']    ?? '', 400),
            'author'  => sanitize($t['author']  ?? '', 80),
            'context' => sanitize($t['context'] ?? '', 80),
        ];
    }

    // FAQs
    $faqs = [];
    foreach ((array)($p['faqs'] ?? []) as $f) {
        if (!is_array($f)) continue;
        $faqs[] = [
            'q' => sanitize($f['q'] ?? '', 200),
            'a' => sanitize($f['a'] ?? '', 600),
        ];
    }

    $content = [
        'farmName'        => sanitize($p['farmName']    ?? '', 80),
        'logoImage'       => sanitize($p['logoImage']   ?? '', 300),
        'heroTitle'       => sanitize($p['heroTitle']   ?? '', 200),
        'heroSubtitle'    => sanitize($p['heroSubtitle'] ?? '', 300),
        'heroBg'          => sanitize($p['heroBg']      ?? '', 300),
        'statsYears'      => sanitize($p['statsYears']  ?? '', 20),
        'statsChicks'     => sanitize($p['statsChicks'] ?? '', 20),
        'statsSurvival'   => sanitize($p['statsSurvival'] ?? '', 20),
        'statsFarmers'    => sanitize($p['statsFarmers']  ?? '', 20),
        'aboutImage'      => sanitize($p['aboutImage']  ?? '', 300),
        'aboutPara1'      => sanitize($p['aboutPara1']  ?? '', 800),
        'aboutPara2'      => sanitize($p['aboutPara2']  ?? '', 800),
        'aboutPara3'      => sanitize($p['aboutPara3']  ?? '', 800),
        'contactAddress'  => sanitize($p['contactAddress']   ?? '', 120),
        'contactPhone'    => sanitize($p['contactPhone']     ?? '', 40),
        'contactEmail'    => sanitize($p['contactEmail']     ?? '', 120),
        'contactWhatsApp' => preg_replace('/[^0-9]/', '', (string)($p['contactWhatsApp'] ?? '')),
        'socialFacebook'  => sanitize($p['socialFacebook']  ?? '', 200),
        'socialInstagram' => sanitize($p['socialInstagram'] ?? '', 200),
        'testimonials'    => $testimonials,
        'faqs'            => $faqs,
    ];

    if (!write_json(CONTENT_FILE, $content)) {
        json_response(['success' => false, 'message' => 'Could not save content.'], 500);
    }

    // Also sync contact info to settings for backward compat
    $settings = read_json(SETTINGS_FILE, default_settings());
    $settings['phone']    = $content['contactPhone'];
    $settings['email']    = $content['contactEmail'];
    $settings['location'] = $content['contactAddress'];
    $settings['whatsapp'] = $content['contactWhatsApp'];
    write_json(SETTINGS_FILE, $settings);

    json_response(['success' => true, 'message' => 'Content saved.']);
}

// ── Update quote status ───────────────────────────────────────────────────
if ($action === 'update_quote_status') {
    $id     = sanitize($data['id']     ?? '', 40);
    $status = sanitize($data['status'] ?? '', 20);
    $allowed = ['New', 'Read', 'Contacted', 'Completed', 'Cancelled'];
    if (!$id || !in_array($status, $allowed)) {
        json_response(['success' => false, 'message' => 'Invalid id or status.'], 422);
    }
    $quotes = read_json(QUOTES_FILE, []);
    $found  = false;
    foreach ($quotes as &$q) {
        if (($q['id'] ?? '') === $id) { $q['status'] = $status; $found = true; break; }
    }
    unset($q);
    if (!$found) json_response(['success' => false, 'message' => 'Quote not found.'], 404);
    write_json(QUOTES_FILE, $quotes);
    json_response(['success' => true, 'message' => 'Status updated.']);
}

// ── Delete quote ──────────────────────────────────────────────────────────
if ($action === 'delete_quote') {
    $id     = sanitize($data['id'] ?? '', 40);
    $quotes = read_json(QUOTES_FILE, []);
    $quotes = array_values(array_filter($quotes, fn($q) => ($q['id'] ?? '') !== $id));
    write_json(QUOTES_FILE, $quotes);
    json_response(['success' => true, 'message' => 'Quote deleted.']);
}

// ── Delete media file ─────────────────────────────────────────────────────
if ($action === 'delete_media') {
    $id    = sanitize($data['id'] ?? '', 40);
    $media = read_json(MEDIA_FILE, []);
    $item  = null;
    foreach ($media as $m) {
        if (($m['id'] ?? '') === $id) { $item = $m; break; }
    }
    if ($item && !empty($item['file'])) {
        $path = UPLOADS_DIR . basename($item['file']);
        if (file_exists($path)) @unlink($path);
    }
    $media = array_values(array_filter($media, fn($m) => ($m['id'] ?? '') !== $id));
    write_json(MEDIA_FILE, $media);
    json_response(['success' => true, 'message' => 'Media deleted.']);
}

// ── Change credentials ────────────────────────────────────────────────────
if ($action === 'change_credentials') {
    $p = $data['payload'] ?? [];

    // Change email
    if (!empty($p['newEmail'])) {
        $newEmail = sanitize($p['newEmail'], 120);
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            json_response(['success' => false, 'message' => 'Invalid email address.'], 422);
        }
        file_put_contents(ADMIN_EMAIL_FILE, $newEmail);
        $_SESSION['cfm_admin_email'] = $newEmail;
    }

    // Change password
    if (!empty($p['newPassword'])) {
        $curPass  = (string)($p['currentPassword'] ?? '');
        $newPass  = (string)($p['newPassword'] ?? '');
        $confPass = (string)($p['confirmPassword'] ?? '');

        if (!password_verify($curPass, get_admin_pass_hash())) {
            json_response(['success' => false, 'message' => 'Current password is incorrect.'], 403);
        }
        if (strlen($newPass) < 8) {
            json_response(['success' => false, 'message' => 'New password must be at least 8 characters.'], 422);
        }
        if ($newPass !== $confPass) {
            json_response(['success' => false, 'message' => 'Passwords do not match.'], 422);
        }
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        file_put_contents(ADMIN_PASS_FILE, $hash);
    }

    json_response(['success' => true, 'message' => 'Credentials updated.']);
}

json_response(['success' => false, 'message' => 'Unknown action.'], 400);
