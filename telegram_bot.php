<?php
/**
 * AniFold Telegram Bot Webhook
 * ============================================================
 * Server pe upload karo: https://craftyam.anifold.shop/telegram_bot.php
 *
 * Webhook set karo (browser mein ek baar open karo):
 * https://api.telegram.org/bot{TOKEN}/setWebhook?url=https://craftyam.anifold.shop/telegram_bot.php
 *
 * USER COMMANDS:
 *   /start {uid}   — Firebase account ko Telegram se link karo
 *   /myorders      — Apne last 5 orders dekho
 *   /help          — Commands list
 *
 * ADMIN COMMANDS (sirf Admin Chat ID se):
 *   /stats         — Revenue, orders, users summary
 *   /products      — Sare products ki list
 *   /orders        — Last 10 orders
 *   /users         — User stats
 *   /help          — Commands list
 */

$FIREBASE_DB_URL = "https://anifold-dp-default-rtdb.firebaseio.com";

// ─── Read update ───────────────────────────────────────────────────
$input  = file_get_contents("php://input");
$update = json_decode($input, true);
if (!$update) { http_response_code(200); exit; }

$message = $update['message'] ?? null;
if (!$message) { http_response_code(200); exit; }

$chat_id    = $message['chat']['id'];
$text       = trim($message['text'] ?? '');
$first_name = $message['from']['first_name'] ?? 'User';

// ─── Fetch bot config from Firebase ───────────────────────────────
$tg_meta    = firebase_get("meta/telegram");
$BOT_TOKEN  = $tg_meta['botToken']    ?? '';
$ADMIN_ID   = $tg_meta['adminChatId'] ?? '';

if (empty($BOT_TOKEN)) { http_response_code(200); exit; }

$is_admin = (string)$chat_id === (string)$ADMIN_ID;

// ─── Route ─────────────────────────────────────────────────────────
if (str_starts_with($text, '/start')) {
    cmd_start($chat_id, $text, $first_name);
} elseif ($text === '/myorders') {
    cmd_myorders($chat_id);
} elseif ($text === '/stats'    && $is_admin) { cmd_stats($chat_id);
} elseif ($text === '/products' && $is_admin) { cmd_products($chat_id);
} elseif ($text === '/orders'   && $is_admin) { cmd_orders($chat_id);
} elseif ($text === '/users'    && $is_admin) { cmd_users($chat_id);
} elseif ($text === '/help') {
    cmd_help($chat_id, $is_admin);
} else {
    send_msg($chat_id, "Hi {$first_name}! 👋\n\nSend /start to link your account.\nSend /help for all commands.");
}

http_response_code(200);

// ═══════════════════════════════════════════════════════════════════
//  COMMAND HANDLERS
// ═══════════════════════════════════════════════════════════════════

function cmd_start($chat_id, $text, $first_name) {
    $parts = explode(' ', $text, 2);
    $uid   = isset($parts[1]) ? trim($parts[1]) : null;

    if (!$uid) {
        send_msg($chat_id,
            "🎌 <b>Welcome to AniFold Bot!</b>\n\n" .
            "To link your Telegram with your AniFold account:\n" .
            "1. Open <b>anifold.shop</b>\n" .
            "2. Go to <b>My Library</b>\n" .
            "3. Tap <b>Connect Telegram</b>\n\n" .
            "After linking, you'll receive download links here automatically after every purchase! 🎁"
        );
        return;
    }

    $user = firebase_get("users/{$uid}");
    if (!$user) {
        send_msg($chat_id, "❌ Account not found!\n\nPlease make sure you're logged in on anifold.shop first, then try again.");
        return;
    }

    // Save chat ID to Firebase
    firebase_put("users/{$uid}/telegramChatId", $chat_id);

    $email = $user['email'] ?? 'your account';
    $name  = trim(($user['firstName'] ?? '') . ' ' . ($user['lastName'] ?? ''));

    send_msg($chat_id,
        "✅ <b>Account Linked Successfully!</b>\n\n" .
        "📧 Email: <code>{$email}</code>\n" .
        "👤 Name: " . ($name ?: 'Not set') . "\n\n" .
        "🎁 From now on, you'll receive your <b>download links here</b> automatically after every purchase!\n\n" .
        "Commands: /myorders | /help"
    );
}

function cmd_myorders($chat_id) {
    // Find user by telegramChatId
    $users = firebase_get("users") ?? [];
    $uid   = null;
    foreach ($users as $id => $u) {
        if (isset($u['telegramChatId']) && (string)$u['telegramChatId'] === (string)$chat_id) {
            $uid = $id; break;
        }
    }
    if (!$uid) { send_msg($chat_id, "❌ Account not linked yet.\nSend /start to link your account."); return; }

    $orders = firebase_get("orders") ?? [];
    $user   = firebase_get("users/{$uid}");
    $email  = $user['email'] ?? '';
    $myOrders = [];
    foreach ($orders as $k => $o) {
        if (($o['email'] ?? '') === $email) $myOrders[] = $o;
    }
    if (empty($myOrders)) { send_msg($chat_id, "📭 No orders yet.\n\nVisit anifold.shop to browse products!"); return; }
    usort($myOrders, fn($a,$b) => ($b['date']??0) - ($a['date']??0));
    $recent = array_slice($myOrders, 0, 5);
    $msg = "📦 <b>Your Last " . count($recent) . " Orders:</b>\n\n";
    foreach ($recent as $o) {
        $date = isset($o['date']) ? date('d M Y', $o['date']/1000) : '—';
        $msg .= "• <b>{$o['item']}</b>\n  ₹{$o['price']} | {$date}\n\n";
    }
    send_msg($chat_id, $msg);
}

function cmd_stats($chat_id) {
    $orders   = firebase_get("orders")   ?? [];
    $products = firebase_get("products") ?? [];
    $users    = firebase_get("users")    ?? [];
    $revenue  = array_sum(array_column($orders, 'price'));
    $today    = array_filter($orders, fn($o) => ($o['date']??0) > (time()-86400)*1000);
    $msg = "📊 <b>AniFold Store Stats</b>\n\n" .
           "💰 Total Revenue: <b>₹" . number_format($revenue, 2) . "</b>\n" .
           "🛒 Total Orders: <b>" . count($orders) . "</b>\n" .
           "📅 Today's Orders: <b>" . count($today) . "</b>\n" .
           "📦 Products: <b>" . count($products) . "</b>\n" .
           "👥 Users: <b>" . count($users) . "</b>\n\n" .
           "⏰ " . date('d M Y, h:i A');
    send_msg($chat_id, $msg);
}

function cmd_products($chat_id) {
    $products = firebase_get("products") ?? [];
    if (empty($products)) { send_msg($chat_id, "No products yet."); return; }
    $msg = "📦 <b>All Products (" . count($products) . ")</b>\n\n";
    $i = 0;
    foreach ($products as $p) {
        if ($i++ >= 20) { $msg .= "...and " . (count($products)-20) . " more."; break; }
        $msg .= "• <b>{$p['title']}</b> — ₹{$p['discountedPrice']}\n";
    }
    send_msg($chat_id, $msg);
}

function cmd_orders($chat_id) {
    $orders = firebase_get("orders") ?? [];
    if (empty($orders)) { send_msg($chat_id, "No orders yet."); return; }
    usort($orders, fn($a,$b) => ($b['date']??0) - ($a['date']??0));
    $recent = array_slice($orders, 0, 10);
    $msg = "🛒 <b>Last 10 Orders</b>\n\n";
    foreach ($recent as $o) {
        $date = isset($o['date']) ? date('d M, h:i A', $o['date']/1000) : '—';
        $msg .= "📦 <b>{$o['item']}</b>\n💰 ₹{$o['price']} | {$o['email']}\n📅 {$date}\n\n";
    }
    send_msg($chat_id, $msg);
}

function cmd_users($chat_id) {
    $users  = firebase_get("users") ?? [];
    $total  = count($users);
    $linked = count(array_filter($users, fn($u) => !empty($u['telegramChatId'])));
    $msg = "👥 <b>User Stats</b>\n\n" .
           "Total Users: <b>{$total}</b>\n" .
           "Telegram Linked: <b>{$linked}</b>\n" .
           "Not Linked: <b>" . ($total-$linked) . "</b>";
    send_msg($chat_id, $msg);
}

function cmd_help($chat_id, $is_admin) {
    $msg = "🤖 <b>AniFold Bot Commands</b>\n\n" .
           "/start — Link Telegram to your account\n" .
           "/myorders — View your last 5 orders\n" .
           "/help — Show this message\n";
    if ($is_admin) {
        $msg .= "\n👑 <b>Admin Commands:</b>\n" .
                "/stats — Revenue & store stats\n" .
                "/products — List all products\n" .
                "/orders — Last 10 orders\n" .
                "/users — User count & TG stats\n";
    }
    send_msg($chat_id, $msg);
}

// ═══════════════════════════════════════════════════════════════════
//  FIREBASE HELPERS
// ═══════════════════════════════════════════════════════════════════
function firebase_get($path) {
    global $FIREBASE_DB_URL;
    $res = @file_get_contents("{$FIREBASE_DB_URL}/{$path}.json");
    if ($res === false) return null;
    $d = json_decode($res, true);
    return ($d === null || $d === 'null') ? null : $d;
}
function firebase_put($path, $value) {
    global $FIREBASE_DB_URL;
    $ctx = stream_context_create(['http' => ['method'=>'PUT','header'=>'Content-Type: application/json','content'=>json_encode($value)]]);
    return @file_get_contents("{$FIREBASE_DB_URL}/{$path}.json", false, $ctx);
}

// ═══════════════════════════════════════════════════════════════════
//  TELEGRAM API
// ═══════════════════════════════════════════════════════════════════
function send_msg($chat_id, $text) {
    global $BOT_TOKEN;
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/json',
        'content' => json_encode(['chat_id'=>$chat_id,'text'=>$text,'parse_mode'=>'HTML'])
    ]]);
    @file_get_contents("https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage", false, $ctx);
}
