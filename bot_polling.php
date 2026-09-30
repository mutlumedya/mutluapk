<?php
/**
 * MKClub - Final Premium Refer & Earn Bot (Long Polling - HTTPS GEREKMEZ)
 * Türkçe + IBAN versiyonu
 *
 * KURULUM:
 * 1. Bu dosyayı /m3u/bot_polling.php olarak kaydet
 * 2. data/ klasörünü sil (eski config kalmasın)
 * 3. Tarayıcıdan 1 kez aç: http://45.158.14.16/m3u/bot_polling.php
 * 4. Telegram'da bota /start yaz
 */

date_default_timezone_set('Europe/Istanbul');
error_reporting(0);
set_time_limit(0);

// ------------------ CONFIG ------------------
$BOT_TOKEN   = "8463223086:AAEFDkpp71qDmPGi0zYTc-F6I3dTVgzxv3s";
$DEFAULT_ADMIN = "8693437066";
$DEFAULT_CHANNELS = ["@kanalfturkiye"];
$DEFAULT_PAYOUT = "@zemtvapk";
$DEFAULT_REF_REWARD = 5.0;
$DEFAULT_DAILY_BONUS = 2.0;
$MIN_WITHDRAW = 1.0;

$API_BASE = "https://api.telegram.org/bot{$BOT_TOKEN}/";

// ------------------ FILES & PATHS ------------------
$BASE_DIR = __DIR__;
$DATA_DIR = $BASE_DIR . DIRECTORY_SEPARATOR . "data";
if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0755, true);

$USERS_FILE   = $DATA_DIR . DIRECTORY_SEPARATOR . "users.json";
$CONFIG_FILE  = $DATA_DIR . DIRECTORY_SEPARATOR . "config.json";
$LOG_FILE     = $BASE_DIR . DIRECTORY_SEPARATOR . "bot_log.txt";
$OFFSET_FILE  = $DATA_DIR . DIRECTORY_SEPARATOR . "offset.txt";

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
    curl_setopt($ch, CURLOPT_TIMEOUT, 40);
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
    return api_call('answerCallbackQuery', [
        'callback_query_id' => $callback_id,
        'text' => $text,
        'show_alert' => $show_alert
    ]);
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
    file_put_contents($LOG_FILE, "[".date('Y-m-d H:i:s')."] ".$msg.PHP_EOL, FILE_APPEND);
}

function validate_iban($iban) {
    $iban = strtoupper(str_replace(' ', '', trim($iban)));
    if (!preg_match('/^TR\d{24}$/', $iban)) return false;
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    for ($i = 0; $i < strlen($rearranged); $i++) {
        $ch = $rearranged[$i];
        if (ctype_digit($ch)) $numeric .= $ch;
        else $numeric .= (ord($ch) - 55);
    }
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

// ------------------ CHANNEL CHECK ------------------
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

// ------------------ ADMIN HELPERS ------------------
function is_admin($uid) {
    $conf = read_json($GLOBALS['CONFIG_FILE']);
    return in_array((string)$uid, $conf['admins'] ?? []);
}

function get_leaderboard($top = 10) {
    $all = read_json($GLOBALS['USERS_FILE']);
    $arr = array_values($all);
    usort($arr, function($a,$b){ return ($b['ref_count'] - $a['ref_count']); });
    return array_slice($arr, 0, $top);
}

// ------------------ UI MARKUPS ------------------
function main_menu_markup() {
    return json_encode(['inline_keyboard' => [
        [['text'=>'🏆 Liderlik Tablosu','callback_data'=>'LEADERBOARD']],
        [['text'=>'🎁 Bonus','callback_data'=>'/bonus'], ['text'=>'🏧 Para Çek','callback_data'=>'WITHDRAW']],
        [['text'=>'🔗 Referans','callback_data'=>'REFERRAL'], ['text'=>'📣 Paylaş & Kazan','switch_inline_query'=>'']],
        [['text'=>'ℹ️ Yardım','callback_data'=>'HELP']]
    ]]);
}

function back_home_button() {
    return json_encode(['inline_keyboard'=> [[['text'=>'⬅️ Ana Menüye Dön','callback_data'=>'/home']]]]);
}

function join_markup() {
    $conf = read_json($GLOBALS['CONFIG_FILE']);
    $rows = [];
    $row = [];
    foreach ($conf['channels'] as $ch) {
        $row[] = ['text'=>"Ziyaret et {$ch}", 'url'=>'https://t.me/'.ltrim($ch,'@')];
    }
    if ($row) $rows[] = $row;
    $rows[] = [['text'=>'✅ Katıldım','callback_data'=>'I_JOINED']];
    return json_encode(['inline_keyboard'=>$rows]);
}

// ------------------ BONUS ------------------
function claim_bonus($user_id, $chat_id) {
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
}

// ------------------ HOME ------------------
function show_home($chat_id, $user_id) {
    $u = get_user($user_id);
    $conf = read_json($GLOBALS['CONFIG_FILE']);
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
    send_message($chat_id, $home, ['reply_markup' => main_menu_markup()]);
}

// ------------------ CALLBACK HANDLER ------------------
function handle_callback($callback) {
    $data = $callback['data'] ?? '';
    $cbid = $callback['id'] ?? '';
    $from = $callback['from'] ?? [];
    $user_id = $from['id'] ?? null;
    $chat_id = $callback['message']['chat']['id'] ?? $user_id;
    if (!$user_id) return;

    if ($data === '/home') { answer_callback($cbid, "Ana Menü"); show_home($chat_id, $user_id); return; }

    if ($data === 'LEADERBOARD') {
        $top = get_leaderboard(10);
        $msg = "<b>🏆 En İyi Referanslar</b>\n\n";
        $i = 1;
        foreach ($top as $t) { $msg .= "{$i}. <code>{$t['id']}</code> — {$t['ref_count']} referans\n"; $i++; }
        answer_callback($cbid, "Liderlik");
        send_message($chat_id, $msg, ['reply_markup' => back_home_button()]);
        return;
    }

    if ($data === '/bonus') { answer_callback($cbid, "Bonus talep ediliyor..."); claim_bonus($user_id, $chat_id); return; }

    if ($data === 'WITHDRAW') {
        answer_callback($cbid, "Para Çek");
        send_message($chat_id, "<b>🏧 Para Çek</b>\n\n🏧 <b>Kullanım:</b>\n<code>/withdraw &lt;miktar&gt;</code>\n\n💡 <b>Örnek:</b>\n<code>/withdraw 50</code>\n\nMinimum: ₺".number_format($GLOBALS['MIN_WITHDRAW'],2), ['reply_markup' => back_home_button()]);
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
        $help .= "/start — Botu başlat\n";
        $help .= "/setiban — IBAN'ınızı kaydedin\n";
        $help .= "/withdraw — Para çek\n";
        $help .= "/bonus — Günlük bonus\n";
        $help .= "/referral — Referans linkiniz\n";
        $help .= "/balance — Bakiyeniz\n";
        $help .= "/leaderboard — En iyi referanslar\n\n";
        $help .= "Tüm komutlar için /cmd kullanın.";
        send_message($chat_id, $help, ['reply_markup' => back_home_button()]);
        return;
    }

    if ($data === 'I_JOINED') {
        if (user_in_all_channels($user_id)) {
            $uu = get_user($user_id);
            $uu['is_invited'] = true;
            save_user($uu);
            answer_callback($cbid, "Doğrulandı");
            send_message($chat_id, "<b>✅ Doğrulandı — gerekli kanallara katıldınız.</b>\nAşağıdaki menüyü kullanın.", ['reply_markup' => main_menu_markup()]);
        } else {
            answer_callback($cbid, "Katılmadınız");
            send_message($chat_id, "<b>❗ Henüz tüm gerekli kanallara katılmadınız.</b>\nLütfen tüm gerekli kanallara katılın ve tekrar ✅ Katıldım butonuna basın.", ['reply_markup' => join_markup()]);
        }
        return;
    }

    answer_callback($cbid, "Bilinmeyen işlem");
}

// ------------------ MESSAGE PROCESSOR ------------------
function process_message($message) {
    $chat_id = $message['chat']['id'];
    $from = $message['from'] ?? [];
    $user_id = $from['id'] ?? null;
    $text = trim($message['text'] ?? '');
    if (!$user_id) return;

    log_line("MSG from {$user_id}: {$text}");

    $u = get_user($user_id);
    $joined = user_in_all_channels($user_id);

    if (!$joined && stripos($text, '/start') !== 0 && stripos($text, '/help') !== 0 && stripos($text, '/cmd') !== 0) {
        send_message($chat_id, "<b>🔒 Erişim Kısıtlı</b>\n\nBotu kullanmadan önce gerekli kanal(lar)a katılmalısınız.", ['reply_markup' => join_markup()]);
        return;
    }

    // ---------- /start ----------
    if (stripos($text, '/start') === 0) {
        $parts = preg_split('/\s+/', $text);
        $ref = $parts[1] ?? null;

        if ($ref && is_numeric($ref) && intval($ref) !== intval($user_id) && !$u['is_invited']) {
            $refUser = get_user(intval($ref));
            $reward = floatval(read_json($GLOBALS['CONFIG_FILE'])['ref_reward'] ?? $GLOBALS['DEFAULT_REF_REWARD']);
            $refUser['balance'] = floatval($refUser['balance']) + $reward;
            $refUser['ref_count'] = intval($refUser['ref_count']) + 1;
            save_user($refUser);
            send_message($ref, "<b>🎉 Referans Bonusu!</b>\nYeni bir kullanıcı davet ettiğiniz için <b>₺".number_format($reward,2)."</b> kazandınız.");
        }

        if (!user_in_all_channels($user_id)) {
            send_message($chat_id, "<b>🔒 Devam etmek için kanal(lar)ımıza katılın</b>\nKatılın ve ✅ Katıldım butonuna basın.", ['reply_markup' => join_markup()]);
            return;
        } else {
            if (!$u['is_invited']) {
                $u['is_invited'] = true;
                save_user($u);
                $username = isset($from['username']) ? ('@'.$from['username']) : 'KullanıcıAdıYok';
                $adminNotify = "<b>👤 Yeni Kullanıcı MKClub'a Katıldı</b>\n\nİsim: <code>".htmlspecialchars($from['first_name'] ?? 'Kullanıcı')."</code>\nKullanıcı ID: <code>{$user_id}</code>\nKullanıcı Adı: <code>{$username}</code>\nReferans: ".($ref ? "<code>{$ref}</code>" : "—")."\nZaman: ".date('Y-m-d H:i:s')." (TSİ)";
                $conf = read_json($GLOBALS['CONFIG_FILE']);
                foreach ($conf['admins'] as $adm) send_message($adm, $adminNotify);
            }
        }
        show_home($chat_id, $user_id);
        return;
    }

    // ---------- /cmd ----------
    if (stripos($text, '/cmd') === 0) {
        $cmds = "<b>⚙️ Tüm Komutlar — MKClub</b>\n\n";
        $cmds .= "<b>👤 Kullanıcı Komutları</b>\n";
        $cmds .= "/start — Botu başlat\n";
        $cmds .= "/referral — Referans linkinizi alın\n";
        $cmds .= "/setiban — 🏦 IBAN'ınızı girin\n";
        $cmds .= "/withdraw — 🏧 Para çek\n";
        $cmds .= "/bonus — 🎁 Günlük bonus\n";
        $cmds .= "/leaderboard — 🏆 Liderlik\n";
        $cmds .= "/balance — 💰 Bakiye\n";
        $cmds .= "/help — 📘 Yardım\n\n";
        $cmds .= "<b>👑 Admin Komutları</b>\n";
        $cmds .= "/admin — Admin paneli\n";
        $cmds .= "/setref — Referans ödülü\n";
        $cmds .= "/setbonus — Günlük bonus\n";
        $cmds .= "/setpayout — Ödeme kanalı\n";
        $cmds .= "/addchannel — Kanal ekle\n";
        $cmds .= "/delchannel — Kanal kaldır\n";
        $cmds .= "/listchannels — Kanalları göster\n";
        $cmds .= "/addadmin — Admin ekle\n";
        $cmds .= "/deladmin — Admin kaldır\n";
        $cmds .= "/listadmins — Adminleri göster\n";
        $cmds .= "/broadcast — Yayın gönder\n";
        $cmds .= "/find — Kullanıcı bul\n";
        $cmds .= "/reset — Kullanıcı sıfırla\n";
        $cmds .= "/stats — İstatistikler\n";
        send_message($chat_id, $cmds, ['reply_markup' => back_home_button()]);
        return;
    }

    // ---------- /help ----------
    if (stripos($text, '/help') === 0) {
        $help = "<b>📘 MKClub — Yardım</b>\n\n";
        $help .= "/start — Botu başlat\n";
        $help .= "/setiban — IBAN'ınızı kaydedin\n";
        $help .= "/withdraw — Para çek\n";
        $help .= "/bonus — Günlük bonus\n";
        $help .= "/referral — Referans linkiniz\n";
        $help .= "/balance — Bakiyeniz\n";
        $help .= "/leaderboard — En iyi referanslar\n\n";
        $help .= "Tüm komutlar için /cmd kullanın.";
        send_message($chat_id, $help, ['reply_markup' => back_home_button()]);
        return;
    }

    // ---------- /balance ----------
    if (stripos($text, '/balance') === 0) {
        send_message($chat_id, "<b>👤 Bakiyeniz</b>\n\n💰 <b>₺".number_format($u['balance'],2)."</b>\n\nDaha fazla kazanmak için /referral kullanın.", ['reply_markup' => back_home_button()]);
        return;
    }

    // ---------- /bonus ----------
    if (stripos($text, '/bonus') === 0) { claim_bonus($user_id, $chat_id); return; }

    // ---------- /referral ----------
    if (stripos($text, '/referral') === 0) {
        $me = api_call('getMe');
        $username = ($me['ok'] && isset($me['result']['username'])) ? $me['result']['username'] : null;
        if ($username) {
            send_message($chat_id, "<b>🔗 Referans Linkiniz</b>\n\nhttps://t.me/{$username}?start={$user_id}\n\nReferans başına: <b>₺".number_format(read_json($GLOBALS['CONFIG_FILE'])['ref_reward'] ?? $GLOBALS['DEFAULT_REF_REWARD'],2)."</b>\nToplam Referans: <b>".$u['ref_count']."</b>", ['reply_markup' => back_home_button()]);
        } else send_message($chat_id, "Bot kullanıcı adı mevcut değil.", ['reply_markup' => back_home_button()]);
        return;
    }

    // ---------- /leaderboard ----------
    if (stripos($text, '/leaderboard') === 0) {
        $top = get_leaderboard(10);
        $msg = "<b>🏆 En İyi Referanslar</b>\n\n";
        $i = 1;
        foreach ($top as $t) { $msg .= "{$i}. <code>{$t['id']}</code> — {$t['ref_count']} referans\n"; $i++; }
        send_message($chat_id, $msg, ['reply_markup' => back_home_button()]);
        return;
    }

    // ---------- /setiban ----------
    if (stripos($text, '/setiban') === 0) {
        $parts = preg_split('/\s+/', $text, 2);
        if (isset($parts[1]) && strlen(trim($parts[1])) >= 10) {
            if (!validate_iban($parts[1])) {
                send_message($chat_id, "<b>❗ Geçersiz IBAN.</b>\n\n🏦 <b>Kullanım:</b>\n<code>/setiban TR12 3456 7890 1234 5678 9012 34</code>", ['reply_markup' => back_home_button()]);
                return;
            }
            $u['iban'] = format_iban($parts[1]);
            $u['pending_action'] = null;
            save_user($u);
            send_message($chat_id, "<b>✅ IBAN kaydedildi:</b>\n<code>".htmlspecialchars(format_iban($parts[1]))."</code>", ['reply_markup' => back_home_button()]);
        } else {
            $u['pending_action'] = 'awaiting_iban';
            save_user($u);
            send_message($chat_id, "<b>🏦 IBAN'ınızı girin</b>\n\n🏦 <b>Kullanım:</b>\n<code>/setiban TR12 3456 7890 1234 5678 9012 34</code>\n\n💡 IBAN'ınız TR ile başlamalı ve 26 karakter olmalıdır.", ['reply_markup' => back_home_button()]);
        }
        return;
    }

    // ---------- pending IBAN ----------
    if (!empty($u['pending_action']) && $u['pending_action'] === 'awaiting_iban') {
        if (validate_iban($text)) {
            $u['iban'] = format_iban($text);
            $u['pending_action'] = null;
            save_user($u);
            send_message($chat_id, "<b>✅ IBAN kaydedildi:</b>\n<code>".htmlspecialchars(format_iban($text))."</code>", ['reply_markup' => back_home_button()]);
        } else {
            send_message($chat_id, "<b>❗ Bu geçerli bir IBAN değil.</b>\n\n💡 IBAN'ınız TR ile başlamalı ve 26 karakter olmalıdır.\nÖrnek: <code>TR12 3456 7890 1234 5678 9012 34</code>", ['reply_markup' => back_home_button()]);
        }
        return;
    }

    // ---------- /withdraw ----------
    if (stripos($text, '/withdraw') === 0) {
        $parts = preg_split('/\s+/', $text);
        $amt = isset($parts[1]) ? floatval($parts[1]) : 0;
        if ($amt <= 0) {
            send_message($chat_id, "<b>🏧 Kullanım:</b>\n\n<code>/withdraw &lt;miktar&gt;</code>\n\n💡 <b>Örnek:</b>\n<code>/withdraw 50</code>\n\nMinimum: ₺".number_format($GLOBALS['MIN_WITHDRAW'],2), ['reply_markup' => back_home_button()]);
            return;
        }
        if ($amt < $GLOBALS['MIN_WITHDRAW']) { send_message($chat_id, "<b>⚠️ Minimum çekim ₺".number_format($GLOBALS['MIN_WITHDRAW'],2)."</b>", ['reply_markup' => back_home_button()]); return; }
        if (empty($u['iban'])) { send_message($chat_id, "<b>❗ Önce IBAN'ınızı ayarlayın:</b>\n\n🏦 <code>/setiban TR12 3456 7890 1234 5678 9012 34</code>", ['reply_markup' => back_home_button()]); return; }
        if ($amt > floatval($u['balance'])) { send_message($chat_id, "<b>❗ Yetersiz bakiye.</b>\nBakiyeniz: <b>₺".number_format($u['balance'],2)."</b>", ['reply_markup' => back_home_button()]); return; }

        $u['balance'] = floatval($u['balance']) - $amt;
        $u['withdrawn'] = floatval($u['withdrawn']) + $amt;
        save_user($u);

        $notice = "<b>🏦 Yeni Para Çekme Talebi</b>\n\n👤 Kullanıcı: ".(isset($from['username']) ? '@'.$from['username'] : $user_id)." (<code>{$user_id}</code>)\n💰 Miktar: <b>₺".number_format($amt,2)."</b>\n🏦 IBAN: <code>".htmlspecialchars($u['iban'])."</code>\n🕒 Zaman: ".date('Y-m-d H:i:s')." (TSİ)\n\nLütfen manuel olarak işleyin.";
        $conf = read_json($GLOBALS['CONFIG_FILE']);
        if (!empty($conf['payout_channel'])) send_message($conf['payout_channel'], $notice);
        foreach ($conf['admins'] as $adm) send_message($adm, $notice);

        send_message($chat_id, "<b>🎉 Para Çekme Talebi Alındı!</b>\n\n💰 Miktar: <b>₺".number_format($amt,2)."</b>\n🏦 IBAN: <code>".htmlspecialchars($u['iban'])."</code>\n📢 Durum: Beklemede.", ['reply_markup' => back_home_button()]);
        return;
    }

    // ---------- ADMIN: /admin ----------
    if (stripos($text, '/admin') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "<b>🚫 Admin komutlarına erişim yetkiniz yok.</b>"); return; }
        $panel = "<b>👑 MKClub — Admin Kontrol Paneli</b>\n\n";
        $panel .= "/setref <miktar> — Referans ödülü\n";
        $panel .= "/setbonus <miktar> — Günlük bonus\n";
        $panel .= "/setpayout <@kanal> — Ödeme kanalı\n";
        $panel .= "/addchannel <@kanal> — Kanal ekle\n";
        $panel .= "/delchannel <@kanal> — Kanal kaldır\n";
        $panel .= "/listchannels — Kanalları göster\n";
        $panel .= "/addadmin <id> — Admin ekle\n";
        $panel .= "/deladmin <id> — Admin kaldır\n";
        $panel .= "/listadmins — Adminleri göster\n";
        $panel .= "/broadcast <mesaj> — Yayın\n";
        $panel .= "/find <id> — Kullanıcı bul\n";
        $panel .= "/reset <id> — Sıfırla\n";
        $panel .= "/stats — İstatistikler";
        send_message($chat_id, $panel, ['reply_markup' => back_home_button()]);
        return;
    }

    if (stripos($text, '/setref') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "🚫 Admin"); return; }
        $parts = preg_split('/\s+/', $text);
        if (!isset($parts[1]) || !is_numeric($parts[1])) { send_message($chat_id, "Kullanım: /setref 5"); return; }
        $conf = read_json($GLOBALS['CONFIG_FILE']);
        $conf['ref_reward'] = floatval($parts[1]);
        write_json($GLOBALS['CONFIG_FILE'], $conf);
        send_message($chat_id, "<b>✅ Referans ödülü ₺".number_format($parts[1],2)."</b>", ['reply_markup' => back_home_button()]);
        return;
    }

    if (stripos($text, '/setbonus') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "🚫 Admin"); return; }
        $parts = preg_split('/\s+/', $text);
        if (!isset($parts[1]) || !is_numeric($parts[1])) { send_message($chat_id, "Kullanım: /setbonus 2"); return; }
        $conf = read_json($GLOBALS['CONFIG_FILE']);
        $conf['daily_bonus'] = floatval($parts[1]);
        write_json($GLOBALS['CONFIG_FILE'], $conf);
        send_message($chat_id, "<b>✅ Günlük bonus ₺".number_format($parts[1],2)."</b>", ['reply_markup' => back_home_button()]);
        return;
    }

    if (stripos($text, '/setpayout') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "🚫 Admin"); return; }
        $parts = preg_split('/\s+/', $text, 2);
        if (!isset($parts[1])) { send_message($chat_id, "Kullanım: /setpayout @kanal"); return; }
        $conf = read_json($GLOBALS['CONFIG_FILE']);
        $conf['payout_channel'] = trim($parts[1]);
        write_json($GLOBALS['CONFIG_FILE'], $conf);
        send_message($chat_id, "<b>✅ Ödeme kanalı: {$parts[1]}</b>", ['reply_markup' => back_home_button()]);
        return;
    }

    if (stripos($text, '/addchannel') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "🚫 Admin"); return; }
        $parts = preg_split('/\s+/', $text, 2);
        if (!isset($parts[1])) { send_message($chat_id, "Kullanım: /addchannel @kanal"); return; }
        $conf = read_json($GLOBALS['CONFIG_FILE']);
        $conf['channels'][] = trim($parts[1]);
        $conf['channels'] = array_values(array_unique($conf['channels']));
        write_json($GLOBALS['CONFIG_FILE'], $conf);
        send_message($chat_id, "<b>✅ Kanal eklendi: {$parts[1]}</b>", ['reply_markup' => back_home_button()]);
        return;
    }

    if (stripos($text, '/delchannel') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "🚫 Admin"); return; }
        $parts = preg_split('/\s+/', $text, 2);
        if (!isset($parts[1])) { send_message($chat_id, "Kullanım: /delchannel @kanal"); return; }
        $conf = read_json($GLOBALS['CONFIG_FILE']);
        $conf['channels'] = array_values(array_diff($conf['channels'], [trim($parts[1])]));
        write_json($GLOBALS['CONFIG_FILE'], $conf);
        send_message($chat_id, "<b>✅ Kanal kaldırıldı.</b>", ['reply_markup' => back_home_button()]);
        return;
    }

    if (stripos($text, '/listchannels') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "🚫 Admin"); return; }
        $conf = read_json($GLOBALS['CONFIG_FILE']);
        $s = "<b>📢 Gerekli Kanallar</b>\n\n";
        foreach ($conf['channels'] as $c) $s .= "• ".htmlspecialchars($c)."\n";
        send_message($chat_id, $s, ['reply_markup' => back_home_button()]);
        return;
    }

    if (stripos($text, '/addadmin') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "🚫 Admin"); return; }
        $parts = preg_split('/\s+/', $text);
        if (!isset($parts[1])) { send_message($chat_id, "Kullanım: /addadmin ID"); return; }
        $conf = read_json($GLOBALS['CONFIG_FILE']);
        $conf['admins'][] = (string)$parts[1];
        $conf['admins'] = array_values(array_unique($conf['admins']));
        write_json($GLOBALS['CONFIG_FILE'], $conf);
        send_message($chat_id, "<b>✅ Admin eklendi: {$parts[1]}</b>", ['reply_markup' => back_home_button()]);
        return;
    }

    if (stripos($text, '/deladmin') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "🚫 Admin"); return; }
        $parts = preg_split('/\s+/', $text);
        if (!isset($parts[1])) { send_message($chat_id, "Kullanım: /deladmin ID"); return; }
        $conf = read_json($GLOBALS['CONFIG_FILE']);
        $conf['admins'] = array_values(array_diff($conf['admins'], [(string)$parts[1]]));
        write_json($GLOBALS['CONFIG_FILE'], $conf);
        send_message($chat_id, "<b>✅ Admin kaldırıldı.</b>", ['reply_markup' => back_home_button()]);
        return;
    }

    if (stripos($text, '/listadmins') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "🚫 Admin"); return; }
        $conf = read_json($GLOBALS['CONFIG_FILE']);
        $s = "<b>👑 Adminler</b>\n\n";
        foreach ($conf['admins'] as $a) $s .= "• <code>{$a}</code>\n";
        send_message($chat_id, $s, ['reply_markup' => back_home_button()]);
        return;
    }

    if (stripos($text, '/find') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "🚫 Admin"); return; }
        $parts = preg_split('/\s+/', $text);
        if (!isset($parts[1])) { send_message($chat_id, "Kullanım: /find ID"); return; }
        $all = read_json($GLOBALS['USERS_FILE']);
        if (isset($all[(string)$parts[1]])) {
            $t = $all[(string)$parts[1]];
            $s = "<b>👤 Kullanıcı: {$parts[1]}</b>\n\nIBAN: <code>".htmlspecialchars($t['iban'] ?? 'Ayarlanmadı')."</code>\nBakiye: <b>₺".number_format($t['balance'],2)."</b>\nReferanslar: <b>".$t['ref_count']."</b>\nÇekilen: <b>₺".number_format($t['withdrawn'],2)."</b>";
            send_message($chat_id, $s, ['reply_markup' => back_home_button()]);
        } else send_message($chat_id, "Kullanıcı bulunamadı.", ['reply_markup' => back_home_button()]);
        return;
    }

    if (stripos($text, '/reset') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "🚫 Admin"); return; }
        $parts = preg_split('/\s+/', $text);
        if (!isset($parts[1])) { send_message($chat_id, "Kullanım: /reset ID"); return; }
        $all = read_json($GLOBALS['USERS_FILE']);
        unset($all[(string)$parts[1]]);
        write_json($GLOBALS['USERS_FILE'], $all);
        send_message($chat_id, "<b>✅ Kullanıcı sıfırlandı.</b>", ['reply_markup' => back_home_button()]);
        return;
    }

    if (stripos($text, '/stats') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "🚫 Admin"); return; }
        $conf = read_json($GLOBALS['CONFIG_FILE']);
        $all = read_json($GLOBALS['USERS_FILE']);
        $tb = 0; foreach ($all as $one) $tb += floatval($one['balance']);
        $txt = "<b>📊 Bot İstatistikleri</b>\n\nToplam kullanıcı: <b>".count($all)."</b>\nToplam bakiye: <b>₺".number_format($tb,2)."</b>\nReferans ödülü: <b>₺".number_format($conf['ref_reward'],2)."</b>\nGünlük bonus: <b>₺".number_format($conf['daily_bonus'],2)."</b>";
        send_message($chat_id, $txt, ['reply_markup' => back_home_button()]);
        return;
    }

    if (stripos($text, '/broadcast ') === 0) {
        if (!is_admin($user_id)) { send_message($chat_id, "🚫 Admin"); return; }
        $msg = trim(substr($text, 10));
        if (!$msg) { send_message($chat_id, "Kullanım: /broadcast mesaj"); return; }
        $all = read_json($GLOBALS['USERS_FILE']);
        $count = 0;
        foreach ($all as $one) { send_message($one['id'], "<b>📣 Yayın</b>\n\n".$msg); usleep(100000); $count++; }
        send_message($chat_id, "<b>✅ Yayın {$count} kullanıcıya gönderildi.</b>", ['reply_markup' => back_home_button()]);
        return;
    }

    // Fallback
    show_home($chat_id, $user_id);
}

// ================== POLLING DÖNGÜSÜ ==================
// Önce eski webhook'u sil
api_call('deleteWebhook', ['drop_pending_updates' => false]);

log_line("=== POLLING BAŞLADI (Yeni Token) ===");

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "MKClub Bot - Long Polling Aktif\n";
    echo "Yeni Token ile çalışıyor\n";
    echo "Bot çalışıyor... (Bu sayfayı kapatma)\n\n";
    flush();
}

$offset = 0;
if (file_exists($OFFSET_FILE)) $offset = intval(file_get_contents($OFFSET_FILE));

while (true) {
    $res = api_call('getUpdates', ['offset' => $offset, 'timeout' => 30]);

    if (isset($res['ok']) && $res['ok'] && !empty($res['result'])) {
        foreach ($res['result'] as $update) {
            $offset = $update['update_id'] + 1;
            file_put_contents($OFFSET_FILE, $offset);
            try {
                if (isset($update['callback_query'])) {
                    handle_callback($update['callback_query']);
                } elseif (isset($update['message'])) {
                    process_message($update['message']);
                }
            } catch (Exception $e) {
                log_line("Hata: " . $e->getMessage());
            }
        }
    }
    usleep(500000);
}
