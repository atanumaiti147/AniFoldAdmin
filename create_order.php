<?php
// ============================================================
// ANIFOLD STORE — create_order.php (COMPLETE VERSION)
//
// EMAIL TRIGGERS (ab har jagah se call hoga):
//   ?action=order_email    → Purchase ke baad confirmation
//   ?action=welcome_email  → Naya account banane pe
//   ?action=reset_email    → Password reset
//   ?action=contact_email  → Contact form submit hone pe
//   ?action=upload_image   → Cloudinary mein image upload
//   (koi action nahi)      → Razorpay order create (original)
// ============================================================

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://anifold.shop', 'https://craftyam.anifold.shop'];
if(in_array($origin, $allowed)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://anifold.shop");
}
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

// ====================================================
// APNI KEYS YAHAN DAALEIN
// ====================================================
$RAZORPAY_KEY_ID     = "rzp_live_RzjzE21QYwmsqE";      // Aapka existing key (same)
$RAZORPAY_KEY_SECRET = "1NhInpsQLAeYbjsLE63oXvSH";     // Aapka existing secret (same)

// Resend: resend.com → Sign up (free) → API Keys → Create Key
$RESEND_API_KEY = "re_NDfXidA1_3bAJr9iNLuH9Dtcevud4TCf2";

// Email sender (resend mein domain verify karo pehle - neeche guide hai)
$FROM_EMAIL = "noreply@anifold.shop";
$FROM_NAME  = "Anifold Store";

// Cloudinary: cloudinary.com → Sign up → Dashboard se copy karo
$CLOUDINARY_CLOUD  = "di1mnrg0l";
$CLOUDINARY_KEY    = "855961983548569";
$CLOUDINARY_SECRET = "Ih_dYF98_M8wSL0BihhTrQTTTcI";
// ====================================================

$action = $_GET['action'] ?? 'create_order';

switch ($action) {
    case 'order_email':   handleOrderEmail();   break;
    case 'welcome_email': handleWelcomeEmail(); break;
    case 'reset_email':   handleResetEmail();   break;
    case 'contact_email': handleContactEmail(); break;
    case 'upload_image':  handleImageUpload();  break;
    default:              handleRazorpay();     break;
}

// ============================================================
// RAZORPAY — ORIGINAL CODE (kuch nahi badla)
// ============================================================
function handleRazorpay() {
    global $RAZORPAY_KEY_ID, $RAZORPAY_KEY_SECRET;

    $data = json_decode(file_get_contents("php://input"));

    if (empty($data->amount) || !is_numeric($data->amount)) {
        http_response_code(400);
        echo json_encode(['error' => 'Amount is required and must be a number.']);
        exit();
    }

    // Create order using Razorpay REST API directly (no SDK needed)
    $orderData = json_encode([
        'receipt'         => 'rcpt_' . time(),
        'amount'          => (int)$data->amount,
        'currency'        => 'INR',
        'payment_capture' => 1
    ]);

    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $orderData,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($RAZORPAY_KEY_ID . ':' . $RAZORPAY_KEY_SECRET),
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        http_response_code(500);
        echo json_encode(['error' => 'Network error: ' . $curlErr]);
        exit();
    }

    $result = json_decode($response, true);

    if ($httpCode === 200 && isset($result['id'])) {
        echo json_encode(['order_id' => $result['id']]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Razorpay error', 'details' => $result]);
    }
}

// ============================================================
// EMAIL 1: ORDER CONFIRMATION — Purchase ke turant baad
// ============================================================
function handleOrderEmail() {
    $d          = getInput();
    $to_email   = validateEmail($d['to_email'] ?? '');
    $to_name    = clean($d['to_name'] ?? 'Customer');
    $product    = clean($d['product_name'] ?? 'Your Product');
    $total      = clean($d['order_total'] ?? '');
    $date       = clean($d['order_date'] ?? date('d M Y, h:i A'));
    $payment_id = clean($d['payment_id'] ?? 'N/A');

    $subject = "Order Confirmed — {$product} | Anifold Store";
    $html    = tpl_order($to_name, $product, $total, $date, $payment_id);
    resend($to_email, $to_name, $subject, $html);
}

// ============================================================
// EMAIL 2: WELCOME — Naya account banane pe
// ============================================================
function handleWelcomeEmail() {
    $d        = getInput();
    $to_email = validateEmail($d['to_email'] ?? '');
    $to_name  = clean($d['to_name'] ?? 'Friend');

    $subject = "Anifold mein Aapka Swagat Hai, {$to_name}!";
    $html    = tpl_welcome($to_name);
    resend($to_email, $to_name, $subject, $html);
}

// ============================================================
// EMAIL 3: PASSWORD RESET
// ============================================================
function handleResetEmail() {
    $d          = getInput();
    $to_email   = validateEmail($d['to_email'] ?? '');
    $to_name    = clean($d['to_name'] ?? 'User');
    $reset_link = clean($d['reset_link'] ?? 'https://anifold.shop');

    $subject = "Password Reset — Anifold Store";
    $html    = tpl_reset($to_name, $reset_link);
    resend($to_email, $to_name, $subject, $html);
}

// ============================================================
// EMAIL 4: CONTACT FORM REPLY
// ============================================================
function handleContactEmail() {
    global $RESEND_API_KEY, $FROM_EMAIL, $FROM_NAME;
    $d        = getInput();
    $to_email = validateEmail($d['to_email'] ?? '');
    $to_name  = clean($d['to_name'] ?? 'Customer');
    $message  = clean($d['message'] ?? '');

    // 1. Send confirmation to customer
    $subject = "Aapka Message Mila — Anifold Store";
    $html    = tpl_contact($to_name, $message);
    resend($to_email, $to_name, $subject, $html);

    // 2. Notify admin (atanumaity92048@gmail.com) about new message
    $admin_html = '<html><body style="font-family:Arial;padding:20px;">
        <h2 style="color:#702122;">New Contact Message!</h2>
        <p><b>From:</b> ' . $to_name . ' (' . $to_email . ')</p>
        <p><b>Message:</b></p>
        <blockquote style="background:#f5f5f5;padding:12px;border-left:4px solid #702122;">' . $message . '</blockquote>
        <p><a href="mailto:' . $to_email . '" style="background:#702122;color:white;padding:10px 20px;text-decoration:none;border-radius:6px;">Reply to Customer</a></p>
    </body></html>';
    $admin_payload = json_encode([
        'from'    => "{$FROM_NAME} <{$FROM_EMAIL}>",
        'to'      => ['atanumaity92048@gmail.com'],
        'subject' => "New Contact: $to_name wants to connect",
        'html'    => $admin_html,
    ]);
    $ch2 = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $admin_payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $RESEND_API_KEY],
    ]);
    curl_exec($ch2); curl_close($ch2);
}

// ============================================================
// CLOUDINARY IMAGE UPLOAD
// ============================================================
function handleImageUpload() {
    global $CLOUDINARY_CLOUD, $CLOUDINARY_KEY, $CLOUDINARY_SECRET;

    if (!isset($_FILES['image'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No image provided']);
        exit();
    }

    $file      = $_FILES['image'];
    $folder    = $_POST['folder'] ?? 'anifold-products';
    $timestamp = time();
    $sig_str   = "folder={$folder}&timestamp={$timestamp}{$CLOUDINARY_SECRET}";
    $signature = sha1($sig_str);

    $ch = curl_init("https://api.cloudinary.com/v1_1/{$CLOUDINARY_CLOUD}/image/upload");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'file'         => new CURLFile($file['tmp_name'], $file['type'], $file['name']),
            'api_key'      => $CLOUDINARY_KEY,
            'timestamp'    => $timestamp,
            'folder'       => $folder,
            'signature'    => $signature,
            'quality'      => 'auto:good',
            'fetch_format' => 'auto',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $data = json_decode($response, true);
        echo json_encode(['success' => true, 'url' => $data['secure_url'], 'format' => $data['format'], 'width' => $data['width'], 'height' => $data['height']]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Upload failed', 'details' => $response]);
    }
}

// ============================================================
// RESEND API SENDER
// ============================================================
function resend($to_email, $to_name, $subject, $html) {
    global $RESEND_API_KEY, $FROM_EMAIL, $FROM_NAME;

    $payload = json_encode([
        'from'    => "{$FROM_NAME} <{$FROM_EMAIL}>",
        'to'      => ["{$to_name} <{$to_email}>"],
        'subject' => $subject,
        'html'    => $html,
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $RESEND_API_KEY,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 || $code === 201) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Email failed', 'code' => $code, 'details' => $response]);
    }
}

// ============================================================
// HELPERS
// ============================================================
function getInput()        { return json_decode(file_get_contents("php://input"), true) ?? []; }
function clean($v)         { return htmlspecialchars(strip_tags(trim($v))); }
function validateEmail($e) {
    $clean = filter_var(trim($e), FILTER_VALIDATE_EMAIL);
    if (!$clean) { http_response_code(400); echo json_encode(['error' => 'Invalid email']); exit(); }
    return $clean;
}
function wrap($color, $badge, $content) {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:28px 14px;"><tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:540px;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
<tr><td style="background:linear-gradient(135deg,#702122,#a40000);padding:26px 28px;text-align:center;">
  <img src="https://i.ibb.co/4wXjKXs2/20260102-204547.png" width="52" alt="Anifold" style="border-radius:10px;display:block;margin:0 auto 10px;">
  <h1 style="margin:0;color:#fff;font-size:19px;font-weight:900;letter-spacing:1px;">ANIFOLD STORE</h1>
  <p style="margin:4px 0 0;color:rgba(255,255,255,0.6);font-size:11px;">Anime Papercraft Templates</p>
</td></tr>
<tr><td style="background:' . $color . ';padding:11px 28px;text-align:center;">
  <p style="margin:0;color:#fff;font-size:13px;font-weight:700;">' . $badge . '</p>
</td></tr>
' . $content . '
<tr><td style="background:#0a0a0a;padding:16px 28px;text-align:center;">
  <p style="margin:0 0 4px;color:rgba(255,255,255,0.3);font-size:11px;">© 2025 Anifold Store · anifold.shop</p>
  <p style="margin:0;font-size:10px;color:rgba(255,255,255,0.2);">Aapne anifold.shop pe register kiya isliye ye email mila.</p>
</td></tr>
</table></td></tr></table>
</body></html>';
}

// ============================================================
// EMAIL TEMPLATES
// ============================================================

function tpl_order($name, $product, $total, $date, $pid) {
    $body = '<tr><td style="padding:26px 28px;">
  <p style="margin:0 0 18px;color:#374151;font-size:14px;line-height:1.7;">Hey <strong>' . $name . '</strong> 👋<br>Aapka order successfully place ho gaya hai!</p>
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border-radius:10px;border:1px solid #e5e7eb;margin-bottom:18px;overflow:hidden;">
    <tr><td style="background:#111;padding:10px 16px;"><p style="margin:0;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">📦 Order Details</p></td></tr>
    <tr><td style="padding:16px;">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr><td style="padding:5px 0;color:#6b7280;font-size:13px;">Product</td><td style="color:#111;font-size:13px;font-weight:700;text-align:right;">' . $product . '</td></tr>
        <tr><td colspan="2" style="border-top:1px dashed #e5e7eb;padding:3px 0;"></td></tr>
        <tr><td style="padding:5px 0;color:#6b7280;font-size:13px;">Amount Paid</td><td style="color:#702122;font-size:15px;font-weight:900;text-align:right;">' . $total . '</td></tr>
        <tr><td style="padding:5px 0;color:#6b7280;font-size:13px;">Date</td><td style="color:#111;font-size:13px;text-align:right;">' . $date . '</td></tr>
        <tr><td style="padding:5px 0;color:#6b7280;font-size:13px;">Payment ID</td><td style="color:#555;font-size:10px;font-family:monospace;text-align:right;">' . $pid . '</td></tr>
      </table>
    </td></tr>
  </table>
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#fef3c7;border-radius:10px;border:1px solid #fde68a;margin-bottom:18px;">
    <tr><td style="padding:14px 16px;">
      <p style="margin:0 0 7px;color:#92400e;font-size:11px;font-weight:800;text-transform:uppercase;">⬇️ Download Kaise Karein</p>
      <p style="margin:3px 0;color:#78350f;font-size:12px;line-height:1.6;">1. anifold.shop website kholein</p>
      <p style="margin:3px 0;color:#78350f;font-size:12px;line-height:1.6;">2. Menu → <strong>My Library</strong></p>
      <p style="margin:3px 0;color:#78350f;font-size:12px;line-height:1.6;">3. Product → <strong>"Download Now"</strong></p>
    </td></tr>
  </table>
  <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;"><tr><td align="center">
    <a href="https://anifold.shop" style="display:inline-block;background:#702122;color:#fff;padding:12px 30px;border-radius:50px;text-decoration:none;font-weight:800;font-size:13px;">📥 My Library Kholein</a>
  </td></tr></table>
  <p style="margin:0;color:#9ca3af;font-size:11px;text-align:center;">Support? <a href="https://wa.link/s62366" style="color:#702122;font-weight:700;text-decoration:none;">WhatsApp pe message karein</a></p>
</td></tr>';
    return wrap('#16a34a', '✅  Order Confirmed! Download ready hai.', $body);
}

function tpl_welcome($name) {
    $body = '<tr><td style="padding:26px 28px;">
  <p style="margin:0 0 16px;color:#374151;font-size:14px;line-height:1.7;">Hey <strong>' . $name . '</strong> 👋<br>Anifold Store pe aane ka shukriya! Ab aap 200+ amazing anime papercraft templates explore kar sakte hain.</p>
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border-radius:10px;border:1px solid #e5e7eb;margin-bottom:18px;overflow:hidden;">
    <tr><td style="background:#111;padding:10px 16px;"><p style="margin:0;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;">🚀 Aap Kya Kar Sakte Hain</p></td></tr>
    <tr><td style="padding:16px;">
      <p style="margin:5px 0;color:#374151;font-size:13px;line-height:1.7;">📦 &nbsp; <strong>200+ templates</strong> — Naruto, One Piece, Demon Slayer, Dragon Ball</p>
      <p style="margin:5px 0;color:#374151;font-size:13px;line-height:1.7;">⚡ &nbsp; <strong>Instant download</strong> — purchase ke baad turant milega</p>
      <p style="margin:5px 0;color:#374151;font-size:13px;line-height:1.7;">❤️ &nbsp; <strong>Wishlist</strong> mein products save karein</p>
      <p style="margin:5px 0;color:#374151;font-size:13px;line-height:1.7;">🎟️ &nbsp; <strong>Coupons</strong> use karein discount ke liye</p>
      <p style="margin:5px 0;color:#374151;font-size:13px;line-height:1.7;">📱 &nbsp; <strong>App install</strong> karein — home screen pe add karein</p>
    </td></tr>
  </table>
  <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;"><tr><td align="center">
    <a href="https://anifold.shop" style="display:inline-block;background:#702122;color:#fff;padding:12px 30px;border-radius:50px;text-decoration:none;font-weight:800;font-size:13px;">🛒 Shopping Start Karein</a>
  </td></tr></table>
  <p style="margin:0;color:#9ca3af;font-size:11px;text-align:center;">Koi sawaal? <a href="https://wa.link/s62366" style="color:#702122;font-weight:700;text-decoration:none;">WhatsApp pe chat karein!</a></p>
</td></tr>';
    return wrap('#702122', '🎉  Anifold Family mein Aapka Swagat Hai!', $body);
}

function tpl_reset($name, $link) {
    $body = '<tr><td style="padding:26px 28px;">
  <p style="margin:0 0 16px;color:#374151;font-size:14px;line-height:1.7;">Hey <strong>' . $name . '</strong>,<br>Aapne Anifold Store account ka password reset maanga tha.</p>
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#eff6ff;border-radius:10px;border:1px solid #bfdbfe;margin-bottom:18px;">
    <tr><td style="padding:14px 16px;">
      <p style="margin:0 0 5px;color:#1e40af;font-size:11px;font-weight:800;text-transform:uppercase;">⚠️ Important</p>
      <p style="margin:0;color:#1e3a8a;font-size:12px;line-height:1.6;">Ye link <strong>1 ghante</strong> mein expire ho jaayega.<br>Agar aapne ye request nahi kiya toh is email ko ignore karein.</p>
    </td></tr>
  </table>
  <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;"><tr><td align="center">
    <a href="' . $link . '" style="display:inline-block;background:#1d4ed8;color:#fff;padding:12px 30px;border-radius:50px;text-decoration:none;font-weight:800;font-size:13px;">🔑 Password Reset Karein</a>
  </td></tr></table>
  <p style="margin:0;color:#9ca3af;font-size:11px;text-align:center;">Button kaam nahi kara? <a href="' . $link . '" style="color:#702122;word-break:break-all;font-size:10px;">' . $link . '</a></p>
</td></tr>';
    return wrap('#1d4ed8', '🔐  Password Reset Request', $body);
}

function tpl_contact($name, $message) {
    $body = '<tr><td style="padding:26px 28px;">
  <p style="margin:0 0 16px;color:#374151;font-size:14px;line-height:1.7;">Hey <strong>' . $name . '</strong> 👋<br>Aapka message hamein mil gaya! Hum <strong>24 ghante</strong> mein reply karenge.</p>
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f9ff;border-radius:10px;border:1px solid #bae6fd;margin-bottom:18px;overflow:hidden;">
    <tr><td style="background:#0c4a6e;padding:10px 16px;"><p style="margin:0;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;">Aapka Message</p></td></tr>
    <tr><td style="padding:14px 16px;">
      <p style="margin:0;color:#0c4a6e;font-size:13px;line-height:1.7;font-style:italic;">"' . $message . '"</p>
    </td></tr>
  </table>
  <p style="margin:0 0 14px;color:#374151;font-size:13px;">Urgent help chahiye? WhatsApp pe bhi available hain:</p>
  <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;"><tr><td align="center">
    <a href="https://wa.link/s62366" style="display:inline-block;background:#25d366;color:#fff;padding:12px 30px;border-radius:50px;text-decoration:none;font-weight:800;font-size:13px;">💬 WhatsApp pe Chat Karein</a>
  </td></tr></table>
</td></tr>';
    return wrap('#0891b2', '📬  Aapka Message Mila!', $body);
}
?>
