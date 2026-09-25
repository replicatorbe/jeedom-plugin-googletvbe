<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * Démon Google TV : garde une connexion ouverte avec chaque TV.
 *
 * Il existe parce que la TV parle d'elle-même — volume, veille, lecture —
 * et attend qu'on lui réponde toutes les cinq secondes : un relevé par cron
 * ne verrait rien de tout cela. Il fait trois choses, dans une seule boucle
 * stream_select :
 *
 *   - entretenir par TV une connexion Cast (port 8009) et, une fois la TV
 *     appairée, une connexion télécommande (port 6466), et les rouvrir
 *     quand elles tombent ;
 *   - pousser au plugin les valeurs qui ont changé (POST action=push) ;
 *   - recevoir les ordres du plugin sur un port local : une ligne JSON par
 *     connexion, une ligne JSON en réponse.
 *
 * La liste des TV vient du plugin (action=devices), relue chaque minute et
 * dès que le fichier témoin change, avec le certificat client de la
 * télécommande. Le démon ne charge pas core.inc.php.
 *
 * Seul l'appairage bloque, quelques secondes au plus par étape : il n'a
 * lieu qu'une fois par TV.
 *
 *   php googletvbed.php --callback URL --pid FICHIER --stamp FICHIER --keyfile FICHIER --port 55180 --loglevel debug --timezone Europe/Brussels
 *
 * La clé API arrive dans un fichier que le démon efface aussitôt lu, jamais
 * en argument : ps est lisible par tous les utilisateurs de la machine.
 */

error_reporting(E_ALL);
set_time_limit(0);
$options = getopt('', array('callback:', 'pid:', 'stamp:', 'keyfile:', 'port:', 'loglevel:', 'timezone:'));
/* Le PHP en ligne de commande est souvent en UTC quand Jeedom est à l'heure
 * locale : sans le fuseau du plugin, le journal du démon serait décalé. */
if (!empty($options['timezone']) && in_array($options['timezone'], timezone_identifiers_list(), true)) {
    date_default_timezone_set($options['timezone']);
}
$callback = isset($options['callback']) ? $options['callback'] : '';
$pidFile  = isset($options['pid']) ? $options['pid'] : '';
$stamp    = isset($options['stamp']) ? $options['stamp'] : '';
$port     = isset($options['port']) ? (int) $options['port'] : 0;
$logLevel = isset($options['loglevel']) ? $options['loglevel'] : 'error';
$apiKey   = '';
if (!empty($options['keyfile']) && is_readable($options['keyfile'])) {
    $apiKey = trim((string) file_get_contents($options['keyfile']));
    @unlink($options['keyfile']);
}

/* Tous les niveaux que log::convertLogLevel() du coeur peut rendre. */
$levels = array('debug' => 0, 'info' => 1, 'notice' => 1, 'warning' => 2, 'error' => 3,
                'critical' => 3, 'alert' => 3, 'emergency' => 3, 'none' => 4);
$threshold = isset($levels[$logLevel]) ? $levels[$logLevel] : 3;

function googletvbeLog($_level, $_message) {
    global $levels, $threshold;
    if ($levels[$_level] < $threshold) {
        return;
    }
    echo '[' . date('Y-m-d H:i:s') . '][' . strtoupper($_level) . '] : ' . $_message . "\n";
}

/* Horloge monotone : un recalage NTP en arrière ne doit pas figer les
 * minuteries du démon. */
function googletvbeClock() {
    return hrtime(true) / 1e9;
}

require_once __DIR__ . '/proto.php';
require_once __DIR__ . '/link.php';
require_once __DIR__ . '/cast.php';
require_once __DIR__ . '/remote.php';

if ($callback === '' || $pidFile === '' || $apiKey === '' || $port <= 0) {
    googletvbeLog('error', 'Arguments manquants : --callback, --pid, --port et --keyfile (contenant la clé API) sont obligatoires.');
    exit(1);
}
foreach (array('curl_init' => 'curl', 'stream_socket_enable_crypto' => 'openssl') as $function => $extension) {
    if (!function_exists($function)) {
        googletvbeLog('error', 'L\'extension PHP ' . $extension . ' est absente.');
        exit(1);
    }
}
if (!extension_loaded('openssl')) {
    googletvbeLog('error', 'L\'extension PHP openssl est absente.');
    exit(1);
}

/* Le canal des ordres n'écoute que la boucle locale. */
$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if ($server === false) {
    googletvbeLog('error', 'Impossible d\'écouter sur 127.0.0.1:' . $port . ' (' . $errstr . '). Le port est-il pris par un autre programme ? Il se change dans la configuration du plugin.');
    exit(1);
}
stream_set_blocking($server, false);

file_put_contents($pidFile, (string) getmypid());

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running) { $running = false; };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

/* ------------------------------------------------------------- JEEDOM */

function googletvbeHandle($_action, $_body = null) {
    global $callback, $apiKey;
    $ch = curl_init($callback . (strpos($callback, '?') === false ? '?' : '&')
        . 'apikey=' . rawurlencode($apiKey) . '&action=' . rawurlencode($_action));
    $options = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_PROXY          => '',
        /* Le callback est en boucle locale, parfois derrière un certificat
         * auto-signé si l'accès interne est en https. */
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    );
    if ($_body !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($_body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $options[CURLOPT_HTTPHEADER] = array('Content-Type: application/json');
    }
    curl_setopt_array($ch, $options);
    return $ch;
}

/* Appel bloquant : la liste des TV, une fois par minute. */
function googletvbeCall($_action, $_body = null) {
    $ch = googletvbeHandle($_action, $_body);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return googletvbeResult($_action, $code, $error, $body);
}

function googletvbeResult($_action, $code, $error, $body) {
    /* Jeedom refuse la clé (clé régénérée, accès API restreint) : inutile
     * d'insister. La gestion automatique du coeur relance le démon avec la
     * clé du moment. */
    if ($code === 401 || $code === 403) {
        global $pidFile;
        googletvbeLog('error', 'Jeedom refuse l\'accès (HTTP ' . $code . ') : clé API changée ou accès API du plugin restreint. Arrêt du démon.');
        @unlink($pidFile);
        exit(1);
    }
    if ($code !== 200) {
        googletvbeLog('warning', 'Appel de Jeedom « ' . $_action . ' » en échec : HTTP ' . $code . ' ' . $error);
        return null;
    }
    return $body;
}

/* ---------------------------------------------------------------- TV */

/* id => array(label, host, paired, cast, remote|null) */
$tvs = array();
/* Appairages en cours : id => googletvbePairing. */
$pairings = array();
/* Certificat client de la télécommande, écrit à côté du fichier pid :
 * stream_context veut des chemins de fichiers. */
$certFile = dirname($pidFile) . '/client.crt';
$keyFile = dirname($pidFile) . '/client.key';
$clientSignature = '';
/* Dernières valeurs poussées, et celles qui attendent de l'être. */
$known = array();
$pending = array();

function googletvbePublisher($_id) {
    return function ($_values) use ($_id) {
        global $known, $pending;
        foreach ($_values as $key => $value) {
            if (!isset($known[$_id]) || !array_key_exists($key, $known[$_id]) || $known[$_id][$key] !== $value) {
                $known[$_id][$key] = $value;
                $pending[$_id][$key] = $value;
            }
        }
    };
}

function googletvbeLoadDevices() {
    global $tvs, $known, $certFile, $keyFile, $clientSignature;
    $body = googletvbeCall('devices');
    $data = $body === null ? null : json_decode($body, true);
    if (!is_array($data) || !isset($data['devices']) || !is_array($data['devices'])) {
        return false;
    }
    $hasClient = false;
    if (isset($data['client']['cert'], $data['client']['key']) && $data['client']['cert'] !== '') {
        $hasClient = true;
        $signature = sha1($data['client']['cert']);
        if ($signature !== $clientSignature) {
            $old = umask(0077);
            file_put_contents($certFile, $data['client']['cert']);
            file_put_contents($keyFile, $data['client']['key']);
            umask($old);
            $clientSignature = $signature;
        }
    }
    $seen = array();
    foreach ($data['devices'] as $device) {
        $id = (int) $device['id'];
        $host = (string) $device['ip'];
        $label = (string) $device['name'];
        $paired = !empty($device['paired']) && $hasClient;
        if ($id <= 0 || $host === '') {
            continue;
        }
        $seen[$id] = true;
        if (isset($tvs[$id]) && $tvs[$id]['host'] !== $host) {
            googletvbeLog('info', $label . ' : nouvelle adresse ' . $host);
            googletvbeDrop($id);
        }
        if (!isset($tvs[$id])) {
            googletvbeLog('info', $label . ' : suivi de ' . $host);
            unset($known[$id]);
            $tvs[$id] = array('label' => $label, 'host' => $host, 'paired' => false, 'remote' => null,
                              'cast' => new googletvbeCast($label, $host, googletvbePublisher($id)));
        }
        $tvs[$id]['label'] = $label;
        if ($paired && $tvs[$id]['remote'] === null) {
            $tvs[$id]['remote'] = new googletvbeRemote($label, $host, $certFile, $keyFile, googletvbePublisher($id));
        } elseif (!$paired && $tvs[$id]['remote'] !== null) {
            $tvs[$id]['remote']->close();
            $tvs[$id]['remote'] = null;
        }
        $tvs[$id]['paired'] = $paired;
    }
    foreach (array_keys($tvs) as $id) {
        if (!isset($seen[$id])) {
            googletvbeLog('info', $tvs[$id]['label'] . ' : n\'est plus suivie');
            googletvbeDrop($id);
        }
    }
    return true;
}

function googletvbeDrop($_id) {
    global $tvs, $known;
    $tvs[$_id]['cast']->close();
    if ($tvs[$_id]['remote'] !== null) {
        $tvs[$_id]['remote']->close();
    }
    unset($tvs[$_id], $known[$_id]);
}

/* Les connexions d'une TV : Cast, et la télécommande si elle est appairée. */
function googletvbeLinks() {
    global $tvs;
    $links = array();
    foreach ($tvs as $tv) {
        $links[] = $tv['cast'];
        if ($tv['remote'] !== null) {
            $links[] = $tv['remote'];
        }
    }
    return $links;
}

/*
 * Les valeurs changées partent vers Jeedom sans bloquer la boucle : un
 * Jeedom lent ne doit pas faire manquer les pings de la TV (toutes les cinq
 * secondes) ni les ordres. Un seul envoi à la fois ; ce qui change pendant
 * ce temps attend le suivant.
 */
$pushMulti = curl_multi_init();
$pushHandle = null;

function googletvbePumpPush() {
    global $pushMulti, $pushHandle;
    if ($pushHandle === null) {
        return;
    }
    curl_multi_exec($pushMulti, $active);
    while (($info = curl_multi_info_read($pushMulti)) !== false) {
        $ch = $info['handle'];
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_multi_remove_handle($pushMulti, $ch);
        curl_close($ch);
        $pushHandle = null;
        googletvbeResult('push', $code, $error, $body);
    }
}

function googletvbeFlush() {
    global $pending, $tvs, $pushMulti, $pushHandle;
    if (count($pending) === 0 || $pushHandle !== null) {
        return;
    }
    $body = array('pushes' => array());
    foreach ($pending as $id => $values) {
        if (isset($tvs[$id])) {
            $values['_raw'] = $tvs[$id]['cast']->raw;
        }
        $body['pushes'][$id] = $values;
    }
    $pending = array();
    $pushHandle = googletvbeHandle('push', $body);
    curl_multi_add_handle($pushMulti, $pushHandle);
    curl_multi_exec($pushMulti, $active);
}

/* TV injoignable par tous ses canaux (débranchée, réseau coupé) : elle
 * n'a rien pu dire de son extinction. L'état affiché ne reste pas figé sur
 * « allumée », et le retour sur le réseau se lit comme un allumage. */
const GTV_UNREACHABLE = array(
    'power' => 0, 'app' => '', 'cast_app' => '', 'cast_app_id' => '', 'status_text' => '',
    'media_state' => 'arrêt', 'media_title' => '', 'media_subtitle' => '',
);

function googletvbeReachability() {
    global $tvs;
    foreach ($tvs as $id => $tv) {
        $reachable = $tv['cast']->isReady() || ($tv['remote'] !== null && $tv['remote']->isReady());
        if (!$reachable && !empty($tv['reachable'])) {
            call_user_func(googletvbePublisher($id), GTV_UNREACHABLE);
        }
        $tvs[$id]['reachable'] = $reachable;
    }
}

/* ------------------------------------------------------------ ORDRES */

/* Touche de télécommande qui remplace un ordre de lecture Cast, pour une
 * appli Android qui ne lit pas en Cast (Netflix, Disney+…). */
const GTV_MEDIA_KEYS = array(
    'PLAY' => 'MEDIA_PLAY', 'PAUSE' => 'MEDIA_PAUSE', 'STOP' => 'MEDIA_STOP',
    'TOGGLE' => 'MEDIA_PLAY_PAUSE', 'QUEUE_NEXT' => 'MEDIA_NEXT', 'QUEUE_PREV' => 'MEDIA_PREVIOUS',
);

function googletvbeNeedRemote($_tv) {
    if ($_tv['remote'] === null) {
        throw new Exception('la TV n\'est pas appairée : utilisez le bouton « Appairer la télécommande » de l\'équipement');
    }
    if (!$_tv['remote']->isReady()) {
        throw new Exception('la télécommande ne joint pas la TV (éteinte, injoignable, ou appairage perdu)');
    }
    return $_tv['remote'];
}

function googletvbeOrder($_request) {
    global $tvs, $apiKey, $pairings, $known, $certFile, $keyFile;
    if (!is_array($_request) || !isset($_request['key']) || !hash_equals($apiKey, (string) $_request['key'])) {
        return array('ok' => false, 'error' => 'clé refusée');
    }
    $do = isset($_request['do']) ? (string) $_request['do'] : '';
    $value = isset($_request['value']) ? $_request['value'] : null;
    if ($do === 'ping') {
        return array('ok' => true, 'tvs' => count($tvs));
    }
    $id = isset($_request['eq']) ? (int) $_request['eq'] : 0;
    if (!isset($tvs[$id])) {
        return array('ok' => false, 'error' => 'TV inconnue du démon : relancez-le si elle vient d\'être créée');
    }
    $tv = $tvs[$id];
    $cast = $tv['cast'];
    $remote = $tv['remote'];
    switch ($do) {
        case 'status':
            return array('ok' => true, 'cast' => $cast->isReady(), 'paired' => $tv['paired'],
                         'remote' => $remote !== null && $remote->isReady(),
                         'remote_rejected' => $remote !== null && $remote->tlsFailures >= 3,
                         'raw' => $cast->raw);
        case 'reconnect':
            $cast->retryNow();
            if ($remote !== null) {
                $remote->retryNow();
            }
            return array('ok' => true);
        case 'pair_start':
            if (!is_readable($certFile)) {
                throw new Exception('certificat client absent : le démon ne l\'a pas encore reçu de Jeedom');
            }
            if (isset($pairings[$id])) {
                $pairings[$id]->close();
            }
            $pairing = new googletvbePairing($tv['host'], $certFile, $keyFile);
            try {
                $pairing->start();
            } catch (Throwable $e) {
                $pairing->close();
                throw $e;
            }
            $pairings[$id] = $pairing;
            googletvbeLog('info', $tv['label'] . ' : appairage commencé, code affiché sur la TV');
            return array('ok' => true);
        case 'pair_finish':
            if (!isset($pairings[$id])) {
                throw new Exception('aucun appairage en cours : recommencez');
            }
            $pairing = $pairings[$id];
            try {
                $pairing->finish((string) $value);
            } catch (Throwable $e) {
                /* Un code mal recopié se corrige sans tout reprendre ; une
                 * erreur de la TV, non. */
                if (strpos($e->getMessage(), 'code incorrect') !== 0) {
                    $pairing->close();
                    unset($pairings[$id]);
                }
                throw $e;
            }
            unset($pairings[$id]);
            googletvbeLog('info', $tv['label'] . ' : appairage réussi');
            /* Réappairage d'une TV déjà connue : la connexion de commande
             * attendait peut-être la fin d'une pause de 30 s. */
            if ($remote !== null) {
                $remote->retryNow();
            }
            return array('ok' => true);
        case 'pair_cancel':
            if (isset($pairings[$id])) {
                $pairings[$id]->close();
                unset($pairings[$id]);
            }
            return array('ok' => true);
        case 'key':
            googletvbeNeedRemote($tv)->key((string) $value, !empty($_request['long']));
            return array('ok' => true);
        case 'link':
            googletvbeNeedRemote($tv)->openLink((string) $value);
            return array('ok' => true);
        case 'power':
            $link = googletvbeNeedRemote($tv);
            $on = $link->isOn !== null ? $link->isOn : (isset($known[$id]['power']) ? (bool) $known[$id]['power'] : null);
            /* La touche POWER bascule : on ne l'envoie que si l'état connu
             * diffère de celui demandé. */
            if ($value === 'toggle' || $on === null || $on !== ($value === 'on')) {
                $link->key('POWER');
            }
            return array('ok' => true);
    }
    if ($do === 'media') {
        $type = strtoupper((string) $value);
        try {
            if (!$cast->isReady()) {
                throw new Exception('Cast injoignable');
            }
            $cast->media($type);
        } catch (Throwable $e) {
            /* Pas de lecture Cast : la touche média de la télécommande
             * s'adresse à l'appli au premier plan. */
            if ($remote === null || !$remote->isReady() || !isset(GTV_MEDIA_KEYS[$type])) {
                throw $e;
            }
            $remote->key(GTV_MEDIA_KEYS[$type]);
        }
        return array('ok' => true);
    }
    if (!$cast->isReady()) {
        return array('ok' => false, 'error' => 'la TV ne répond pas sur le réseau (éteinte ou injoignable)');
    }
    switch ($do) {
        case 'refresh':
            $cast->refresh();
            break;
        case 'volume_set':
            $cast->setVolume($value);
            break;
        case 'volume_step':
            $cast->stepVolume((float) $value);
            break;
        case 'mute':
            $cast->setMuted($value === 'toggle' ? 'toggle' : (bool) $value);
            break;
        case 'launch':
            $cast->launch($value);
            break;
        case 'app_stop':
            $cast->stopApp();
            break;
        default:
            return array('ok' => false, 'error' => 'ordre inconnu : ' . $do);
    }
    return array('ok' => true);
}

/* Connexions du plugin en cours de lecture : flux => tampon. */
$clients = array();

function googletvbeServe($_stream) {
    global $clients;
    $chunk = @fread($_stream, 65536);
    if ($chunk !== false && $chunk !== '') {
        $clients[(int) $_stream]['buffer'] .= $chunk;
    }
    $buffer = $clients[(int) $_stream]['buffer'];
    $end = strpos($buffer, "\n");
    if ($end === false) {
        if (feof($_stream) || strlen($buffer) > 65536) {
            @fclose($_stream);
            unset($clients[(int) $_stream]);
        }
        return;
    }
    $request = json_decode(substr($buffer, 0, $end), true);
    try {
        $reply = googletvbeOrder($request);
    } catch (Throwable $e) {
        $reply = array('ok' => false, 'error' => $e->getMessage());
    }
    if (!$reply['ok']) {
        googletvbeLog('debug', 'Ordre ' . (isset($request['do']) ? $request['do'] : '?') . ' refusé : ' . $reply['error']);
    }
    stream_set_blocking($_stream, true);
    @fwrite($_stream, json_encode($reply, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
    @fclose($_stream);
    unset($clients[(int) $_stream]);
}

/* -------------------------------------------------------------- BOUCLE */

googletvbeLog('info', 'Démarrage du démon, ordres sur 127.0.0.1:' . $port);
$nextDevices = 0.0;
$stampValue = null;
$lastFlush = 0.0;
while ($running) {
  /* Une erreur imprévue ne doit pas tuer le démon : elle est notée, la
   * boucle reprend. */
  try {
    $now = googletvbeClock();
    $current = ($stamp !== '' && is_readable($stamp)) ? (string) @file_get_contents($stamp) : '';
    if ($now >= $nextDevices || $current !== $stampValue) {
        $stampValue = $current;
        /* Jeedom injoignable : on réessaie dans dix secondes, pas une minute. */
        $nextDevices = $now + (googletvbeLoadDevices() ? 60 : 10);
    }

    foreach (googletvbeLinks() as $link) {
        $link->tick($now);
    }
    /* Un code jamais saisi n'immobilise pas la TV sur l'écran d'appairage. */
    foreach ($pairings as $id => $pairing) {
        if ($now - $pairing->startedAt > 180) {
            $pairing->close();
            unset($pairings[$id]);
        }
    }

    $read = array($server);
    $write = array();
    foreach ($clients as $client) {
        $read[] = $client['stream'];
    }
    $links = googletvbeLinks();
    foreach ($links as $link) {
        $link->watch($read, $write);
    }
    $except = null;
    $ready = @stream_select($read, $write, $except, 0, 250000);
    if ($ready === false) {
        /* Interrompu par un signal : on repasse par la condition de la
         * boucle. */
        continue;
    }
    foreach ($read as $stream) {
        if ($stream === $server) {
            $client = @stream_socket_accept($server, 0);
            if ($client !== false) {
                stream_set_blocking($client, false);
                $clients[(int) $client] = array('stream' => $client, 'buffer' => '', 'since' => $now);
            }
        } elseif (isset($clients[(int) $stream]) && $clients[(int) $stream]['stream'] === $stream) {
            googletvbeServe($stream);
        }
    }
    foreach ($links as $link) {
        $readable = false;
        $writable = false;
        foreach ($read as $stream) {
            $readable = $readable || $link->owns($stream);
        }
        foreach ($write as $stream) {
            $writable = $writable || $link->owns($stream);
        }
        if ($readable || $writable) {
            $link->handle($readable, $writable);
        }
    }
    /* Un client qui n'envoie jamais sa ligne ne reste pas ouvert. */
    foreach ($clients as $key => $client) {
        if ($now - $client['since'] > 5) {
            @fclose($client['stream']);
            unset($clients[$key]);
        }
    }
    googletvbeReachability();
    googletvbePumpPush();
    /* Les changements groupés : un réglage de volume en produit plusieurs
     * d'affilée. */
    if ($now - $lastFlush >= 0.3) {
        $lastFlush = $now;
        googletvbeFlush();
    }
  } catch (Throwable $e) {
    googletvbeLog('error', 'Erreur imprévue : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    usleep(500000);
  }
}

googletvbeLog('info', 'Arrêt du démon');
foreach (googletvbeLinks() as $link) {
    $link->close();
}
@fclose($server);
@unlink($certFile);
@unlink($keyFile);
@unlink($pidFile);
