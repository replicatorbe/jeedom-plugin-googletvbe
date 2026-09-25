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
 * Télécommande Android TV, version 2 : le protocole de l'appli Google TV.
 *
 * Deux ports, en TLS, avec le même certificat client :
 *
 *   6467  appairage, une fois pour toutes. La TV affiche un code de six
 *         caractères hexadécimaux ; on lui renvoie une empreinte SHA-256
 *         des deux clés publiques et du code. Elle retient alors notre
 *         certificat.
 *   6466  commande : touches, lancement d'applis par lien, et en retour
 *         marche/arrêt et appli au premier plan. La TV envoie un ping
 *         toutes les cinq secondes et coupe si on n'y répond pas.
 *
 * Chaque message est un protobuf précédé de sa longueur en varint. Les
 * numéros de champs viennent de polo.proto et remotemessage.proto
 * (bibliothèque androidtvremote2 de tronikos, reprise par Home Assistant).
 */

/* Champs du message de commande (RemoteMessage). */
const GTV_REMOTE_CONFIGURE = 1;
const GTV_REMOTE_SET_ACTIVE = 2;
const GTV_REMOTE_ERROR = 3;
const GTV_REMOTE_PING_REQUEST = 8;
const GTV_REMOTE_PING_RESPONSE = 9;
const GTV_REMOTE_KEY_INJECT = 10;
const GTV_REMOTE_IME_KEY_INJECT = 20;
const GTV_REMOTE_START = 40;
const GTV_REMOTE_SET_VOLUME_LEVEL = 50;
const GTV_REMOTE_APP_LINK_LAUNCH = 90;

class googletvbeRemoteCodec {

    /* Fonctions annoncées à la TV : ping 1, touches 2, IME 4 (sans lui, pas
     * d'appli au premier plan), marche 32, volume 64, liens 512. C'est le
     * jeu de Home Assistant. */
    const FEATURES = 615;

    const DIRECTION_START_LONG = 1;
    const DIRECTION_END_LONG = 2;
    const DIRECTION_SHORT = 3;

    /* Touches nommées → code KeyEvent Android. */
    const KEYS = array(
        'HOME' => 3, 'BACK' => 4, 'DPAD_UP' => 19, 'DPAD_DOWN' => 20, 'DPAD_LEFT' => 21,
        'DPAD_RIGHT' => 22, 'DPAD_CENTER' => 23, 'VOLUME_UP' => 24, 'VOLUME_DOWN' => 25,
        'POWER' => 26, 'ENTER' => 66, 'DEL' => 67, 'MENU' => 82, 'SEARCH' => 84,
        'MEDIA_PLAY_PAUSE' => 85, 'MEDIA_STOP' => 86, 'MEDIA_NEXT' => 87, 'MEDIA_PREVIOUS' => 88,
        'MEDIA_REWIND' => 89, 'MEDIA_FAST_FORWARD' => 90, 'MUTE' => 91, 'PAGE_UP' => 92,
        'PAGE_DOWN' => 93, 'MEDIA_PLAY' => 126, 'MEDIA_PAUSE' => 127, 'MEDIA_RECORD' => 130,
        'VOLUME_MUTE' => 164, 'INFO' => 165, 'CHANNEL_UP' => 166, 'CHANNEL_DOWN' => 167,
        'GUIDE' => 172, 'DVR' => 173, 'BOOKMARK' => 174, 'CAPTIONS' => 175, 'SETTINGS' => 176,
        'TV_POWER' => 177, 'TV_INPUT' => 178, 'PROG_RED' => 183, 'PROG_GREEN' => 184,
        'PROG_YELLOW' => 185, 'PROG_BLUE' => 186, 'APP_SWITCH' => 187, 'LAST_CHANNEL' => 229,
        'SLEEP' => 223, 'WAKEUP' => 224, 'TV_INPUT_HDMI_1' => 243, 'TV_INPUT_HDMI_2' => 244,
        'TV_INPUT_HDMI_3' => 245, 'TV_INPUT_HDMI_4' => 246, 'ASSIST' => 219,
        '0' => 7, '1' => 8, '2' => 9, '3' => 10, '4' => 11, '5' => 12, '6' => 13, '7' => 14,
        '8' => 15, '9' => 16,
    );

    /* Un nom (« DPAD_UP », « KEYCODE_DPAD_UP », « home »), un chiffre seul
     * (la touche chiffre, pour composer une chaîne), ou un code KeyEvent
     * Android à partir de 10. */
    public static function keyCode($_key) {
        $key = strtoupper(trim((string) $_key));
        if (preg_match('/^\d$/', $key)) {
            return self::KEYS[$key];
        }
        if (preg_match('/^\d{2,3}$/', $key)) {
            $code = (int) $key;
            if ($code > 0 && $code < 400) {
                return $code;
            }
        }
        if (strpos($key, 'KEYCODE_') === 0) {
            $key = substr($key, 8);
        }
        if (isset(self::KEYS[$key])) {
            return self::KEYS[$key];
        }
        throw new Exception('touche inconnue : « ' . $_key . ' »');
    }

    public static function frame($_message) {
        return googletvbeProto::varint(strlen($_message)) . $_message;
    }

    /* Messages complets du tampon ; le reste attend la suite. */
    public static function unframe(&$_buffer) {
        $messages = array();
        while ($_buffer !== '') {
            $pos = 0;
            $length = googletvbeProto::readVarint($_buffer, $pos);
            /* Contrôlé avant d'attendre la suite : un flux corrompu ne doit
             * pas faire grossir le tampon. */
            if ($length !== null && $length > 1048576) {
                throw new Exception('message démesuré (' . $length . ' octets)');
            }
            if ($length === null || strlen($_buffer) < $pos + $length) {
                break;
            }
            $messages[] = googletvbeProto::decode(substr($_buffer, $pos, $length));
            $_buffer = (string) substr($_buffer, $pos + $length);
        }
        return $messages;
    }

    public static function keyInject($_code, $_direction = self::DIRECTION_SHORT) {
        return self::frame(googletvbeProto::bytes(GTV_REMOTE_KEY_INJECT,
            googletvbeProto::int(1, $_code) . googletvbeProto::int(2, $_direction)));
    }

    public static function appLink($_link) {
        return self::frame(googletvbeProto::bytes(GTV_REMOTE_APP_LINK_LAUNCH, googletvbeProto::bytes(1, $_link)));
    }

    public static function configure($_features) {
        $info = googletvbeProto::bytes(1, 'Jeedom') . googletvbeProto::bytes(2, 'Jeedom')
              . googletvbeProto::int(3, 1) . googletvbeProto::bytes(4, '1')
              . googletvbeProto::bytes(5, 'atvremote') . googletvbeProto::bytes(6, '1.0.0');
        return self::frame(googletvbeProto::bytes(GTV_REMOTE_CONFIGURE,
            googletvbeProto::int(1, $_features) . googletvbeProto::bytes(2, $info)));
    }

    public static function setActive($_features) {
        return self::frame(googletvbeProto::bytes(GTV_REMOTE_SET_ACTIVE, googletvbeProto::int(1, $_features)));
    }

    public static function pingResponse($_value) {
        return self::frame(googletvbeProto::bytes(GTV_REMOTE_PING_RESPONSE, googletvbeProto::int(1, $_value)));
    }

    public static function isPackage($_target) {
        return strpos($_target, ':') === false && preg_match('/^[A-Za-z][\w]*(\.[A-Za-z_][\w]*)+$/', $_target) === 1;
    }

    /* La TV n'ouvre que les liens qu'une appli déclare savoir traiter. Un
     * nom de paquet Android devient la fiche Play Store de l'appli, dont le
     * bouton « Ouvrir » est sélectionné : c'est à l'appelant d'appuyer sur
     * OK. Une TCL (Android TV 11) refuse market://launch, intent: et
     * package:, et coupe la connexion. */
    public static function normalizeLink($_target) {
        $target = trim((string) $_target);
        if ($target === '') {
            throw new Exception('lien ou paquet vide');
        }
        if (self::isPackage($target)) {
            return 'https://play.google.com/store/apps/details?id=' . $target;
        }
        return $target;
    }

    /* ---------------------------------------------------------- APPAIRAGE */

    public static function pairingMessage($_field, $_body) {
        return self::frame(googletvbeProto::int(1, 2) . googletvbeProto::int(2, 200) . googletvbeProto::bytes($_field, $_body));
    }

    /* Empreinte envoyée à la TV :
     *   SHA-256( n client ‖ e client ‖ n TV ‖ e TV ‖ octets des 4 derniers
     *   caractères du code )
     * Les deux premiers caractères du code sont une somme de contrôle : ils
     * doivent valoir le premier octet de l'empreinte. Rend null pour un code
     * mal recopié. */
    public static function pairingSecret($_clientKey, $_serverKey, $_code) {
        $code = strtoupper(trim((string) $_code));
        if (!preg_match('/^[0-9A-F]{6}$/', $code)) {
            throw new Exception('code incorrect : le code affiché par la TV compte six caractères, chiffres et lettres de A à F');
        }
        $client = openssl_pkey_get_details($_clientKey);
        $server = openssl_pkey_get_details($_serverKey);
        if (!isset($client['rsa']['n'], $server['rsa']['n'])) {
            throw new Exception('clés RSA illisibles');
        }
        $hash = hash('sha256', ltrim($client['rsa']['n'], "\0") . ltrim($client['rsa']['e'], "\0")
            . ltrim($server['rsa']['n'], "\0") . ltrim($server['rsa']['e'], "\0") . hex2bin(substr($code, 2)), true);
        if (ord($hash[0]) !== hexdec(substr($code, 0, 2))) {
            return null;
        }
        return $hash;
    }
}

/*
 * Appairage, en deux temps séparés par la saisie du code : start() ouvre la
 * connexion et fait afficher le code, finish() l'envoie. Bloquant, mais
 * court : le démon reprend sa boucle entre les deux.
 */
class googletvbePairing {

    const STEP_TIMEOUT = 10;

    private $stream;
    private $buffer = '';
    private $clientKey;
    private $serverKey;
    public $startedAt;

    public function __construct($_host, $_certFile, $_keyFile) {
        $context = stream_context_create(array('ssl' => array(
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
            'local_cert' => $_certFile, 'local_pk' => $_keyFile, 'capture_peer_cert' => true,
        )));
        $stream = @stream_socket_client('ssl://' . $_host . ':6467', $errno, $errstr, self::STEP_TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new Exception('la TV refuse l\'appairage sur le port 6467 (' . $errstr . ')');
        }
        $params = stream_context_get_params($stream);
        $this->serverKey = openssl_pkey_get_public($params['options']['ssl']['peer_certificate']);
        $this->clientKey = openssl_pkey_get_public(file_get_contents($_certFile));
        if ($this->serverKey === false || $this->clientKey === false) {
            fclose($stream);
            throw new Exception('certificats illisibles');
        }
        stream_set_timeout($stream, self::STEP_TIMEOUT);
        $this->stream = $stream;
        $this->startedAt = googletvbeClock();
    }

    private function exchange($_field, $_body, $_expected) {
        fwrite($this->stream, googletvbeRemoteCodec::pairingMessage($_field, $_body));
        $deadline = googletvbeClock() + self::STEP_TIMEOUT;
        while (googletvbeClock() < $deadline) {
            $messages = googletvbeRemoteCodec::unframe($this->buffer);
            if (count($messages) > 0) {
                $message = $messages[0];
                $status = (int) googletvbeProto::first($message, 2, 0);
                if ($status !== 200) {
                    $reasons = array(400 => 'erreur', 401 => 'configuration refusée', 402 => 'code refusé');
                    throw new Exception('la TV répond ' . $status . (isset($reasons[$status]) ? ' (' . $reasons[$status] . ')' : ''));
                }
                if (!isset($message[$_expected])) {
                    throw new Exception('réponse inattendue de la TV');
                }
                return $message[$_expected][0];
            }
            $chunk = fread($this->stream, 8192);
            if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                throw new Exception('la TV a coupé la connexion d\'appairage');
            }
            $this->buffer .= $chunk;
        }
        throw new Exception('la TV ne répond plus');
    }

    /* Jusqu'à l'affichage du code sur la TV. */
    public function start() {
        $this->exchange(10, googletvbeProto::bytes(1, 'atvremote') . googletvbeProto::bytes(2, 'Jeedom'), 11);
        /* Code hexadécimal de six caractères, rôle « entrée ». */
        $encoding = googletvbeProto::int(1, 3) . googletvbeProto::int(2, 6);
        $this->exchange(20, googletvbeProto::bytes(1, $encoding) . googletvbeProto::int(3, 1), 20);
        $this->exchange(30, googletvbeProto::bytes(1, $encoding) . googletvbeProto::int(2, 1), 31);
    }

    public function finish($_code) {
        $secret = googletvbeRemoteCodec::pairingSecret($this->clientKey, $this->serverKey, $_code);
        if ($secret === null) {
            throw new Exception('code incorrect : vérifiez-le sur l\'écran de la TV');
        }
        $this->exchange(40, googletvbeProto::bytes(1, $secret), 41);
        $this->close();
    }

    public function close() {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }
}

/*
 * Connexion de commande, port 6466, tenue en permanence comme celle de
 * Cast.
 */
class googletvbeRemote extends googletvbeLink {

    private $publish;
    private $features = 0;
    /* null tant que la TV n'a rien dit de son état. */
    public $isOn = null;
    /* Connexions refusées d'affilée : au-delà de quelques-unes, la TV a
     * sans doute oublié notre certificat. En TLS 1.3, ce refus n'échoue pas
     * la poignée de main : la TV ferme juste après, avant tout message. */
    public $tlsFailures = 0;
    private $heard = false;

    public function __construct($_label, $_host, $_certFile, $_keyFile, $_publish) {
        parent::__construct($_label . ' [télécommande]', $_host, 6466,
            array('local_cert' => $_certFile, 'local_pk' => $_keyFile));
        $this->publish = $_publish;
        /* La TV envoie un ping toutes les cinq secondes. */
        $this->idleTimeout = 16;
    }

    protected function onReady() {
        $this->heard = false;
        call_user_func($this->publish, array('remote' => 1));
    }

    protected function onDown($_reason) {
        if (strpos($_reason, 'TLS') !== false || (!$this->heard && strpos($_reason, 'fermée') !== false)) {
            $this->tlsFailures++;
        }
        $this->isOn = null;
        call_user_func($this->publish, array('remote' => 0));
    }

    protected function onData() {
        foreach (googletvbeRemoteCodec::unframe($this->in) as $message) {
            if (!$this->heard) {
                $this->heard = true;
                $this->tlsFailures = 0;
            }
            if (isset($message[GTV_REMOTE_PING_REQUEST])) {
                $ping = googletvbeProto::decode($message[GTV_REMOTE_PING_REQUEST][0]);
                $this->send(googletvbeRemoteCodec::pingResponse((int) googletvbeProto::first($ping, 1, 0)));
                continue;
            }
            if (isset($message[GTV_REMOTE_CONFIGURE])) {
                $configure = googletvbeProto::decode($message[GTV_REMOTE_CONFIGURE][0]);
                $this->features = googletvbeRemoteCodec::FEATURES & (int) googletvbeProto::first($configure, 1, 0);
                googletvbeLog('debug', $this->label . ' : fonctions de la TV ' . googletvbeProto::first($configure, 1, 0) . ', retenues ' . $this->features);
                $this->send(googletvbeRemoteCodec::configure($this->features));
                continue;
            }
            if (isset($message[GTV_REMOTE_SET_ACTIVE])) {
                $this->send(googletvbeRemoteCodec::setActive($this->features));
                continue;
            }
            if (isset($message[GTV_REMOTE_START])) {
                $start = googletvbeProto::decode($message[GTV_REMOTE_START][0]);
                $this->isOn = (bool) googletvbeProto::first($start, 1, 0);
                googletvbeLog('debug', $this->label . ' : ' . ($this->isOn ? 'allumée' : 'en veille'));
                call_user_func($this->publish, array('power' => $this->isOn ? 1 : 0));
                continue;
            }
            if (isset($message[GTV_REMOTE_IME_KEY_INJECT])) {
                $ime = googletvbeProto::decode($message[GTV_REMOTE_IME_KEY_INJECT][0]);
                if (isset($ime[1][0])) {
                    $app = googletvbeProto::decode($ime[1][0]);
                    $package = (string) googletvbeProto::first($app, 12, '');
                    if ($package !== '') {
                        googletvbeLog('debug', $this->label . ' : appli ' . $package);
                        call_user_func($this->publish, array('app' => $package));
                    }
                }
                continue;
            }
            if (isset($message[GTV_REMOTE_ERROR])) {
                googletvbeLog('warning', $this->label . ' : la TV signale une erreur (' . bin2hex($message[GTV_REMOTE_ERROR][0]) . ')');
            }
        }
    }

    public function key($_key, $_long = false) {
        $code = googletvbeRemoteCodec::keyCode($_key);
        if ($_long) {
            $this->send(googletvbeRemoteCodec::keyInject($code, googletvbeRemoteCodec::DIRECTION_START_LONG));
            $this->send(googletvbeRemoteCodec::keyInject($code, googletvbeRemoteCodec::DIRECTION_END_LONG));
            return;
        }
        $this->send(googletvbeRemoteCodec::keyInject($code));
    }

    public function openLink($_target) {
        $this->send(googletvbeRemoteCodec::appLink(googletvbeRemoteCodec::normalizeLink($_target)));
    }
}
