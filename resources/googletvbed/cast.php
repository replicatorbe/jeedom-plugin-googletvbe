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
 * Google Cast, port 8009 : volume, veille, application Cast, lecture.
 *
 * Chaque message est un CastMessage protobuf précédé de sa longueur sur
 * quatre octets gros-boutistes. Le contenu utile est du JSON, rangé dans un
 * « espace de noms » :
 *
 *   tp.connection  CONNECT / CLOSE : ouvrir un canal vers un destinataire
 *   tp.heartbeat   PING / PONG, toutes les 5 s dans les deux sens
 *   receiver       GET_STATUS, SET_VOLUME, LAUNCH, STOP → RECEIVER_STATUS
 *   media          GET_STATUS, PLAY, PAUSE… → MEDIA_STATUS
 *
 * Le destinataire « receiver-0 » est la TV elle-même. Une application Cast
 * lancée (YouTube, Spotify…) a son propre destinataire, son transportId, à
 * qui l'on parle média. Une fois connectés, les deux poussent leur état à
 * chaque changement : pas de relevé périodique.
 *
 * Une TCL (Android TV 11) annonce aussi ici les applis Android ouvertes à
 * la télécommande, Netflix par exemple (« appId »:"Netflix", sans
 * transportId) : on connaît l'appli, mais sans lecture Cast à piloter.
 */
class googletvbeCast extends googletvbeLink {

    const NS_CONNECTION = 'urn:x-cast:com.google.cast.tp.connection';
    const NS_HEARTBEAT = 'urn:x-cast:com.google.cast.tp.heartbeat';
    const NS_RECEIVER = 'urn:x-cast:com.google.cast.receiver';
    const NS_MEDIA = 'urn:x-cast:com.google.cast.media';

    const SENDER = 'sender-jeedom';
    const RECEIVER = 'receiver-0';
    const PING_EVERY = 5;

    /* État Cast du lecteur → valeur de la commande « Lecture ». */
    const PLAYER_STATES = array(
        'PLAYING' => 'lecture',
        'PAUSED' => 'pause',
        'BUFFERING' => 'chargement',
        'LOADING' => 'chargement',
        'IDLE' => 'arrêt',
    );

    /* Rappel : function (array $values) — les valeurs de commandes
     * changées, par identifiant logique. */
    private $publish;
    private $requestId = 0;
    private $lastPing = 0.0;
    private $level = null;
    private $step = 0.01;
    private $muted = false;
    private $sessionId = null;
    private $transport = null;
    private $mediaSession = null;
    private $playerState = '';
    public $raw = array('receiver' => null, 'media' => null);

    public function __construct($_label, $_host, $_publish) {
        parent::__construct($_label . ' [cast]', $_host, 8009);
        $this->publish = $_publish;
        $this->idleTimeout = 20;
    }

    /* ------------------------------------------------------------- CADRES */

    public static function frame($_source, $_destination, $_namespace, $_payload) {
        $message = googletvbeProto::int(1, 0)            /* protocol_version CASTV2_1_0 */
                 . googletvbeProto::bytes(2, $_source)
                 . googletvbeProto::bytes(3, $_destination)
                 . googletvbeProto::bytes(4, $_namespace)
                 . googletvbeProto::int(5, 0)            /* payload_type STRING */
                 . googletvbeProto::bytes(6, json_encode($_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return pack('N', strlen($message)) . $message;
    }

    /* Extrait les messages complets du tampon ; le reste attend la suite. */
    public static function unframe(&$_buffer) {
        $messages = array();
        while (strlen($_buffer) >= 4) {
            $length = unpack('N', substr($_buffer, 0, 4))[1];
            if ($length > 1048576) {
                throw new Exception('message Cast démesuré (' . $length . ' octets)');
            }
            if (strlen($_buffer) < 4 + $length) {
                break;
            }
            $fields = googletvbeProto::decode(substr($_buffer, 4, $length));
            $_buffer = (string) substr($_buffer, 4 + $length);
            $messages[] = array(
                'source' => (string) googletvbeProto::first($fields, 2, ''),
                'destination' => (string) googletvbeProto::first($fields, 3, ''),
                'namespace' => (string) googletvbeProto::first($fields, 4, ''),
                'payload' => json_decode((string) googletvbeProto::first($fields, 6, ''), true),
            );
        }
        return $messages;
    }

    private function emit($_destination, $_namespace, $_payload) {
        googletvbeLog('debug', $this->label . ' → ' . $_destination . ' ' . json_encode($_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->send(self::frame(self::SENDER, $_destination, $_namespace, $_payload));
    }

    private function request($_destination, $_namespace, $_payload) {
        $_payload['requestId'] = ++$this->requestId;
        $this->emit($_destination, $_namespace, $_payload);
    }

    /* ------------------------------------------------------------- CYCLE */

    protected function onReady() {
        $this->transport = null;
        /* Sans lecture Cast, l'état de lecture vaut « arrêt » et non rien. */
        $this->clearMedia();
        $this->emit(self::RECEIVER, self::NS_CONNECTION, array('type' => 'CONNECT'));
        $this->request(self::RECEIVER, self::NS_RECEIVER, array('type' => 'GET_STATUS'));
        $this->lastPing = googletvbeClock();
        call_user_func($this->publish, array('online' => 1));
    }

    protected function onDown($_reason) {
        $this->transport = null;
        $this->mediaSession = null;
        call_user_func($this->publish, array('online' => 0));
    }

    protected function onTick($_now) {
        if ($_now - $this->lastPing >= self::PING_EVERY) {
            $this->lastPing = $_now;
            $this->send(self::frame(self::SENDER, self::RECEIVER, self::NS_HEARTBEAT, array('type' => 'PING')));
        }
    }

    protected function onData() {
        foreach (self::unframe($this->in) as $message) {
            $payload = $message['payload'];
            if (!is_array($payload) || !isset($payload['type'])) {
                continue;
            }
            if ($message['namespace'] === self::NS_HEARTBEAT) {
                if ($payload['type'] === 'PING') {
                    $this->send(self::frame(self::SENDER, $message['source'], self::NS_HEARTBEAT, array('type' => 'PONG')));
                }
                continue;
            }
            googletvbeLog('debug', $this->label . ' ← ' . $message['source'] . ' ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if ($message['namespace'] === self::NS_CONNECTION && $payload['type'] === 'CLOSE') {
                /* L'application s'est fermée de son côté. */
                if ($message['source'] === $this->transport) {
                    $this->transport = null;
                    $this->clearMedia();
                }
                continue;
            }
            if ($payload['type'] === 'RECEIVER_STATUS' && isset($payload['status'])) {
                $this->receiverStatus($payload['status']);
            } elseif ($payload['type'] === 'MEDIA_STATUS' && $message['source'] === $this->transport) {
                $this->mediaStatus(isset($payload['status']) ? $payload['status'] : array());
            } elseif (in_array($payload['type'], array('LAUNCH_ERROR', 'INVALID_REQUEST', 'LOAD_FAILED', 'INVALID_PLAYER_STATE'), true)) {
                googletvbeLog('warning', $this->label . ' : la TV refuse (' . $payload['type']
                    . (isset($payload['reason']) ? ', ' . $payload['reason'] : '') . ')');
            }
        }
    }

    /* ------------------------------------------------------------- ÉTAT */

    public static function receiverValues($_status) {
        $values = array();
        if (isset($_status['volume']['level'])) {
            $values['volume'] = (int) round($_status['volume']['level'] * 100);
        }
        if (isset($_status['volume']['muted'])) {
            $values['muted'] = $_status['volume']['muted'] ? 1 : 0;
        }
        if (isset($_status['isStandBy'])) {
            $values['power'] = $_status['isStandBy'] ? 0 : 1;
        }
        if (isset($_status['isActiveInput'])) {
            $values['active_input'] = $_status['isActiveInput'] ? 1 : 0;
        }
        $app = self::mainApplication($_status);
        $values['cast_app'] = $app !== null && isset($app['displayName']) ? (string) $app['displayName'] : '';
        $values['cast_app_id'] = $app !== null && isset($app['appId']) ? (string) $app['appId'] : '';
        $values['status_text'] = $app !== null && isset($app['statusText']) ? (string) $app['statusText'] : '';
        return $values;
    }

    /* L'application au premier plan. L'écran de veille Cast (Backdrop) ne
     * compte pas comme une application lancée. */
    public static function mainApplication($_status) {
        foreach (isset($_status['applications']) && is_array($_status['applications']) ? $_status['applications'] : array() as $app) {
            if (empty($app['isIdleScreen'])) {
                return $app;
            }
        }
        return null;
    }

    public static function hasMedia($_app) {
        foreach (isset($_app['namespaces']) && is_array($_app['namespaces']) ? $_app['namespaces'] : array() as $namespace) {
            if (isset($namespace['name']) && $namespace['name'] === self::NS_MEDIA) {
                return true;
            }
        }
        return false;
    }

    private function receiverStatus($_status) {
        $this->raw['receiver'] = $_status;
        if (isset($_status['volume']['level'])) {
            $this->level = (float) $_status['volume']['level'];
        }
        if (isset($_status['volume']['stepInterval']) && $_status['volume']['stepInterval'] > 0) {
            $this->step = (float) $_status['volume']['stepInterval'];
        }
        if (isset($_status['volume']['muted'])) {
            $this->muted = (bool) $_status['volume']['muted'];
        }
        $app = self::mainApplication($_status);
        $this->sessionId = ($app !== null && isset($app['sessionId'])) ? $app['sessionId'] : null;
        $transport = ($app !== null && isset($app['transportId']) && self::hasMedia($app)) ? $app['transportId'] : null;
        if ($transport !== $this->transport) {
            $this->transport = $transport;
            $this->clearMedia();
            if ($transport !== null) {
                $this->emit($transport, self::NS_CONNECTION, array('type' => 'CONNECT'));
                $this->request($transport, self::NS_MEDIA, array('type' => 'GET_STATUS'));
            }
        }
        call_user_func($this->publish, self::receiverValues($_status));
    }

    public static function mediaValues($_entry) {
        $values = array();
        if (isset($_entry['playerState'])) {
            $state = (string) $_entry['playerState'];
            $values['media_state'] = isset(self::PLAYER_STATES[$state]) ? self::PLAYER_STATES[$state] : strtolower($state);
        }
        /* « media » n'est renvoyé qu'au changement de contenu : son absence
         * ne vide pas le titre. */
        if (isset($_entry['media']) && is_array($_entry['media'])) {
            $meta = isset($_entry['media']['metadata']) && is_array($_entry['media']['metadata']) ? $_entry['media']['metadata'] : array();
            $values['media_title'] = isset($meta['title']) ? (string) $meta['title'] : '';
            $subtitle = '';
            foreach (array('artist', 'seriesTitle', 'subtitle', 'albumName', 'studio') as $key) {
                if (isset($meta[$key]) && $meta[$key] !== '') {
                    $subtitle = (string) $meta[$key];
                    break;
                }
            }
            $values['media_subtitle'] = $subtitle;
        }
        return $values;
    }

    private function mediaStatus($_status) {
        $this->raw['media'] = $_status;
        if (!is_array($_status) || count($_status) === 0) {
            $this->mediaSession = null;
            $this->playerState = 'IDLE';
            call_user_func($this->publish, array('media_state' => 'arrêt', 'media_title' => '', 'media_subtitle' => ''));
            return;
        }
        $entry = $_status[0];
        if (isset($entry['mediaSessionId'])) {
            $this->mediaSession = $entry['mediaSessionId'];
        }
        if (isset($entry['playerState'])) {
            $this->playerState = (string) $entry['playerState'];
        }
        call_user_func($this->publish, self::mediaValues($entry));
    }

    private function clearMedia() {
        $this->mediaSession = null;
        $this->playerState = '';
        $this->raw['media'] = null;
        call_user_func($this->publish, array('media_state' => 'arrêt', 'media_title' => '', 'media_subtitle' => ''));
    }

    /* ------------------------------------------------------------ ORDRES */

    public function refresh() {
        $this->request(self::RECEIVER, self::NS_RECEIVER, array('type' => 'GET_STATUS'));
        if ($this->transport !== null) {
            $this->request($this->transport, self::NS_MEDIA, array('type' => 'GET_STATUS'));
        }
    }

    /* Niveau de 0 à 100. */
    public function setVolume($_percent) {
        $level = max(0, min(100, (float) $_percent)) / 100;
        $this->request(self::RECEIVER, self::NS_RECEIVER, array('type' => 'SET_VOLUME', 'volume' => array('level' => round($level, 3))));
        /* Retenu sans attendre la réponse : deux « Volume + » rapprochés
         * font deux pas, pas un. */
        $this->level = $level;
    }

    /* Pas en pourcents, positif ou négatif, depuis le dernier niveau connu. */
    public function stepVolume($_delta) {
        if ($this->level === null) {
            throw new Exception('volume actuel inconnu');
        }
        $this->setVolume($this->level * 100 + $_delta);
    }

    public function setMuted($_muted) {
        $muted = ($_muted === 'toggle') ? !$this->muted : (bool) $_muted;
        $this->request(self::RECEIVER, self::NS_RECEIVER, array('type' => 'SET_VOLUME', 'volume' => array('muted' => $muted)));
    }

    public function launch($_appId) {
        $appId = strtoupper(trim((string) $_appId));
        if (!preg_match('/^[0-9A-F]{8}$/', $appId)) {
            throw new Exception('identifiant d\'application Cast invalide : « ' . $_appId . ' » (huit caractères hexadécimaux)');
        }
        $this->request(self::RECEIVER, self::NS_RECEIVER, array('type' => 'LAUNCH', 'appId' => $appId));
    }

    public function stopApp() {
        if ($this->sessionId === null) {
            throw new Exception('aucune application Cast en cours');
        }
        $this->request(self::RECEIVER, self::NS_RECEIVER, array('type' => 'STOP', 'sessionId' => $this->sessionId));
    }

    /* PLAY, PAUSE, STOP, QUEUE_NEXT, QUEUE_PREV, SEEK, ou TOGGLE qui choisit
     * entre lecture et pause selon l'état connu. */
    public function media($_type, $_extra = array()) {
        if ($this->transport === null || $this->mediaSession === null) {
            throw new Exception('aucune lecture Cast en cours');
        }
        $type = $_type;
        if ($type === 'TOGGLE') {
            $type = ($this->playerState === 'PLAYING' || $this->playerState === 'BUFFERING') ? 'PAUSE' : 'PLAY';
        }
        if ($type === 'QUEUE_NEXT' || $type === 'QUEUE_PREV') {
            $_extra = array('type' => 'QUEUE_UPDATE', 'jump' => $type === 'QUEUE_NEXT' ? 1 : -1);
            $type = 'QUEUE_UPDATE';
        }
        $this->request($this->transport, self::NS_MEDIA, array_merge(array('type' => $type, 'mediaSessionId' => $this->mediaSession), $_extra));
    }
}
