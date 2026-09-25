<?php
/* Jeu d'essai hors ligne des protocoles du démon.
 *
 *   php tests/run.php
 *
 * Ne touche ni à Jeedom ni à la TV : les messages sont fabriqués ici, ou
 * recopiés de ce qu'une TCL (Android TV 11, Cast 3.72) a réellement envoyé. */

error_reporting(E_ALL);
function googletvbeLog($_level, $_message) {
}
function googletvbeClock() {
    return hrtime(true) / 1e9;
}
require_once __DIR__ . '/../resources/googletvbed/proto.php';
require_once __DIR__ . '/../resources/googletvbed/link.php';
require_once __DIR__ . '/../resources/googletvbed/cast.php';
require_once __DIR__ . '/../resources/googletvbed/remote.php';

$failures = 0;
$count = 0;
function check($_label, $_actual, $_expected) {
    global $failures, $count;
    $count++;
    if ($_actual !== $_expected) {
        $failures++;
        echo "ÉCHEC $_label\n  attendu : " . var_export($_expected, true) . "\n  obtenu  : " . var_export($_actual, true) . "\n";
    }
}

/* ------------------------------------------------------------- varint */
foreach (array(0 => "\x00", 1 => "\x01", 127 => "\x7f", 128 => "\x80\x01", 300 => "\xac\x02", 16384 => "\x80\x80\x01") as $n => $bytes) {
    check("varint($n)", googletvbeProto::varint($n), $bytes);
    $pos = 0;
    check("readVarint($n)", googletvbeProto::readVarint($bytes, $pos), $n);
    check("readVarint($n) avance", $pos, strlen($bytes));
}
$pos = 0;
check('varint tronqué', googletvbeProto::readVarint("\x80", $pos), null);

/* ------------------------------------------------------------ décodage */
$message = googletvbeProto::int(1, 150) . googletvbeProto::bytes(2, 'abc') . googletvbeProto::bytes(2, 'de');
check('décodage', googletvbeProto::decode($message), array(1 => array(150), 2 => array('abc', 'de')));
$threw = false;
try {
    googletvbeProto::decode(googletvbeProto::bytes(2, 'abcdef') . '');
    googletvbeProto::decode(substr(googletvbeProto::bytes(2, 'abcdef'), 0, 4));
} catch (Exception $e) {
    $threw = true;
}
check('message tronqué refusé', $threw, true);

/* --------------------------------------------------------- cadres Cast */
$frame = googletvbeCast::frame('sender-jeedom', 'receiver-0', googletvbeCast::NS_RECEIVER, array('type' => 'GET_STATUS', 'requestId' => 1));
check('longueur du cadre', unpack('N', substr($frame, 0, 4))[1], strlen($frame) - 4);
/* Deux cadres et le début d'un troisième : les deux premiers sortent, le
 * reste attend. */
$buffer = $frame . $frame . substr($frame, 0, 10);
$messages = googletvbeCast::unframe($buffer);
check('deux messages extraits', count($messages), 2);
check('reste en attente', strlen($buffer), 10);
check('espace de noms', $messages[0]['namespace'], googletvbeCast::NS_RECEIVER);
check('destinataire', $messages[0]['destination'], 'receiver-0');
check('contenu JSON', $messages[0]['payload'], array('type' => 'GET_STATUS', 'requestId' => 1));

/* ------------------------------------------ RECEIVER_STATUS réel (TCL) */
$standby = json_decode('{"isActiveInput":false,"isStandBy":true,"userEq":{},"volume":{"controlType":"master","level":0.11999999731779099,"muted":false,"stepInterval":0.009999999776482582}}', true);
check('TV en veille', googletvbeCast::receiverValues($standby), array(
    'volume' => 12, 'muted' => 0, 'power' => 0, 'active_input' => 0,
    'cast_app' => '', 'cast_app_id' => '', 'status_text' => ''));

$youtube = json_decode('{"applications":[{"appId":"233637DE","displayName":"YouTube","isIdleScreen":false,"namespaces":[{"name":"urn:x-cast:com.google.cast.media"}],"sessionId":"s1","statusText":"YouTube","transportId":"t1"}],"isStandBy":false,"volume":{"level":0.3,"muted":true}}', true);
$values = googletvbeCast::receiverValues($youtube);
check('application Cast', $values['cast_app'], 'YouTube');
check('volume 30', $values['volume'], 30);
check('muet', $values['muted'], 1);
check('allumée', $values['power'], 1);
check('média disponible', googletvbeCast::hasMedia(googletvbeCast::mainApplication($youtube)), true);

$backdrop = json_decode('{"applications":[{"appId":"E8C28D3C","displayName":"Backdrop","isIdleScreen":true}]}', true);
check('écran de veille ignoré', googletvbeCast::mainApplication($backdrop), null);

/* Allumée sur Netflix ouvert à la télécommande (TCL, relevé réel) : l'appli
 * est connue, mais sans transport ni lecture Cast. */
$netflix = json_decode('{"applications":[{"appId":"Netflix","appType":"WEB","displayName":"Netflix","iconUrl":"","isIdleScreen":false,"launchedFromCloud":false,"senderConnected":false,"sessionId":"1daae88d-e9a1-46d1-9c37-5fbeb7b7fbfe","statusText":"Netflix","universalAppId":"Netflix"}],"isActiveInput":true,"isStandBy":false,"userEq":{},"volume":{"controlType":"master","level":0.14000000059604645,"muted":false,"stepInterval":0.009999999776482582}}', true);
check('TV allumée sur Netflix', googletvbeCast::receiverValues($netflix), array(
    'volume' => 14, 'muted' => 0, 'power' => 1, 'active_input' => 1,
    'cast_app' => 'Netflix', 'cast_app_id' => 'Netflix', 'status_text' => 'Netflix'));
check('Netflix sans lecture Cast', googletvbeCast::hasMedia(googletvbeCast::mainApplication($netflix)), false);

/* ---------------------------------------------------------- MEDIA_STATUS */
$media = json_decode('{"mediaSessionId":1,"playerState":"PLAYING","media":{"metadata":{"title":"Titre","artist":"Artiste"}}}', true);
check('média en lecture', googletvbeCast::mediaValues($media), array('media_state' => 'lecture', 'media_title' => 'Titre', 'media_subtitle' => 'Artiste'));
check('sans « media », titre gardé', googletvbeCast::mediaValues(array('playerState' => 'PAUSED')), array('media_state' => 'pause'));

/* ---------------------------------------------------------- télécommande */
check('touche courte DPAD_UP', googletvbeRemoteCodec::keyInject(19), "\x06\x52\x04\x08\x13\x10\x03");
check('nom de touche', googletvbeRemoteCodec::keyCode('keycode_home'), 3);
check('touche par numéro', googletvbeRemoteCodec::keyCode('26'), 26);
check('code à deux chiffres', googletvbeRemoteCodec::keyCode('03'), 3);
check('chiffre', googletvbeRemoteCodec::keyCode('5'), 12);
$threw = false;
try {
    googletvbeRemoteCodec::keyCode('ABRACADABRA');
} catch (Exception $e) {
    $threw = true;
}
check('touche inconnue refusée', $threw, true);
check('lien gardé', googletvbeRemoteCodec::normalizeLink('https://www.netflix.com/title'), 'https://www.netflix.com/title');
check('paquet via la fiche Play Store', googletvbeRemoteCodec::normalizeLink('com.google.android.youtube.tvkids'), 'https://play.google.com/store/apps/details?id=com.google.android.youtube.tvkids');
check('lien avec schéma pas pris pour un paquet', googletvbeRemoteCodec::isPackage('spotify://'), false);

/* Messages de la TV coupés n'importe où : rien ne se perd. */
$ping = googletvbeRemoteCodec::frame(googletvbeProto::bytes(GTV_REMOTE_PING_REQUEST, googletvbeProto::int(1, 42)));
$start = googletvbeRemoteCodec::frame(googletvbeProto::bytes(GTV_REMOTE_START, googletvbeProto::int(1, 1)));
$stream = $ping . $start;
$got = array();
$buffer = '';
for ($i = 0; $i < strlen($stream); $i++) {
    $buffer .= $stream[$i];
    foreach (googletvbeRemoteCodec::unframe($buffer) as $message) {
        $got[] = array_keys($message)[0];
    }
}
check('messages reconstitués', $got, array(GTV_REMOTE_PING_REQUEST, GTV_REMOTE_START));

/* Empreinte d'appairage : le premier octet du code contrôle la saisie. */
$a = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
$b = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
$pubA = openssl_pkey_get_public(openssl_pkey_get_details($a)['key']);
$pubB = openssl_pkey_get_public(openssl_pkey_get_details($b)['key']);
$da = openssl_pkey_get_details($pubA)['rsa'];
$db = openssl_pkey_get_details($pubB)['rsa'];
$expected = hash('sha256', $da['n'] . $da['e'] . $db['n'] . $db['e'] . hex2bin('BEEF'), true);
$code = strtoupper(bin2hex($expected[0])) . 'BEEF';
check('code juste', googletvbeRemoteCodec::pairingSecret($pubA, $pubB, strtolower($code)), $expected);
$wrong = sprintf('%02X', (ord($expected[0]) + 1) % 256) . 'BEEF';
check('code mal recopié', googletvbeRemoteCodec::pairingSecret($pubA, $pubB, $wrong), null);
check('exposant sur trois octets', bin2hex($da['e']), '010001');

echo $count . ' contrôles, ' . $failures . " échec(s)\n";
exit($failures > 0 ? 1 : 0);
