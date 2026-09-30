<?php
/**
 * MKClub - Final Premium Refer & Earn Bot (single-file)
 * Token and webhook set per user request.
 *
 * Deploy:
 * 1. Save as bot.php inside public_html.
 * 2. Ensure directory writable (script will create data/).
 * 3. Visit https://mksocial.site/bot.php once to auto-set webhook.
 * 4. Test in Telegram.
 */

date_default_timezone_set('Europe/Istanbul');
error_reporting(0);

// ------------------ CONFIG ------------------
$BOT_TOKEN   = "8463223086:AAEFDkpp71qDmPGi0zYTc-F6I3dTVgzxv3s";
$WEBHOOK_URL = "https://45.158.14.16/m3u/bot.php";

$AUTO_DETECT_WEBHOOK = true; // if false, uses $WEBHOOK_URL as-is

$DEFAULT_ADMIN = "8693437066";
$DEFAULT_CHANNELS = ["@kanalfturkiye"];
$DEFAULT_PAYOUT = "@zemtvapk";
$DEFAULT_REF_REWARD = 5.0;
$DEFAULT_DAILY_BONUS = 2.0;
$MIN_WITHDRAW = 1.0;

// ------------------ FILES & PATHS ------------------
$BASE_DIR = __DIR__;
$DATA_DIR = $BASE_DIR . DIRECTORY_SEPARATOR . "data";
if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0755, true);

$USERS_FILE = $DATA_DIR . DIRECTORY_SEPARATOR . "users.json";
$CONFIG_FILE = $DATA_DIR . DIRECTORY_SEPARATOR . "config.json";
$LOG_FILE = $BASE_DIR . DIRECTORY_SEPARATOR . "bot_log.txt";
$WEBHOOK_LOG = $BASE_DIR . DIRECTORY_SEPARATOR . "webhook_log.txt";

$API_BASE = "https://api.telegram.org/bot{$BOT_TOKEN}/";

// ------------------ UTILITIES ------------------
function api_call($method, $params = []) {
    global $API_BASE;
    $url = $API_BASE . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if (!empty($params)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    }
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $res = curl_exec($ch);
    if ($res === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'error' => $err];
    }
    curl_close($ch);
    $json = json_decode($res, true);
    return $json === null ? ['ok' => false, 'raw' => $res] : $json;
}

function send_message($chat_id, $text, $extra = []) {
    $params = array_merge([
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ], $extra);
    return api_call('sendMessage', $params);
}

function answer_callback($callback_id, $text = '', $show_alert = false) {
    return api_call('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => $text, 'show_alert' => $show_alert]);
}

function send_document($chat_id, $file_path, $caption = '') {
    if (!file_exists($file_path)) return ['ok' => false, 'error' => 'File not found'];
    $cfile = curl_file_create($file_path);
    return api_call('sendDocument', ['chat_id' => $chat_id, 'document' => $cfile, 'caption' => $caption]);
}

function read_json($path) {
    if (!file_exists($path)) return [];
    $s = @file_get_contents($path);
    if ($s === false) return [];
    $d = json_decode($s, true);
    return is_array($d) ? $d : [];
}

function write_json($path, $data) {
    $tmp = $path . '.tmp';
    $fp = @fopen($tmp, 'w');
    if (!$fp) return false;
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    rename($tmp, $path);
    return true;
}

function log_line($msg) {
    global $LOG_FILE;
    $line = "[".date('Y-m-d H:i:s')."] ".$msg.PHP_EOL;
    file_put_contents($LOG_FILE, $line, FILE_APPEND);
}

// ------------------ IBAN DOĞRULAMA ------------------
function validate_iban($iban) {
    // Boşlukları temizle, büyük harfe çevir
    $iban = strtoupper(str_replace(' ', '', trim($iban)));
    // TR IBAN: TR + 24 rakam = 26 karakter
    if (!preg_match('/^TR\d{24}$/', $iban)) return false;
    // IBAN mod-97 kontrolü
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    for ($i = 0; $i < strlen($rearranged); $i++) {
        $ch = $rearranged[$i];
        if (ctype_digit($ch)) $numeric .= $ch;
        else $numeric .= (ord($ch) - 55);
    }
    // Mod 97 hesapla (büyük sayılar için parça parça)
    $mod = 0;
    for ($i = 0; $i < strlen($numeric); $i++) {
        $mod = ($mod * 10 + intval($numeric[$i])) % 97;
    }
    return $mod === 1;
}

function format_iban($iban) {
    $iban = strtoupper(str_replace(' ', '', trim($iban)));
    return trim(chunk_split($iban, 4, ' '));
}

// ------------------ BOOTSTRAP CONFIG ------------------
$config = read_json($CONFIG_FILE);
if (!isset($config['admins']) || !is_array($config['admins'])) $config['admins'] = [$DEFAULT_ADMIN];
if (!isset($config['channels']) || !is_array($config['channels'])) $config['channels'] = $DEFAULT_CHANNELS;
if (!isset($config['payout_channel'])) $config['payout_channel'] = $DEFAULT_PAYOUT;
if (!isset($config['ref_reward'])) $config['ref_reward'] = $DEFAULT_REF_REWARD;
if (!isset($config['daily_bonus'])) $config['daily_bonus'] = $DEFAULT_DAILY_BONUS;
if (!isset($config['bot_name'])) {
    $me = api_call('getMe');
    $config['bot_name'] = ($me['ok'] && isset($me['result']['username'])) ? $me['result']['username'] : 'MKBot';
}
if (!isset($config['total_users'])) $config['total_users'] = 0;
write_json($CONFIG_FILE, $config);

$users = read_json($USERS_FILE);

// ------------------ USER HELPERS ------------------
function get_user($uid) {
    global $users, $USERS_FILE;
    $k = (string)$uid;
    if (!isset($users[$k])) {
        $users[$k] = [
            'id' => $uid,
            'iban' => null,
            'balance' => 0.0,
            'ref_count' => 0,
            'withdrawn' => 0.0,
            'is_invited' => false,
            'last_bonus' => 0,
            'pending_action' => null,
            'created_at' => date('c')
        ];
        write_json($USERS_FILE, $users);
        $conf = read_json($GLOBALS['CONFIG_FILE']);
        $conf['total_users'] = (isset($conf['total_users']) ? intval($conf['total_users']) : 0) + 1;
        write_json($GLOBALS['CONFIG_FILE'], $conf);
    }
    return $users[$k];
}

function save_user($user) {
    global $users, $USERS_FILE;
    $users[(string)$user['id']] = $user;
    write_json($USERS_FILE, $users);
}

// ------------------ WEBHOOK (robust) ------------------
function detect_webhook_url() {
    global $AUTO_DETECT_WEBHOOK, $WEBHOOK_URL;
    if (!$AUTO_DETECT_WEBHOOK && !empty($WEBHOOK_URL)) return $WEBHOOK_URL;
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri = strtok($_SERVER['REQUEST_URI'] ?? '/bot.php', '?');
    $scheme = 'http://';
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == '443') $scheme = 'https://';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $xfp = $_SERVER['HTTP_X_FORWARDED_PROTO'];
        if (stripos($xfp, 'https') !== false) $scheme = 'https://';
    }
    return rtrim($scheme . $host . $uri, '/');
}

function ensure_webhook($url) {
    global $WEBHOOK_LOG;
    $info = api_call('getWebhookInfo');
    $current = isset($info['result']['url']) ? $info['result']['url'] : '';
    if ($current !== $url) {
        $res = api_call('setWebhook', ['url' => $url]);
        file_put_contents($WEBHOOK_LOG, "[".date('Y-m-d H:i:s')."] setWebhook -> ".json_encode($res).PHP_EOL, FILE_APPEND);
        return $res;
    } else {
        file_put_contents($WEBHOOK_LOG, "[".date('Y-m-d H:i:s')."] webhook ok: ".$current.PHP_EOL, FILE_APPEND);
        return ['ok' => true, 'result' => ['url' => $current]];
    }
}

// ------------------ CHANNEL JOIN CHECK ------------------
function user_in_all_channels($user_id) {
    $conf = read_json($GLOBALS['CONFIG_FILE']);
    $channels = $conf['channels'] ?? [];
    if (empty($channels)) return true;
    foreach ($channels as $ch) {
        $res = api_call('getChatMember', ['chat_id' => $ch, 'user_id' => $user_id]);
        if (!isset($res['ok']) || !$res['ok']) return false;
        $status = $res['result']['status'] ?? 'left';
        if (in_array($status, ['left','kicked'])) return false;
    }
    return true;
}

// ------------------ UI MARKUPS ------------------
function main_menu_markup($user_balance = 0.0) {
    $kb = [
        'inline_keyboard' => [
            [['text'=>'🏆 Liderlik Tablosu','callback_data'=>'LEADERBOARD']],
            [['text'=>'🎁 Bonus','callback_data'=>'/bonus'], ['text'=>'🏧 Para Çek','callback_data'=>'WITHDRAW']],
            [['text'=>'🔗 Referans','callback_data'=>'REFERRAL'], ['text'=>'📣 Paylaş & Kazan','switch_inline_query'=>'']],
            [['text'=>'ℹ️ Yardım','callback_data'=>'HELP']]
        ]
    ];
    return json_encode($kb);
}

function back_home_button() {
    return json_encode(['inline_keyboard'=> [[['text'=>'⬅️ Ana Menüye Dön','callback_data'=>'/home']]]]);
}

function join_markup() {
    $conf = read_json($GLOBALS['CONFIG_FILE']);
    $rows = [];
    $row = [];
    foreach ($conf['channels'] as $ch) $row[] = ['text'=>"Ziyaret et {$ch}", 'url'=>'https://t.me/'.ltrim($ch,'@')];
    if ($row) $rows[] = $row;
    $rows[] = [['text'=>'✅ Katıldım','callback_data'=>'I_JOINED']];
    return json_encode(['inline_keyboard'=>$rows]);
}

// ------------------ CALLBACK HANDLER ------------------
function handle_callback($callback) {
    $data = $callback['data'] ?? '';
    $cbid = $callback['id'] ?? '';
    $from = $callback['from'] ?? [];
    $user_id = $from['id'] ?? null;
    $chat = $callback['message']['chat'] ?? null;
    $chat_id = $chat['id'] ?? $user_id;
    if (!$user_id) return;

    // Home callback -> replace with full home (same as /start)
    if ($data === '/home') {
        $u = get_user($user_id);
        $conf = read_json($GLOBALS['CONFIG_FILE']);
        $reward = number_format($conf['ref_reward'] ?? $GLOBALS['DEFAULT_REF_REWARD'],2);
        $bonus = number_format($conf['daily_bonus'] ?? $GLOBALS['DEFAULT_DAILY_BONUS'],2);
        $channels_block = "";
        foreach ($conf['channels'] as $c) $channels_block .= "• {$c}\n";
        $payout = htmlspecialchars($conf['payout_channel'] ?? $GLOBALS['DEFAULT_PAYOUT']);
        $home = "<b>🎉 MKClub'a Hoş Geldiniz — Referans & Kazan</b>\n\n";
        $home .= "Arkadaşlarınızı referans linkinizle davet edin ve her referans için <b>₺{$reward}</b> kazanın!\n\n";
        $home .= "<b>💰 Referans ödülü:</b> <b>₺{$reward}</b>\n";
        $home .= "<b>🎁 Günlük bonus:</b> <b>₺{$bonus}</b>\n\n";
        $home .= "<b>📢 Gerekli kanal(lar):</b>\n{$channels_block}\n";
        $home .= "<b>🏦 Ödeme kanalı:</b> {$payout}\n\n";
        $home .= "<b>👤 Bakiyeniz</b>\n\n";
        $home .= "💰 <b>₺".number_format($u['balance'],2)."</b>\n\n";
        $home .= "Başlamak için aşağıdaki butonları kullanın.";
        answer_callback($cbid, "Ana Menü");
        send_message($chat_id, $home, ['reply_markup' => main_menu_markup($u['balance'])]);
        return;
    }

    if ($data === 'LEADERBOARD') {
        $top = get_leaderboard(10);
        $msg = "<b>🏆 En İyi Referanslar</b>\n\n";
        $i = 1;
        foreach ($top as $t) {
            $msg .= "{$i}. <code>{$t['id']}</code> — {$t['ref_count']} referans\n";
            $i++;
        }
        answer_callback($cbid, "Liderlik Tablosu");
        send_message($chat_id, $msg, ['reply_markup' => back_home_button()]);
        return;
    }

    if ($data === '/bonus') {
        // run bonus directly
        answer_callback($cbid, "Bonus talep ediliyor...");
        claim_bonus($user_id, $chat_id, $from);
        return;
    }

    if ($data === 'WITHDRAW') {
        answer_callback($cbid, "Para Çek");
        $msg = "<b>🏧 Para Çek</b>\n\n🏧 <b>Kullanım:</b>\n<code>/withdraw &lt;miktar&gt;</code>\n\n💡 <b>Örnek:</b>\n<code>/withdraw 50</code>\n\nMinimum: ₺".number_format($GLOBALS['MIN_WITHDRAW'],2);
        send_message($chat_id, $msg, ['reply_markup' => back_home_button()]);
        return;
    }

    if ($data === 'REFERRAL') {
        answer_callback($cbid, "Referans");
        $u = get_user($user_id);
        $me = api_call('getMe');
        $username = ($me['ok'] && isset($me['result']['username'])) ? $me['result']['username'] : null;
        $link = $username ? "https://t.me/{$username}?start={$user_id}" : "Referans linki hazır değil";
        $txt = "<b>🔗 Referans Linkiniz</b>\n\n{$link}\n\nReferans başına: <b>₺".number_format(read_json($GLOBALS['CONFIG_FILE'])['ref_reward'] ?? $GLOBALS['DEFAULT_REF_REWARD'],2)."</b>\nToplam Referans: <b>".$u['ref_count']."</b>";
        send_message($chat_id, $txt, ['reply_markup' => back_home_button()]);
        return;
    }

    if ($data === 'HELP') {
        answer_callback($cbid, "Yardım");
        $help = "<b>📘 MKClub — Yardım & Hızlı Başlangıç</b>\n\n";
        $help .= "<b>Nasıl çalışır</b>\nArkadaşlarınızı referans linkinizle davet edin. Katılan her arkadaş için <b>₺".number_format(read_json($GLOBALS['CONFIG_FILE'])['ref_reward'] ?? $GLOBALS['DEFAULT_REF_REWARD'],2)."</b> kazanırsınız.\n\n";
        $help .= "<b>Yaygın Komutlar</b>\n";
        $help .= "/start — Botu başlat (referans: <code>/start 12345</code>)\n";
        $help .= "/setiban — IBAN'ınızı kaydedin (bot soracaktır). Veya <code>/setiban TR12 3456 7890 1234 5678 9012 34</code> kullanın\n";
        $help .= "/withdraw — Para çek (örnek: <code>/withdraw 50</code>)\n";
        $help .= "/bonus — Günlük bonus (24 saatte bir)\n";
        $help .= "/referral — Referans linkinizi alın\n";
        $help .= "/balance — Bakiyenizi kontrol edin\n";
        $help .= "/leaderboard — En iyi referanslar\n\n";
        $help .= "Daha fazlası mı lazım?\nAdmin komutları dahil tüm komutları görmek için /cmd kullanın.";
        send_message($chat_id, $help, ['reply_markup' => back_home_button()]);
        return;
    }

    if ($data === 'I_JOINED') {
        if (user_in_all_channels($user_id)) {
            $uu = get_user($user_id);
            $uu['is_invited'] = true;
            save_user($uu);
            answer_callback($cbid, "Doğrulandı");
            send_message($chat_id, "<b>✅ Doğrulandı — gerekli kanallara katıldınız.</b>\nAşağıdaki menüyü kullanın.", ['reply_markup' => main_menu_markup($uu['balance'])]);
        } else {
            answer_callback($cbid, "Katılmadınız");
            send_message($chat_id, "<b>❗ Henüz tüm gerekli kanallara katılmadınız.</b>\nLütfen tüm gerekli kanallara katılın ve tekrar ✅ Katıldım butonuna basın.", ['reply_markup' => join_markup()]);
        }
        return;
    }

    answer_callback($cbid, "Bilinmeyen işlem");
}

// ------------------ ADMIN HELPERS ------------------
function is_admin($uid) {
    $conf = read_json($GLOBALS['CONFIG_FILE']);
    $admins = $conf['admins'] ?? [];
    return in_array((string)$uid, $admins);
}

function get_leaderboard($top = 10) {
    $all = read_json($GLOBALS['USERS_FILE']);
    $arr = [];
    foreach ($all as $u) $arr[] = $u;
    usort($arr, function($a,$b){ return ($b['ref_count'] - $a['ref_count']); });
    return array_slice($arr, 0, $top);
}

// ------------------ BONUS HELPER ------------------
function claim_bonus($user_id, $chat_id, $from) {
    $u = get_user($user_id);
    $now = time();
    $last = intval($u['last_bonus'] ?? 0);
    $conf = read_json($GLOBALS['CONFIG_FILE']);
    $daily = floatval($conf['daily_bonus'] ?? $GLOBALS['DEFAULT_DAILY_BONUS']);
    if ($now - $last < 24*3600) {
        $left = 24*3600 - ($now - $last);
        $h = floor($left/3600); $m = floor(($left%3600)/60);
        send_message($chat_id, "<b>⏳ Günlük bonus zaten alındı.</b>\nSonraki: {$h}s {$m}d", ['reply_markup' => back_home_button()]);
        return;
    }
    $u['balance'] = floatval($u['balance']) + $daily;
    $u['last_bonus'] = $now;
    save_user($u);
    send_message($chat_id, "<b>🎁 Günlük Bonus Alındı!</b>\n\n<b>₺".number_format($daily,2)."</b> aldınız\nYeni bakiye: <b>₺".number_format($u['balance'],2)."</b>", ['reply_markup' => back_home_button()]);
    return;
}

// ------------------ PROCESS UPDATE ------------------
try {
    // ensure webhook
    $webhook = detect_webhook_url();
    ensure_webhook($webhook);

    $raw = file_get_contents('php://input');
    if (!$raw) {
        if (isset($_GET['status']) || isset($_GET['test'])) {
            header('Content-Type: text/html; charset=utf-8');
            echo "<h2>MKClub Bot - Durum</h2><pre>";
            print_r(api_call('getWebhookInfo'));
            echo "</pre><p>Loglar: bot_log.txt</p>";
            exit;
        }
        exit;
    }

    file_put_contents($LOG_FILE, "[".date('Y-m-d H:i:s')."] RAW: ".$raw.PHP_EOL, FILE_APPEND);
    $update = json_decode($raw, true);
    if (!$update) exit;

    // callback
    if (isset($update['callback_query'])) {
        handle_callback($update['callback_query']);
        exit;
    }

    // message
    $message = $update['message'] ?? null;
    if (!$message) exit;
    $chat_id = $message['chat']['id'];
    $from = $message['from'] ?? [];
    $user_id = $from['id'] ?? null;
    $text = trim($message['text'] ?? '');

    $u = get_user($user_id);
    $joined = user_in_all_channels($user_id);

    // restrict until joined
    if (!$joined) {
        if (stripos($text, '/start') !== 0 && stripos($text, '/help') !== 0 && stripos($text, '/cmd') !== 0) {
            $msg = "<b>🔒 Erişim Kısıtlı</b>\n\nBotu kullanmadan önce gerekli kanal(lar)a katılmalısınız. Kanalları açmak için butona dokunun ve ardından ✅ Katıldım butonuna basın.";
            send_message($chat_id, $msg, ['reply_markup' => join_markup()]);
            exit;
        }
    }

    // ---------- /start ----------
    if (stripos($text, '/start') === 0) {
        $parts = preg_split('/\s+/', $text);
        $ref = $parts[1] ?? null;

        // referral awarding (first time)
        if ($ref && is_numeric($ref) && intval($ref) !== intval($user_id) && !$u['is_invited']) {
            $refUser = get_user(intval($ref));
            $reward = floatval(read_json($CONFIG_FILE)['ref_reward'] ?? $GLOBALS['DEFAULT_REF_REWARD']);
            $refUser['balance'] = floatval($refUser['balance']) + $reward;
            $refUser['ref_count'] = intval($refUser['ref_count']) + 1;
            save_user($refUser);
            send_message($ref, "<b>🎉 Referans Bonusu!</b>\nYeni bir kullanıcı davet ettiğiniz için <b>₺".number_format($reward,2)."</b> kazandınız.");
        }

        if (!user_in_all_channels($user_id)) {
            send_message($chat_id, "<b>🔒 Devam etmek için kanal(lar)ımıza katılın</b>\nKatılın ve ✅ Katıldım butonuna basın.", ['reply_markup' => join_markup()]);
            exit;
        } else {
            if (!$u['is_invited']) {
                $u['is_invited'] = true;
                save_user($u);
                // notify admins
                $username = isset($from['username']) ? ('@'.$from['username']) : 'KullanıcıAdıYok';
                $adminNotify = "<b>👤 Yeni Kullanıcı MKClub'a Katıldı</b>\n\nİsim: <code>".htmlspecialchars($from['first_name'] ?? 'Kullanıcı')."</code>\nKullanıcı ID: <code>{$user_id}</code>\nKullanıcı Adı: <code>{$username}</code>\nReferans: ".($ref ? "<code>{$ref}</code>" : "—")."\nZaman: ".date('Y-m-d H:i:s')." (TSİ)";
                $conf = read_json($CONFIG_FILE);
                foreach ($conf['admins'] as $adm) send_message($adm, $adminNotify);
            }
        }

        // home message with settings + balance
        $conf = read_json($CONFIG_FILE);
        $reward = number_format($conf['ref_reward'] ?? $GLOBALS['DEFAULT_REF_REWARD'], 2);
        $bonus = number_format($conf['daily_bonus'] ?? $GLOBALS['DEFAULT_DAILY_BONUS'], 2);
        $channels_block = "";
        foreach ($conf['channels'] as $c) $channels_block .= "• {$c}\n";
        $payout = htmlspecialchars($conf['payout_channel'] ?? $GLOBALS['DEFAULT_PAYOUT']);

        $home = "<b>🎉 MKClub'a Hoş Geldiniz — Referans & Kazan</b>\n\n";
        $home .= "Arkadaşlarınızı referans linkinizle davet edin ve her referans için <b>₺{$reward}</b> kazanın!\n\n";
        $home .= "<b>💰 Referans ödülü:</b> <b>₺{$reward}</b>\n";
        $home .= "<b>🎁 Günlük bonus:</b> <b>₺{$bonus}</b>\n\n";
        $home .= "<b>📢 Gerekli kanal(lar):</b>\n{$channels_block}\n";
        $home .= "<b>🏦 Ödeme kanalı:</b> {$payout}\n\n";
        $home .= "<b>👤 Bakiyeniz</b>\n\n";
        $home .= "💰 <b>₺".number_format($u['balance'],2)."</b>\n\n";
        $home .= "Başlamak için aşağıdaki butonları kullanın.";

        send_message($chat_id, $home, ['reply_markup' => main_menu_markup($u['balance'])]);
        exit;
    }

    // ---------- /cmd ----------
    if (stripos($text, '/cmd') === 0) {
        $cmds = "<b>⚙️ Tüm Komutlar — MKClub</b>\n\n";
        $cmds .= "<b>👤 Kullanıcı Komutları</b>\n";
        $cmds .= "/start — Botu başlat. Örnek: <code>/start 12345</code>\n";
        $cmds .= "/referral — Referans linkinizi alın\n";
        $cmds .= "/setiban — 🏦 IBAN'ınızı girin (Örnek: <code>/setiban TR12 3456 7890 1234 5678 9012 34</code>)\n";
        $cmds .= "/withdraw — 🏧 Kullanım: <code>/withdraw &lt;miktar&gt;</code>\n";
        $cmds .= "/bonus — 🎁 Günlük bonus al (24 saatte bir). Veya 🎁 Bonus butonuna basın.\n";
        $cmds .= "/leaderboard — 🏆 En iyi referansları görüntüleyin\n";
        $cmds .= "/help — 📘 Tam yardım metni\n\n";
        $cmds .= "<b>👑 Admin Komutları</b>\n";
        $cmds .= "/admin — Admin kontrol panelini göster\n";
        $cmds .= "/setref <miktar> — Referans ödülünü ayarla (örnek: <code>/setref 5</code>)\n";
        $cmds .= "/setbonus <miktar> — Günlük bonusu ayarla (örnek: <code>/setbonus 2</code>)\n";
        $cmds .= "/setpayout <@kanal> — Ödeme kanalını ayarla (örnek: <code>/setpayout @testmkdev</code>)\n";
        $cmds .= "/addchannel <@kanal> — Gerekli kanal ekle\n";
        $cmds .= "/delchannel <@kanal> — Gerekli kanalı kaldır\n";
        $cmds .= "/listchannels — Gerekli kanalları göster\n";
        $cmds .= "/addadmin <kullanıcı_id> — Admin ekle\n";
        $cmds .= "/deladmin <kullanıcı_id> — Admin kaldır\n";
        $cmds .= "/listadmins — Adminleri göster\n";
        $cmds .= "/broadcast <mesaj> — Tüm kullanıcılara yayın gönder\n";
        $cmds .= "/find <kullanıcı_id> — Kullanıcı bul\n";
        $cmds .= "/reset <kullanıcı_id> — Kullanıcıyı sıfırla\n";
        $cmds .= "/backup — users & config JSON indir\n";
        $cmds .= "/stats — İstatistikleri görüntüle\n";
        send_message($chat_id, $cmds, ['reply_markup' => back_home_button()]);
        exit;
    }

    // ---------- /help ----------
    if (stripos($text, '/help') === 0) {
        $help = "<b>📘 MKClub — Yardım & Hızlı Başlangıç</b>\n\n";
        $help .= "<b>Nasıl çalışır</b>\nArkadaşlarınızı referans linkinizle davet edin. Katılan her arkadaş için <b>₺".number_format(read_json($CONFIG_FILE)['ref_reward'] ?? $GLOBALS['DEFAULT_REF_REWARD'],2)."</b> kazanırsınız.\n\n";
        $help .= "<b>Yaygın Komutlar</b>\n";
        $help .= "/start — Botu başlat (referans: <code>/start 12345</code>)\n";
        $help .= "/setiban — IBAN'ınızı kaydedin (bot soracaktır). Veya <code>/setiban TR12 3456 7890 1234 5678 9012 34</code> kullanın\n";
        $help .= "/withdraw — Para çek (örnek: <code>/withdraw 50</code>)\n";
        $help .= "/bonus — Günlük bonus (24 saatte bir)\n";
        $help .= "/referral — Referans linkinizi alın\n";
        $help .= "/balance — Bakiyenizi kontrol edin\n";
        $help .= "/leaderboard — En iyi referanslar\n\n";
        $help .= "Daha fazlası mı lazım?\nAdmin komutları dahil tüm komutları görmek için /cmd kullanın.";
        send_message($chat_id, $help, ['reply_markup' => back_home_button()]);
        exit;
    }

    // ---------- ADMIN COMMANDS (implementations) ----------
    if (stripos($text, '/admin') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Admin komutlarına erişim yetkiniz yok.</b>"); exit; }
        $panel = "<b>👑 MKClub — Admin Kontrol Paneli</b>\n\n";
        $panel .= "<b>Finans</b>\n";
        $panel .= "• /setref <miktar> — Referans ödülünü ayarla (örnek: <code>/setref 5</code>)\n";
        $panel .= "• /setbonus <miktar> — Günlük bonusu ayarla (örnek: <code>/setbonus 2</code>)\n";
        $panel .= "• /setpayout <@kanal> — Ödeme kanalını ayarla\n\n";
        $panel .= "<b>Kanallar</b>\n";
        $panel .= "• /addchannel <@kanal>\n";
        $panel .= "• /delchannel <@kanal>\n";
        $panel .= "• /listchannels — Gerekli kanalları göster\n\n";
        $panel .= "<b>Adminler</b>\n";
        $panel .= "• /addadmin <kullanıcı_id>\n";
        $panel .= "• /deladmin <kullanıcı_id>\n";
        $panel .= "• /listadmins — Adminleri göster\n\n";
        $panel .= "<b>Kullanıcılar & Sistem</b>\n";
        $panel .= "• /broadcast <mesaj>\n";
        $panel .= "• /find <kullanıcı_id>\n";
        $panel .= "• /reset <kullanıcı_id>\n";
        $panel .= "• /backup — users & config indir\n";
        $panel .= "• /stats — İstatistikleri göster\n";
        send_message($chat_id, $panel, ['reply_markup' => back_home_button()]);
        exit;
    }

    if (stripos($text, '/setref') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Kullanım (admin): /setref &lt;miktar&gt; (örnek: /setref 5)</b>"); exit; }
        $parts = preg_split('/\s+/', $text);
        if (!isset($parts[1]) || !is_numeric($parts[1])) {
            send_message($chat_id, "<b>👑 Kullanım (admin): /setref &lt;miktar&gt;  (örnek: /setref 5)</b>"); exit;
        }
        $val = floatval($parts[1]);
        $conf = read_json($CONFIG_FILE);
        $conf['ref_reward'] = $val;
        write_json($CONFIG_FILE, $conf);
        send_message($chat_id, "<b>✅ Referans ödülü ₺".number_format($val,2)." olarak güncellendi</b>", ['reply_markup' => back_home_button()]);
        exit;
    }

    if (stripos($text, '/setbonus') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Kullanım (admin): /setbonus &lt;miktar&gt; (örnek: /setbonus 2)</b>"); exit; }
        $parts = preg_split('/\s+/', $text);
        if (!isset($parts[1]) || !is_numeric($parts[1])) {
            send_message($chat_id, "<b>👑 Kullanım (admin): /setbonus &lt;miktar&gt;  (örnek: /setbonus 2)</b>"); exit;
        }
        $val = floatval($parts[1]);
        $conf = read_json($CONFIG_FILE);
        $conf['daily_bonus'] = $val;
        write_json($CONFIG_FILE, $conf);
        send_message($chat_id, "<b>✅ Günlük bonus ₺".number_format($val,2)." olarak güncellendi</b>", ['reply_markup' => back_home_button()]);
        exit;
    }

    if (stripos($text, '/setpayout') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Kullanım (admin): /setpayout &lt;@kanal&gt;</b>"); exit; }
        $parts = preg_split('/\s+/', $text, 2);
        if (!isset($parts[1]) || !preg_match('/^@[\w\d_]+$/', trim($parts[1]))) {
            send_message($chat_id, "<b>👑 Kullanım: /setpayout @kanal (örnek: /setpayout @testmkdev)</b>"); exit;
        }
        $ch = trim($parts[1]);
        $conf = read_json($CONFIG_FILE);
        $conf['payout_channel'] = $ch;
        write_json($CONFIG_FILE, $conf);
        send_message($chat_id, "<b>✅ Ödeme kanalı {$ch} olarak ayarlandı</b>", ['reply_markup' => back_home_button()]);
        exit;
    }

    if (stripos($text, '/addchannel') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Kullanım (admin): /addchannel &lt;@kanal&gt;</b>"); exit; }
        $parts = preg_split('/\s+/', $text, 2);
        if (!isset($parts[1]) || !preg_match('/^@[\w\d_]+$/', trim($parts[1]))) {
            send_message($chat_id, "<b>👑 Kullanım: /addchannel @kanal</b>"); exit;
        }
        $ch = trim($parts[1]);
        $conf = read_json($CONFIG_FILE);
        $conf['channels'] = $conf['channels'] ?? [];
        $conf['channels'][] = $ch;
        $conf['channels'] = array_values(array_unique($conf['channels']));
        write_json($CONFIG_FILE, $conf);
        send_message($chat_id, "<b>✅ Kanal eklendi: {$ch}</b>", ['reply_markup' => back_home_button()]);
        exit;
    }

    if (stripos($text, '/delchannel') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Kullanım (admin): /delchannel &lt;@kanal&gt;</b>"); exit; }
        $parts = preg_split('/\s+/', $text, 2);
        if (!isset($parts[1]) || !preg_match('/^@[\w\d_]+$/', trim($parts[1]))) {
            send_message($chat_id, "<b>👑 Kullanım: /delchannel @kanal</b>"); exit;
        }
        $ch = trim($parts[1]);
        $conf = read_json($CONFIG_FILE);
        $conf['channels'] = array_values(array_diff($conf['channels'] ?? [], [$ch]));
        write_json($CONFIG_FILE, $conf);
        send_message($chat_id, "<b>✅ Kanal kaldırıldı: {$ch}</b>", ['reply_markup' => back_home_button()]);
        exit;
    }

    if (stripos($text, '/listchannels') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Sadece admin</b>"); exit; }
        $conf = read_json($CONFIG_FILE);
        $s = "<b>📢 Gerekli kanallar</b>\n\n";
        foreach ($conf['channels'] as $c) $s .= "• ".htmlspecialchars($c)."\n";
        send_message($chat_id, $s, ['reply_markup' => back_home_button()]);
        exit;
    }

    if (stripos($text, '/addadmin') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Kullanım (admin): /addadmin &lt;kullanıcı_id&gt;</b>"); exit; }
        $parts = preg_split('/\s+/', $text);
        if (!isset($parts[1]) || !is_numeric($parts[1])) { send_message($chat_id, "Kullanım: /addadmin <kullanıcı_id>"); exit; }
        $conf = read_json($CONFIG_FILE);
        $conf['admins'] = $conf['admins'] ?? [];
        $conf['admins'][] = (string)$parts[1];
        $conf['admins'] = array_values(array_unique($conf['admins']));
        write_json($CONFIG_FILE, $conf);
        send_message($chat_id, "<b>✅ Admin eklendi: ".$parts[1]."</b>", ['reply_markup' => back_home_button()]);
        exit;
    }

    if (stripos($text, '/deladmin') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Kullanım (admin): /deladmin &lt;kullanıcı_id&gt;</b>"); exit; }
        $parts = preg_split('/\s+/', $text);
        if (!isset($parts[1]) || !is_numeric($parts[1])) { send_message($chat_id, "Kullanım: /deladmin <kullanıcı_id>"); exit; }
        $conf = read_json($CONFIG_FILE);
        $conf['admins'] = array_values(array_diff($conf['admins'] ?? [], [(string)$parts[1]]));
        write_json($CONFIG_FILE, $conf);
        send_message($chat_id, "<b>✅ Admin kaldırıldı: ".$parts[1]."</b>", ['reply_markup' => back_home_button()]);
        exit;
    }

    if (stripos($text, '/listadmins') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Sadece admin</b>"); exit; }
        $conf = read_json($CONFIG_FILE);
        $s = "<b>👑 Adminler</b>\n\n";
        foreach ($conf['admins'] as $a) $s .= "• <code>{$a}</code>\n";
        send_message($chat_id, $s, ['reply_markup' => back_home_button()]);
        exit;
    }

    if (stripos($text, '/find') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Kullanım (admin): /find &lt;kullanıcı_id&gt;</b>"); exit; }
        $parts = preg_split('/\s+/', $text);
        if (!isset($parts[1]) || !is_numeric($parts[1])) { send_message($chat_id, "Kullanım: /find <kullanıcı_id>"); exit; }
        $target = (string)$parts[1];
        $all = read_json($USERS_FILE);
        if (isset($all[$target])) {
            $t = $all[$target];
            $s = "<b>👤 Kullanıcı: {$target}</b>\n\nIBAN: <code>".htmlspecialchars($t['iban'] ?? 'Ayarlanmadı')."</code>\nBakiye: <b>₺".number_format($t['balance'],2)."</b>\nReferanslar: <b>".$t['ref_count']."</b>\nÇekilen: <b>₺".number_format($t['withdrawn'],2)."</b>\nOluşturulma: ".$t['created_at'];
            send_message($chat_id, $s, ['reply_markup' => back_home_button()]);
        } else send_message($chat_id, "Kullanıcı bulunamadı.", ['reply_markup' => back_home_button()]);
        exit;
    }

    if (stripos($text, '/reset') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Kullanım (admin): /reset &lt;kullanıcı_id&gt;</b>"); exit; }
        $parts = preg_split('/\s+/', $text);
        if (!isset($parts[1]) || !is_numeric($parts[1])) { send_message($chat_id, "Kullanım: /reset <kullanıcı_id>"); exit; }
        $target = (string)$parts[1];
        $all = read_json($USERS_FILE);
        if (isset($all[$target])) {
            unset($all[$target]);
            write_json($USERS_FILE, $all);
            send_message($chat_id, "<b>✅ Kullanıcı sıfırlandı: ".$target."</b>", ['reply_markup' => back_home_button()]);
        } else send_message($chat_id, "Kullanıcı bulunamadı.", ['reply_markup' => back_home_button()]);
        exit;
    }

    if (stripos($text, '/backup') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Sadece admin</b>"); exit; }
        if (file_exists($USERS_FILE)) send_document($chat_id, $USERS_FILE, "users.json");
        if (file_exists($CONFIG_FILE)) send_document($chat_id, $CONFIG_FILE, "config.json");
        exit;
    }

    if (stripos($text, '/stats') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Sadece admin</b>"); exit; }
        $conf = read_json($CONFIG_FILE);
        $all = read_json($USERS_FILE);
        $total_users = $conf['total_users'] ?? count($all);
        $total_balance = 0;
        foreach ($all as $one) $total_balance += floatval($one['balance']);
        $txt = "<b>📊 Bot İstatistikleri</b>\n\nToplam kullanıcı: <b>{$total_users}</b>\nToplam bakiye: <b>₺".number_format($total_balance,2)."</b>\nReferans ödülü: <b>₺".number_format($conf['ref_reward'],2)."</b>\nGünlük bonus: <b>₺".number_format($conf['daily_bonus'],2)."</b>\nÖdeme kanalı: <b>".htmlspecialchars($conf['payout_channel'])."</b>";
        send_message($chat_id, $txt, ['reply_markup' => back_home_button()]);
        exit;
    }

    if (stripos($text, '/users') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Sadece admin</b>"); exit; }
        $all = read_json($USERS_FILE);
        send_message($chat_id, "<b>👥 Toplam kullanıcı:</b> ".count($all), ['reply_markup' => back_home_button()]);
        exit;
    }

    if (stripos($text, '/broadcast ') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Sadece admin</b>"); exit; }
        $msg = trim(substr($text, 10));
        if (!$msg) { send_message($chat_id, "<b>Kullanım:</b>\n/broadcast <mesaj>", ['reply_markup' => back_home_button()]); exit; }
        $all = read_json($USERS_FILE);
        $count = 0;
        foreach ($all as $one) {
            send_message($one['id'], "<b>📣 Yayın</b>\n\n".$msg);
            usleep(150000);
            $count++;
        }
        send_message($chat_id, "<b>✅ Yayın {$count} kullanıcıya gönderildi</b>", ['reply_markup' => back_home_button()]);
        exit;
    }

    // ---------- USER COMMANDS ----------
    // /setiban
    if (stripos($text, '/setiban') === 0) {
        $parts = preg_split('/\s+/', $text, 2);
        if (isset($parts[1]) && strlen(trim($parts[1])) >= 10) {
            $iban_raw = trim($parts[1]);
            if (!validate_iban($iban_raw)) {
                send_message($chat_id, "<b>❗ Geçersiz IBAN.</b>\n\n🏦 <b>Kullanım:</b>\n<code>/setiban TR12 3456 7890 1234 5678 9012 34</code>\n\n💡 <b>Örnek:</b>\n<code>/setiban TR12 3456 7890 1234 5678 9012 34</code>\n\nIBAN'ınız TR ile başlamalı ve 26 karakter olmalıdır.", ['reply_markup' => back_home_button()]);
                exit;
            }
            $u['iban'] = format_iban($iban_raw);
            $u['pending_action'] = null;
            save_user($u);
            send_message($chat_id, "<b>✅ IBAN kaydedildi:</b>\n<code>".htmlspecialchars(format_iban($iban_raw))."</code>", ['reply_markup' => back_home_button()]);
        } else {
            $u['pending_action'] = 'awaiting_iban';
            save_user($u);
            send_message($chat_id, "<b>🏦 IBAN'ınızı girin</b>\n\n🏦 <b>Kullanım:</b>\n<code>/setiban TR12 3456 7890 1234 5678 9012 34</code>\n\n💡 <b>Örnek:</b>\n<code>TR12 3456 7890 1234 5678 9012 34</code>\n\nIBAN'ınız TR ile başlamalı ve 26 karakter olmalıdır.", ['reply_markup' => back_home_button()]);
        }
        exit;
    }

    // pending iban reply
    if (!empty($u['pending_action']) && $u['pending_action'] === 'awaiting_iban') {
        if (validate_iban($text)) {
            $u['iban'] = format_iban($text);
            $u['pending_action'] = null;
            save_user($u);
            send_message($chat_id, "<b>✅ IBAN kaydedildi:</b>\n<code>".htmlspecialchars(format_iban($text))."</code>", ['reply_markup' => back_home_button()]);
        } else {
            send_message($chat_id, "<b>❗ Bu geçerli bir IBAN değil.</b>\n\n🏦 <b>Kullanım:</b>\n<code>/setiban TR12 3456 7890 1234 5678 9012 34</code>\n\n💡 <b>Örnek:</b>\n<code>TR12 3456 7890 1234 5678 9012 34</code>\n\nIBAN'ınız TR ile başlamalı ve 26 karakter olmalıdır.", ['reply_markup' => back_home_button()]);
        }
        exit;
    }

    // /withdraw
    if (stripos($text, '/withdraw') === 0) {
        $parts = preg_split('/\s+/', $text);
        $amt = isset($parts[1]) ? floatval($parts[1]) : 0;
        if ($amt <= 0) {
            send_message($chat_id, "<b>🏧 Kullanım:</b>\n\n<code>/withdraw &lt;miktar&gt;</code>\n\n💡 <b>Örnek:</b>\n<code>/withdraw 50</code>\n\nMinimum: ₺".number_format($MIN_WITHDRAW,2), ['reply_markup' => back_home_button()]);
            exit;
        }
        if ($amt < $MIN_WITHDRAW) { send_message($chat_id, "<b>⚠️ Minimum çekim ₺".number_format($MIN_WITHDRAW,2)."</b>", ['reply_markup' => back_home_button()]); exit; }
        if (empty($u['iban'])) { send_message($chat_id, "<b>❗ Önce IBAN'ınızı ayarlayın:</b>\n\n🏦 <b>Kullanım:</b>\n<code>/setiban TR12 3456 7890 1234 5678 9012 34</code>", ['reply_markup' => back_home_button()]); exit; }
        if ($amt > floatval($u['balance'])) { send_message($chat_id, "<b>❗ Yetersiz bakiye.</b>\nBakiyeniz: <b>₺".number_format($u['balance'],2)."</b>", ['reply_markup' => back_home_button()]); exit; }

        // process withdraw
        $u['balance'] = floatval($u['balance']) - $amt;
        $u['withdrawn'] = floatval($u['withdrawn']) + $amt;
        save_user($u);

        // notify payout channel & admins
        $notice = "<b>🏦 Yeni Para Çekme Talebi</b>\n\n👤 Kullanıcı: ".(isset($from['username']) ? '@'.$from['username'] : $user_id)." (<code>{$user_id}</code>)\n💰 Miktar: <b>₺".number_format($amt,2)."</b>\n🏦 IBAN: <code>".htmlspecialchars($u['iban'])."</code>\n🕒 Zaman: ".date('Y-m-d H:i:s')." (TSİ)\n\nLütfen manuel olarak işleyin.";
        $conf = read_json($CONFIG_FILE);
        if (!empty($conf['payout_channel'])) send_message($conf['payout_channel'], $notice);
        foreach ($conf['admins'] as $adm) send_message($adm, $notice);

        $payout_info = !empty($conf['payout_channel']) ? "Ödeme güncellemelerini ödeme kanalımızdan alacaksınız: {$conf['payout_channel']}" : "Admin ödemenizi yakında işleyecektir.";
        $user_msg = "<b>🎉 Para Çekme Talebi Başarıyla Gönderildi!</b>\n\n💰 Miktar: <b>₺".number_format($amt,2)."</b>\n🏦 IBAN: <code>".htmlspecialchars($u['iban'])."</code>\n📢 Durum: Beklemede — yakında işlenecek.\n\n{$payout_info}";
        send_message($chat_id, $user_msg, ['reply_markup' => back_home_button()]);
        exit;
    }

    // /referral
    if (stripos($text, '/referral') === 0 || strtolower(trim($text)) === 'referral') {
        $me = api_call('getMe');
        $username = ($me['ok'] && isset($me['result']['username'])) ? $me['result']['username'] : null;
        if ($username) {
            $link = "https://t.me/".$username."?start=".$user_id;
            send_message($chat_id, "<b>🔗 Referans Linkiniz</b>\n\n".$link."\nReferans başına: <b>₺".number_format(read_json($CONFIG_FILE)['ref_reward'] ?? $GLOBALS['DEFAULT_REF_REWARD'],2)."</b>\nToplam Referans: <b>".$u['ref_count']."</b>", ['reply_markup' => back_home_button()]);
        } else send_message($chat_id, "Bot kullanıcı adı mevcut değil.", ['reply_markup' => back_home_button()]);
        exit;
    }

    // /balance quick
    if (stripos($text, '/balance') === 0 || strtolower(trim($text)) === 'bakiyem') {
        send_message($chat_id, "<b>👤 Bakiyeniz</b>\n\n💰 <b>₺".number_format($u['balance'],2)."</b>\n\nDaha fazla kazanmak için /referral kullanın.", ['reply_markup' => back_home_button()]);
        exit;
    }

    // /bonus text command
    if (stripos($text, '/bonus') === 0) {
        claim_bonus($user_id, $chat_id, $from);
        exit;
    }

    // /leaderboard
    if (stripos($text, '/leaderboard') === 0) {
        $top = get_leaderboard(10);
        $msg = "<b>🏆 En İyi Referanslar</b>\n\n";
        $i = 1;
        foreach ($top as $t) {
            $msg .= "{$i}. <code>{$t['id']}</code> — {$t['ref_count']} referans\n";
            $i++;
        }
        send_message($chat_id, $msg, ['reply_markup' => back_home_button()]);
        exit;
    }

    // fallback: show home-like status
    $conf = read_json($CONFIG_FILE);
    $reward = number_format($conf['ref_reward'] ?? $GLOBALS['DEFAULT_REF_REWARD'], 2);
    $bonus = number_format($conf['daily_bonus'] ?? $GLOBALS['DEFAULT_DAILY_BONUS'], 2);
    $channels_block = "";
    foreach ($conf['channels'] as $c) $channels_block .= "• {$c}\n";
    $payout = htmlspecialchars($conf['payout_channel'] ?? $GLOBALS['DEFAULT_PAYOUT']);
    $home = "<b>🎉 MKClub'a Hoş Geldiniz — Referans & Kazan</b>\n\n";
    $home .= "Arkadaşlarınızı referans linkinizle davet edin ve her referans için <b>₺{$reward}</b> kazanın!\n\n";
    $home .= "<b>💰 Referans ödülü:</b> <b>₺{$reward}</b>\n";
    $home .= "<b>🎁 Günlük bonus:</b> <b>₺{$bonus}</b>\n\n";
    $home .= "<b>📢 Gerekli kanal(lar):</b>\n{$channels_block}\n";
    $home .= "<b>🏦 Ödeme kanalı:</b> {$payout}\n\n";
    $home .= "<b>👤 Bakiyeniz</b>\n\n";
    $home .= "💰 <b>₺".number_format($u['balance'],2)."</b>\n\n";
    $home .= "Başlamak için aşağıdaki butonları kullanın.";
    send_message($chat_id, $home, ['reply_markup' => main_menu_markup($u['balance'])]);

} catch (Exception $e) {
    log_line("Exception: " . $e->getMessage());
    exit;
}
?>
