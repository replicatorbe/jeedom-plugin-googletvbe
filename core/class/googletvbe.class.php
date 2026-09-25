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

require_once __DIR__ . '/../../../../core/php/core.inc.php';

/*
 * Pilotage local des téléviseurs Google TV / Android TV (TCL, Sony, Philips,
 * Chromecast avec Google TV…), en PHP natif, sans cloud.
 *
 * La TV parle d'elle-même et attend qu'on lui réponde toutes les cinq
 * secondes : les connexions sont donc tenues par un démon
 * (resources/googletvbed), qui pousse les changements ici (ingestPush) et
 * reçoit les ordres sur un port local (daemonOrder). La classe ne parle
 * jamais directement à la TV, sauf pour l'identifier (probe) et pour le
 * réveil par le réseau, qui n'a besoin d'aucune connexion.
 *
 * Protocoles employés :
 *   - Google Cast (port 8009) : volume absolu, muet, veille, application
 *     Cast et lecture en cours ;
 *   - télécommande Android TV (ports 6466/6467), une fois la TV appairée :
 *     touches, marche/arrêt, lancement d'applis, appli au premier plan ;
 *   - Wake-on-LAN : allumage d'une TV dont le réseau dort ;
 *   - TvOverlay (appli com.tabdeveloper.tvoverlay, API HTTP sur le port
 *     5001), si elle est installée : ce plugin la garde en marche, en la
 *     relançant par la télécommande quand Android l'a arrêtée. Les
 *     notifications elles-mêmes sont l'affaire du plugin TvOverlay
 *     (tvoverlaybe), qui appelle overlayRelaunch() au besoin.
 *
 * L'identifiant logique d'un équipement est l'UDN de la TV (uuid annoncé en
 * SSDP) : une adresse IP qui change est retrouvée par la recherche sans
 * recréer l'équipement.
 */
class googletvbe extends eqLogic {

    /* ============================================================= RÉGLAGES */

    const DEFAULT_PORT = 55180;
    const DEFAULT_VOLUME_STEP = 2;

    const OVERLAY_PACKAGE = 'com.tabdeveloper.tvoverlay';
    const OVERLAY_PORT = 5001;
    /* Entre deux relances automatiques de TvOverlay : une appli qui meurt
     * aussitôt relancée ne doit pas faire clignoter l'écran chaque minute. */
    const OVERLAY_RELAUNCH_EVERY = 600;

    /* Une relance en cours ou toute récente n'est pas refaite : deux
     * séquences mêlées (OK, Retour, Retour, Lecture ×2) feraient sortir de
     * l'appli regardée. */
    const RELAUNCH_LOCK = 120;

    /* Relance automatique de TvOverlay : jamais, dans les minutes qui
     * suivent l'allumage (l'écran d'accueil est affiché, personne n'est
     * dérangé), ou à tout moment (quitte à interrompre un film). */
    const WATCHDOG_OFF = 0;
    const WATCHDOG_AFTER_POWER_ON = 1;
    const WATCHDOG_ALWAYS = 2;
    const WATCHDOG_WINDOW = 180;

    /* Applications Cast lançables depuis la liste. L'identifiant est celui
     * de l'application réceptrice Cast, pas le nom du paquet Android. */
    const CAST_APPS = array(
        '233637DE' => 'YouTube',
        'CA5E8412' => 'Netflix',
        'CC32E753' => 'Spotify',
        '9AC194DC' => 'Plex',
    );

    /* Applis lançables par la télécommande : des liens que l'appli installée
     * sait ouvrir. Pas de nom de paquet : il passe par la fiche Play Store,
     * où OK installerait une appli absente. */
    const REMOTE_APPS = array(
        'https://www.youtube.com' => 'YouTube',
        'https://www.netflix.com/title' => 'Netflix',
        'https://app.primevideo.com' => 'Prime Video',
        'https://www.disneyplus.com' => 'Disney+',
        'spotify://' => 'Spotify',
        'plex://' => 'Plex',
    );

    /* Commandes qui ne font qu'envoyer une touche de télécommande. */
    const KEY_COMMANDS = array(
        'up' => 'DPAD_UP', 'down' => 'DPAD_DOWN', 'left' => 'DPAD_LEFT', 'right' => 'DPAD_RIGHT',
        'ok' => 'DPAD_CENTER', 'back' => 'BACK', 'home' => 'HOME', 'menu' => 'MENU',
        'settings' => 'SETTINGS', 'guide' => 'GUIDE', 'info' => 'INFO',
        'channel_up' => 'CHANNEL_UP', 'channel_down' => 'CHANNEL_DOWN', 'input' => 'TV_INPUT',
        'hdmi1' => 'TV_INPUT_HDMI_1', 'hdmi2' => 'TV_INPUT_HDMI_2', 'hdmi3' => 'TV_INPUT_HDMI_3',
        'hdmi4' => 'TV_INPUT_HDMI_4', 'rewind' => 'MEDIA_REWIND', 'forward' => 'MEDIA_FAST_FORWARD',
    );

    /* Les commandes, dans leur ordre d'affichage. Seules les absentes sont
     * créées : un nom ou une icône changés par l'utilisateur sont gardés. */
    const COMMANDS = array(
        'online'         => array('name' => 'En ligne', 'type' => 'info', 'subType' => 'binary'),
        'remote'         => array('name' => 'Télécommande connectée', 'type' => 'info', 'subType' => 'binary', 'visible' => 0),
        'power'          => array('name' => 'Allumée', 'type' => 'info', 'subType' => 'binary'),
        'app'            => array('name' => 'Application', 'type' => 'info', 'subType' => 'string'),
        'volume'         => array('name' => 'Volume', 'type' => 'info', 'subType' => 'numeric', 'unite' => '%', 'minValue' => 0, 'maxValue' => 100),
        'muted'          => array('name' => 'Muet', 'type' => 'info', 'subType' => 'binary'),
        'cast_app'       => array('name' => 'Application Cast', 'type' => 'info', 'subType' => 'string'),
        'cast_app_id'    => array('name' => 'ID application Cast', 'type' => 'info', 'subType' => 'string', 'visible' => 0),
        'status_text'    => array('name' => 'Statut Cast', 'type' => 'info', 'subType' => 'string'),
        'overlay'        => array('name' => 'TvOverlay actif', 'type' => 'info', 'subType' => 'binary', 'visible' => 0),
        'media_state'    => array('name' => 'État de lecture', 'type' => 'info', 'subType' => 'string'),
        'media_title'    => array('name' => 'Titre', 'type' => 'info', 'subType' => 'string'),
        'media_subtitle' => array('name' => 'Artiste - série', 'type' => 'info', 'subType' => 'string'),
        'active_input'   => array('name' => 'Entrée active', 'type' => 'info', 'subType' => 'binary', 'visible' => 0),

        'refresh'        => array('name' => 'Rafraîchir', 'type' => 'action', 'subType' => 'other'),
        'power_on'       => array('name' => 'Allumer', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-power-off'),
        'power_off'      => array('name' => 'Éteindre', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-power-off'),
        'power_toggle'   => array('name' => 'Marche-arrêt', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'wol'            => array('name' => 'Allumer (réveil réseau)', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'up'             => array('name' => 'Haut', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-chevron-up'),
        'down'           => array('name' => 'Bas', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-chevron-down'),
        'left'           => array('name' => 'Gauche', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-chevron-left'),
        'right'          => array('name' => 'Droite', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-chevron-right'),
        'ok'             => array('name' => 'OK', 'type' => 'action', 'subType' => 'other', 'icon' => 'far fa-dot-circle'),
        'back'           => array('name' => 'Retour', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-undo'),
        'home'           => array('name' => 'Accueil', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-home'),
        'menu'           => array('name' => 'Menu', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'settings'       => array('name' => 'Paramètres', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'guide'          => array('name' => 'Guide', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'info'           => array('name' => 'Info', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'channel_up'     => array('name' => 'Chaîne +', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'channel_down'   => array('name' => 'Chaîne -', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'input'          => array('name' => 'Source', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'hdmi1'          => array('name' => 'HDMI 1', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'hdmi2'          => array('name' => 'HDMI 2', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'hdmi3'          => array('name' => 'HDMI 3', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'hdmi4'          => array('name' => 'HDMI 4', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'open_app'       => array('name' => 'Lancer une application', 'type' => 'action', 'subType' => 'select'),
        'open_link'      => array('name' => 'Ouvrir un lien ou un paquet', 'type' => 'action', 'subType' => 'message', 'visible' => 0),
        'key'            => array('name' => 'Touche', 'type' => 'action', 'subType' => 'message', 'visible' => 0),
        'volume_set'     => array('name' => 'Régler le volume', 'type' => 'action', 'subType' => 'slider', 'value' => 'volume', 'minValue' => 0, 'maxValue' => 100),
        'volume_up'      => array('name' => 'Volume +', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-volume-up'),
        'volume_down'    => array('name' => 'Volume -', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-volume-down'),
        'mute_on'        => array('name' => 'Couper le son', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-volume-mute'),
        'mute_off'       => array('name' => 'Rétablir le son', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-volume-off'),
        'mute_toggle'    => array('name' => 'Muet on-off', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'play'           => array('name' => 'Lire', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-play'),
        'pause'          => array('name' => 'Pause', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-pause'),
        'play_pause'     => array('name' => 'Lecture-pause', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'stop'           => array('name' => 'Stop', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-stop'),
        'previous'       => array('name' => 'Précédent', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-step-backward'),
        'next'           => array('name' => 'Suivant', 'type' => 'action', 'subType' => 'other', 'icon' => 'fas fa-step-forward'),
        'rewind'         => array('name' => 'Retour rapide', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'forward'        => array('name' => 'Avance rapide', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'launch'         => array('name' => 'Lancer une application Cast', 'type' => 'action', 'subType' => 'select'),
        'launch_id'      => array('name' => 'Lancer par ID Cast', 'type' => 'action', 'subType' => 'message', 'visible' => 0),
        'app_stop'       => array('name' => 'Quitter l’application Cast', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'overlay_restart' => array('name' => 'Relancer TvOverlay', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
    );

    /* ================================================================ CRON */

    /* Chaque minute, TvOverlay est vérifiée sur les TV allumées : Android
     * l'arrête volontiers, et une notification perdue ne se voit pas. Une
     * relance dure une vingtaine de secondes : elle part en tâche de fond,
     * pour ne pas retarder le cron des autres plugins. */
    public static function cron() {
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            if (!$eqLogic->overlayEnabled()) {
                continue;
            }
            try {
                $eqLogic->overlayWatch();
            } catch (Throwable $e) {
                log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : TvOverlay : ' . $e->getMessage());
            }
        }
    }

    /* Commandes retirées du plugin, supprimées des équipements à la mise à
     * jour : les notifications sont passées au plugin TvOverlay. */
    const OBSOLETE_COMMANDS = array('notify', 'notify_json', 'notify_fixed');

    /* =============================================================== DÉMON */

    public static function deamon_info() {
        $return = array('log' => __CLASS__ . 'd', 'state' => 'nok', 'launchable' => 'ok');
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid != '' && @posix_getsid((int) $pid)) {
                $return['state'] = 'ok';
            } else {
                @unlink($pid_file);
            }
        }
        /* Un démon vivant qui ne joint plus Jeedom (clé API changée, accès
         * API restreint) ne pousse plus rien : il est déclaré arrêté, pour
         * que la gestion automatique le relance. Il rappelle chaque minute. */
        if ($return['state'] == 'ok' && time() - (int) @filemtime($pid_file) > 150
            && time() - (int) cache::byKey('googletvbe::daemon_seen')->getValue(0) > 150) {
            $return['state'] = 'nok';
        }
        return $return;
    }

    public static function deamon_start() {
        self::deamon_stop();
        $daemon = realpath(__DIR__ . '/../../resources/googletvbed/googletvbed.php');
        $cmd  = 'php ' . escapeshellarg($daemon);
        $cmd .= ' --callback ' . escapeshellarg(self::getCallbackUrl());
        $cmd .= ' --pid ' . escapeshellarg(jeedom::getTmpFolder(__CLASS__) . '/deamon.pid');
        $cmd .= ' --stamp ' . escapeshellarg(self::stampFile());
        $cmd .= ' --port ' . (int) self::daemonPort();
        $cmd .= ' --loglevel ' . escapeshellarg(log::convertLogLevel(log::getLogLevel(__CLASS__)));
        $cmd .= ' --timezone ' . escapeshellarg(date_default_timezone_get());

        /* La clé API passe par un fichier lisible du seul www-data, que le
         * démon efface après l'avoir lu : ni en argument ni dans un « echo »,
         * que ps montre à n'importe quel utilisateur local. */
        $keyFile = jeedom::getTmpFolder(__CLASS__) . '/daemon.key';
        @unlink($keyFile);
        $old = umask(0077);
        file_put_contents($keyFile, jeedom::getApiKey(__CLASS__));
        umask($old);
        $cmd .= ' --keyfile ' . escapeshellarg($keyFile);
        $full = $cmd . ' >> ' . log::getPathToLog(__CLASS__ . 'd') . ' 2>&1 &';
        log::add(__CLASS__, 'info', __('Lancement du démon', __FILE__));
        exec($full);

        for ($i = 1; $i <= 20; $i++) {
            if (self::deamon_info()['state'] == 'ok') {
                message::removeAll(__CLASS__, 'unableStartDeamon');
                return true;
            }
            sleep(1);
        }
        log::add(__CLASS__, 'error', __('Le démon n\'a pas démarré. Consultez le journal', __FILE__) . ' ' . __CLASS__ . 'd.');
        return false;
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid != '') {
                system::kill($pid);
            }
            @unlink($pid_file);
        }
        system::kill('resources/googletvbed/googletvbed.php');
        /* Plus personne ne tient les connexions : l'état affiché ne doit pas
         * les dire ouvertes. */
        foreach (self::byType(__CLASS__) as $eqLogic) {
            $eqLogic->checkAndUpdateCmd('online', 0);
            $eqLogic->checkAndUpdateCmd('remote', 0);
        }
        return true;
    }

    public static function daemonPort() {
        $port = (int) config::byKey('daemon_port', __CLASS__, self::DEFAULT_PORT);
        return ($port > 1024 && $port < 65536) ? $port : self::DEFAULT_PORT;
    }

    public static function getCallbackUrl() {
        return network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp')
             . '/plugins/googletvbe/core/php/jeeGoogletvbe.php';
    }

    public static function stampFile() {
        return jeedom::getTmpFolder(__CLASS__) . '/devices.stamp';
    }

    /* Le démon relit la liste des TV quand le contenu de ce fichier change. */
    public static function notifyDaemon() {
        @file_put_contents(self::stampFile(), sprintf('%.6f', microtime(true)));
    }

    /* Réglage du port changé : le démon doit être relancé pour l'écouter. */
    public static function postConfig_daemon_port($_value) {
        if (self::deamon_info()['state'] == 'ok') {
            self::deamon_start();
        }
    }

    /* Les TV que le démon doit suivre, et le certificat client de la
     * télécommande. */
    public static function daemonDevices() {
        $devices = array();
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            if ($eqLogic->isConfigured()) {
                $devices[] = array('id' => (int) $eqLogic->getId(), 'name' => $eqLogic->getHumanName(),
                                   'ip' => $eqLogic->getConfiguration('ip'),
                                   'paired' => (int) $eqLogic->getConfiguration('paired', 0));
            }
        }
        $client = array('cert' => '', 'key' => '');
        try {
            $client = self::clientCertificate();
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $e->getMessage());
        }
        return array('devices' => $devices, 'client' => $client);
    }

    /* L'identité de Jeedom auprès des TV : une clé RSA et un certificat
     * auto-signé, créés une fois et gardés dans la configuration du plugin.
     * Pas dans data/ : le déploiement ne préserve pas ce dossier, et une
     * clé perdue oblige à réappairer toutes les TV. */
    public static function clientCertificate() {
        $cert = (string) config::byKey('client_cert', __CLASS__, '');
        $key = (string) config::byKey('client_key', __CLASS__, '');
        if ($cert !== '' && $key !== '') {
            return array('cert' => $cert, 'key' => $key);
        }
        $options = array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256');
        $private = openssl_pkey_new($options);
        if ($private === false) {
            throw new Exception(__('Impossible de créer la clé de la télécommande :', __FILE__) . ' ' . openssl_error_string());
        }
        $csr = openssl_csr_new(array('commonName' => 'atvremote', 'organizationName' => 'Jeedom'), $private, $options);
        $x509 = openssl_csr_sign($csr, null, $private, 3650, $options, random_int(1000, 2147483647));
        if ($x509 === false || !openssl_x509_export($x509, $cert) || !openssl_pkey_export($private, $key)) {
            throw new Exception(__('Impossible de créer le certificat de la télécommande :', __FILE__) . ' ' . openssl_error_string());
        }
        config::save('client_cert', $cert, __CLASS__);
        config::save('client_key', $key, __CLASS__);
        return array('cert' => $cert, 'key' => $key);
    }

    /* Un ordre au démon, une ligne JSON aller et retour. */
    public static function daemonCall($_request, $_timeout = 3) {
        $_request['key'] = jeedom::getApiKey(__CLASS__);
        $socket = @stream_socket_client('tcp://127.0.0.1:' . self::daemonPort(), $errno, $errstr, $_timeout);
        if ($socket === false) {
            throw new Exception(__('Le démon ne répond pas : vérifiez qu\'il est démarré.', __FILE__));
        }
        stream_set_timeout($socket, $_timeout);
        fwrite($socket, json_encode($_request) . "\n");
        $line = fgets($socket);
        fclose($socket);
        $reply = is_string($line) ? json_decode($line, true) : null;
        if (!is_array($reply)) {
            throw new Exception(__('Réponse illisible du démon.', __FILE__));
        }
        if (empty($reply['ok'])) {
            throw new Exception(isset($reply['error']) ? $reply['error'] : __('Ordre refusé par le démon.', __FILE__));
        }
        return $reply;
    }

    public function daemonOrder($_do, $_value = null, $_timeout = 3) {
        return self::daemonCall(array('eq' => (int) $this->getId(), 'do' => $_do, 'value' => $_value), $_timeout);
    }

    /* ========================================================= APPAIRAGE */

    /* La TV affiche un code. Le démon garde la connexion ouverte jusqu'à
     * pairFinish(). */
    public function pairStart() {
        $this->daemonOrder('pair_start', null, 45);
    }

    public function pairFinish($_code) {
        $this->daemonOrder('pair_finish', trim((string) $_code), 45);
        $this->setConfiguration('paired', 1);
        $this->save();
        log::add(__CLASS__, 'info', $this->getHumanName() . ' : télécommande appairée');
    }

    /* Abandonne un appairage commencé, sans toucher à un appairage
     * existant. */
    public function pairCancel() {
        $this->daemonOrder('pair_cancel');
    }

    public function unpair() {
        try {
            $this->daemonOrder('pair_cancel');
        } catch (Throwable $e) {
        }
        $this->setConfiguration('paired', 0);
        $this->save();
    }

    /* Valeurs poussées par le démon : seules celles qui ont changé. */
    public function ingestPush($_values) {
        if (!is_array($_values)) {
            return;
        }
        if (array_key_exists('_raw', $_values)) {
            $this->setCache('raw', $_values['_raw']);
            unset($_values['_raw']);
        }
        if (isset($_values['power']) && (int) $_values['power'] === 1) {
            $power = $this->getCmd('info', 'power');
            if (is_object($power) && (int) $power->execCmd() !== 1) {
                $this->setCache('power_on_at', time());
            }
        }
        foreach ($_values as $logicalId => $value) {
            if (isset(self::COMMANDS[$logicalId]) && self::COMMANDS[$logicalId]['type'] === 'info') {
                $this->checkAndUpdateCmd($logicalId, $value);
            }
        }
    }

    /* ======================================================== CYCLE DE VIE */

    /* Aucune exception ici : le coeur crée l'équipement avec son seul nom. */
    public function preSave() {
        if ($this->getId() == '') {
            $this->setIsEnable(1);
            $this->setIsVisible(1);
        }
        $this->setConfiguration('ip', trim((string) $this->getConfiguration('ip', '')));
        $this->setConfiguration('mac', implode(', ', self::parseMacs($this->getConfiguration('mac', ''))));
        /* Une case absente de la configuration s'afficherait décochée alors
         * que la relance est active par défaut. */
        if ($this->getConfiguration('overlay_watchdog', '') === '') {
            $this->setConfiguration('overlay_watchdog', self::WATCHDOG_AFTER_POWER_ON);
        }
        if ($this->getConfiguration('overlay_resume', '') === '') {
            $this->setConfiguration('overlay_resume', 1);
        }
    }

    public function postSave() {
        $this->createCommands();
        self::notifyDaemon();
    }

    public function postRemove() {
        self::notifyDaemon();
    }

    public function isConfigured() {
        return $this->getConfiguration('ip', '') !== '';
    }

    public function createCommands() {
        foreach (self::OBSOLETE_COMMANDS as $logicalId) {
            $cmd = $this->getCmd(null, $logicalId);
            if (is_object($cmd)) {
                $cmd->remove();
            }
        }
        $order = 0;
        foreach (self::COMMANDS as $logicalId => $def) {
            $order++;
            $cmd = $this->getCmd(null, $logicalId);
            if (is_object($cmd)) {
                /* Noms abîmés par le coeur avant la 0.2 : « / » et « ' » y sont
                 * retirés (« Marchearrêt »). Seul un nom resté tel quel est
                 * corrigé, jamais un nom choisi par l'utilisateur. */
                $legacy = cleanComponanteName(strtr($def['name'], array('’' => "'", ' - ' => ' / ', '-' => '/')));
                if ($legacy !== $def['name'] && $cmd->getName() === $legacy) {
                    $cmd->setName($def['name']);
                    try {
                        $cmd->save();
                    } catch (Throwable $e) {
                        log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . $e->getMessage());
                    }
                }
                /* Les listes de choix suivent les versions du plugin. */
                $list = self::choiceList($logicalId);
                if ($list !== null && $cmd->getConfiguration('listValue') !== $list) {
                    $cmd->setConfiguration('listValue', $list);
                    $cmd->save();
                }
                continue;
            }
            try {
                $this->createCommand($logicalId, $def, $order);
            } catch (Throwable $e) {
                /* Un nom déjà pris par une commande renommée : les autres
                 * commandes sont créées quand même. */
                log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . __('commande', __FILE__) . ' ' . $logicalId . ' : ' . $e->getMessage());
            }
        }
    }

    private function createCommand($_logicalId, $_def, $_order) {
        $cmd = new googletvbeCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);
        $cmd->setName(__($_def['name'], __FILE__));
        $cmd->setType($_def['type']);
        $cmd->setSubType($_def['subType']);
        $cmd->setOrder($_order);
        $cmd->setIsVisible(isset($_def['visible']) ? $_def['visible'] : 1);
        if (isset($_def['unite'])) {
            $cmd->setUnite($_def['unite']);
        }
        if (isset($_def['minValue'])) {
            $cmd->setConfiguration('minValue', $_def['minValue']);
            $cmd->setConfiguration('maxValue', $_def['maxValue']);
        }
        if (isset($_def['icon'])) {
            $cmd->setDisplay('icon', '<i class="' . $_def['icon'] . '"></i>');
        }
        if (self::choiceList($_logicalId) !== null) {
            $cmd->setConfiguration('listValue', self::choiceList($_logicalId));
        }
        if (isset($_def['value'])) {
            $info = $this->getCmd('info', $_def['value']);
            if (is_object($info)) {
                $cmd->setValue($info->getId());
            }
        }
        $cmd->save();
    }

    private static function choiceList($_logicalId) {
        $apps = array('launch' => self::CAST_APPS, 'open_app' => self::REMOTE_APPS);
        if (!isset($apps[$_logicalId])) {
            return null;
        }
        $choices = array();
        foreach ($apps[$_logicalId] as $id => $name) {
            $choices[] = $id . '|' . $name;
        }
        return implode(';', $choices);
    }

    /* ============================================================= ORDRES */

    public function runAction($_logicalId, $_options = array()) {
        if (isset(self::KEY_COMMANDS[$_logicalId])) {
            $this->daemonOrder('key', self::KEY_COMMANDS[$_logicalId]);
            return;
        }
        switch ($_logicalId) {
            case 'power_on':
                $this->powerOn();
                return;
            case 'power_off':
                $this->daemonOrder('power', 'off');
                return;
            case 'power_toggle':
                $this->daemonOrder('power', 'toggle');
                return;
            case 'open_app':
                $this->openTarget(isset($_options['select']) ? $_options['select'] : '');
                return;
            case 'open_link':
            case 'key':
                /* Dans un scénario, la valeur peut venir du titre ou du
                 * message. */
                $value = trim((string) (isset($_options['message']) ? $_options['message'] : ''));
                if ($value === '' && isset($_options['title'])) {
                    $value = trim((string) $_options['title']);
                }
                if ($_logicalId === 'key') {
                    $this->daemonOrder('key', $value);
                } else {
                    $this->openTarget($value);
                }
                return;
            case 'refresh':
                $this->daemonOrder('refresh');
                return;
            case 'wol':
                $this->wake();
                return;
            case 'volume_set':
                if (!isset($_options['slider']) || !is_numeric($_options['slider'])) {
                    throw new Exception(__('Volume attendu, de 0 à 100.', __FILE__));
                }
                $this->daemonOrder('volume_set', (float) $_options['slider']);
                return;
            case 'volume_up':
            case 'volume_down':
                $step = max(1, (int) config::byKey('volume_step', __CLASS__, self::DEFAULT_VOLUME_STEP));
                $this->daemonOrder('volume_step', $_logicalId === 'volume_up' ? $step : -$step);
                return;
            case 'mute_on':
                $this->daemonOrder('mute', 1);
                return;
            case 'mute_off':
                $this->daemonOrder('mute', 0);
                return;
            case 'mute_toggle':
                $this->daemonOrder('mute', 'toggle');
                return;
            case 'play':
            case 'pause':
            case 'stop':
                $this->daemonOrder('media', strtoupper($_logicalId));
                return;
            case 'play_pause':
                $this->daemonOrder('media', 'TOGGLE');
                return;
            case 'next':
                $this->daemonOrder('media', 'QUEUE_NEXT');
                return;
            case 'previous':
                $this->daemonOrder('media', 'QUEUE_PREV');
                return;
            case 'launch':
                $this->daemonOrder('launch', isset($_options['select']) ? $_options['select'] : '');
                return;
            case 'launch_id':
                /* Dans un scénario, l'identifiant peut venir du titre ou du
                 * message. */
                $id = trim((string) (isset($_options['message']) ? $_options['message'] : ''));
                if ($id === '' && isset($_options['title'])) {
                    $id = trim((string) $_options['title']);
                }
                $this->daemonOrder('launch', $id);
                return;
            case 'app_stop':
                $this->daemonOrder('app_stop');
                return;
            case 'overlay_restart':
                $this->overlayRelaunch();
                return;
        }
        throw new Exception(__('Commande inconnue :', __FILE__) . ' ' . $_logicalId);
    }

    /* Depuis la veille, la touche Marche de la télécommande. Si la TV ne
     * répond plus du tout (réseau coupé), le réveil par le réseau, quand
     * une adresse MAC est connue. */
    public function powerOn() {
        try {
            $this->daemonOrder('power', 'on');
            return;
        } catch (Throwable $e) {
            if (count(self::parseMacs($this->getConfiguration('mac', ''))) === 0) {
                throw $e;
            }
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . $e->getMessage() . ' ; réveil par le réseau');
        }
        $this->wake();
    }

    /* ========================================================= TVOVERLAY */

    public function overlayEnabled() {
        return $this->isConfigured() && (int) $this->getConfiguration('overlay', 0) === 1;
    }

    private function overlayPort($_port = null) {
        $port = (int) ($_port !== null ? $_port : $this->getConfiguration('overlay_port', self::OVERLAY_PORT));
        return ($port > 0 && $port < 65536) ? $port : self::OVERLAY_PORT;
    }

    /* Un appel à l'API de TvOverlay. Lève une exception si l'appli ne
     * répond pas ou refuse. */
    public function overlayRequest($_path, $_body = null, $_timeout = 3, $_port = null) {
        $ch = curl_init('http://' . $this->getConfiguration('ip') . ':' . $this->overlayPort($_port) . $_path);
        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $_timeout,
            CURLOPT_TIMEOUT => $_timeout,
            CURLOPT_PROXY => '',
        );
        if ($_body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($_body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $options[CURLOPT_HTTPHEADER] = array('Content-Type: application/json');
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code === 0) {
            throw new Exception(__('TvOverlay ne répond pas', __FILE__) . ($error !== '' ? ' (' . $error . ')' : ''));
        }
        $reply = json_decode((string) $body, true);
        if ($code !== 200 || !is_array($reply) || empty($reply['success'])) {
            throw new Exception(__('TvOverlay refuse :', __FILE__) . ' ' . (is_array($reply) && isset($reply['message']) ? $reply['message'] : 'HTTP ' . $code));
        }
        return $reply;
    }

    /* Ouvre un lien, ou une appli par son nom de paquet : la TV n'accepte
     * pour cela que la fiche Play Store, dont le bouton « Ouvrir » est
     * sélectionné d'office. On appuie sur OK une fois la fiche affichée. */
    public function openTarget($_target) {
        $target = trim((string) $_target);
        $this->daemonOrder('link', $target);
        if (strpos($target, ':') === false && preg_match('/^[A-Za-z][\w]*(\.[A-Za-z_][\w]*)+$/', $target)) {
            sleep(4);
            $this->waitRemote(4);
            $this->daemonOrder('key', 'DPAD_CENTER');
        }
    }

    /* Relance l'appli par sa fiche Play Store (seule voie acceptée par la
     * TV sans ADB), puis deux « Retour » : l'un ferme l'écran de réglages
     * de TvOverlay, l'autre la fiche. L'appli d'avant revient, mais
     * Netflix, passé en arrière-plan, s'est mis en pause sur son écran
     * « Reprendre » : la touche Lecture relance le film (vérifié sur une
     * TCL). Sur un écran sans lecture, elle ne fait rien ; un film mis en
     * pause volontairement, lui, reprend. Une vingtaine de secondes en tout.
     *
     * $_port : celui du plugin TvOverlay quand c'est lui qui demande. */
    public function overlayRelaunch($_port = null) {
        $lock = 'googletvbe::relaunch::' . $this->getId();
        $last = (int) cache::byKey($lock)->getValue(0);
        if (time() - $last < self::RELAUNCH_LOCK) {
            /* Relance en cours ou toute récente : on attend son résultat
             * plutôt que d'en lancer une seconde. */
            while (time() - $last < 45) {
                try {
                    $this->overlayRequest('/get', null, 1, $_port);
                    return;
                } catch (Throwable $e) {
                    sleep(1);
                }
            }
            throw new Exception(__('TvOverlay a déjà été relancée il y a moins de deux minutes, sans succès : pas de nouvel essai tout de suite.', __FILE__));
        }
        cache::set($lock, time());
        $this->waitRemote();
        $this->setCache('overlay_relaunch_at', time());
        log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('relance de TvOverlay', __FILE__));
        $this->openTarget(self::OVERLAY_PACKAGE);
        /* Le temps que l'appli démarre : son API répond une fois lancée. */
        sleep(2);
        for ($i = 0; $i < 6; $i++) {
            try {
                $this->overlayRequest('/get', null, 1, $_port);
                break;
            } catch (Throwable $e) {
                sleep(1);
            }
        }
        $this->waitRemote(4);
        $this->daemonOrder('key', 'BACK');
        sleep(1);
        $this->daemonOrder('key', 'BACK');
        if ((int) $this->getConfiguration('overlay_resume', 1) === 1) {
            sleep(2);
            $this->daemonOrder('key', 'MEDIA_PLAY');
        }
    }

    /* Relance automatique permise maintenant ? */
    private function overlayMayRelaunch() {
        $mode = (int) $this->getConfiguration('overlay_watchdog', self::WATCHDOG_AFTER_POWER_ON);
        if ($mode === self::WATCHDOG_OFF) {
            return false;
        }
        if ($mode === self::WATCHDOG_AFTER_POWER_ON && time() - (int) $this->getCache('power_on_at', 0) > self::WATCHDOG_WINDOW) {
            return false;
        }
        return is_object($this->getCmd('info', 'remote'))
            && (int) $this->getCmd('info', 'remote')->execCmd() === 1
            && time() - (int) $this->getCache('overlay_relaunch_at', 0) > self::OVERLAY_RELAUNCH_EVERY;
    }

    /* La connexion de la télécommande se rouvre toute seule quand la TV la
     * coupe ; on lui laisse quelques secondes plutôt que d'échouer. */
    public function waitRemote($_seconds = 8) {
        $deadline = microtime(true) + $_seconds;
        $asked = false;
        while (true) {
            $status = $this->daemonOrder('status');
            if (!empty($status['remote'])) {
                return;
            }
            if (empty($status['paired'])) {
                throw new Exception(__('La TV n\'est pas appairée : utilisez « Appairer la télécommande ».', __FILE__));
            }
            if (microtime(true) >= $deadline) {
                throw new Exception(__('La télécommande ne joint pas la TV.', __FILE__));
            }
            if (!$asked) {
                $this->daemonOrder('reconnect');
                $asked = true;
            }
            usleep(500000);
        }
    }

    /* Surveillance de la minute : TV allumée, TvOverlay doit répondre. */
    public function overlayWatch() {
        $power = $this->getCmd('info', 'power');
        if (!is_object($power) || (int) $power->execCmd() !== 1) {
            return;
        }
        try {
            $this->overlayRequest('/get', null, 2);
            $this->checkAndUpdateCmd('overlay', 1);
            return;
        } catch (Throwable $e) {
            $this->checkAndUpdateCmd('overlay', 0);
        }
        if ($this->overlayMayRelaunch()) {
            /* Noté tout de suite : le cron suivant ne relance pas une
             * seconde fois pendant que celle-ci tourne. */
            $this->setCache('overlay_relaunch_at', time());
            exec('php ' . escapeshellarg(realpath(__DIR__ . '/../php/googletvbeRelaunch.php')) . ' ' . (int) $this->getId()
                . ' >> ' . escapeshellarg(log::getPathToLog(__CLASS__)) . ' 2>&1 &');
        }
    }

    /* La relance de la surveillance, en tâche de fond. */
    public function overlayRelaunchAndCheck() {
        $this->overlayRelaunch();
        try {
            $this->overlayRequest('/get', null, 2);
            $this->checkAndUpdateCmd('overlay', 1);
        } catch (Throwable $e) {
            log::add(__CLASS__, 'warning', $this->getHumanName() . ' : ' . __('TvOverlay ne répond toujours pas après relance', __FILE__));
        }
    }

    /* Notification de test du bouton : si TvOverlay est morte, la relance
     * d'abord, quel que soit le réglage de relance automatique (c'est une
     * demande explicite). */
    public function overlaySend($_path, $_body) {
        if (!$this->overlayEnabled()) {
            throw new Exception(__('TvOverlay n\'est pas activée sur cet équipement.', __FILE__));
        }
        try {
            $reply = $this->overlayRequest($_path, $_body);
            $this->checkAndUpdateCmd('overlay', 1);
            return $reply;
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), __('TvOverlay refuse :', __FILE__)) === 0) {
                throw $e;
            }
            $this->checkAndUpdateCmd('overlay', 0);
        }
        $this->overlayRelaunch();
        $reply = $this->overlayRequest($_path, $_body);
        $this->checkAndUpdateCmd('overlay', 1);
        return $reply;
    }

    /* ============================================================ WIDGET */

    /* Infos suivies en direct par la télécommande du widget, et commandes
     * qu'elle déclenche. */
    const WIDGET_INFOS = array('online', 'remote', 'power', 'volume', 'muted', 'app', 'cast_app',
                               'media_state', 'media_title', 'media_subtitle');
    const WIDGET_ACTIONS = array('refresh', 'power_on', 'power_off', 'input', 'settings', 'up', 'down', 'left', 'right',
                                 'ok', 'back', 'home', 'menu', 'previous', 'rewind', 'play_pause', 'forward', 'next',
                                 'volume_up', 'volume_down', 'mute_toggle', 'channel_up', 'channel_down', 'volume_set', 'open_app');

    /* Une télécommande plutôt que la pile des 58 commandes. */
    public function toHtml($_version = 'dashboard') {
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        $version = jeedom::versionAlias($_version);
        /* Une télécommande a sa taille à elle : la taille retenue par le
         * dashboard pour l'ancien widget (706 × 370 px, par exemple) la
         * couperait. */
        $replace['#width#'] = '270px';
        $replace['#height#'] = 'auto';
        $ids = array();
        $state = array();
        foreach (array_merge(self::WIDGET_INFOS, self::WIDGET_ACTIONS) as $logicalId) {
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                continue;
            }
            $ids[$logicalId] = (string) $cmd->getId();
            if ($cmd->getType() === 'info') {
                $value = $cmd->execCmd();
                $state[$logicalId] = ($value === null) ? '' : (string) $value;
            }
        }
        /* Les applis de la commande « Lancer une application », telles que
         * l'utilisateur a pu les modifier. */
        $apps = array();
        $openApp = $this->getCmd('action', 'open_app');
        $list = is_object($openApp) ? (string) $openApp->getConfiguration('listValue', '') : '';
        foreach (explode(';', $list) as $entry) {
            $parts = explode('|', $entry, 2);
            if (trim($parts[0]) !== '') {
                $apps[] = array('value' => trim($parts[0]), 'label' => trim(isset($parts[1]) ? $parts[1] : $parts[0]));
            }
        }
        $replace['#refresh_id#'] = isset($ids['refresh']) ? $ids['refresh'] : '';
        /* En attribut HTML, échappé : le script les relit sans rien évaluer. */
        $replace['#gtv_ids#'] = htmlspecialchars(json_encode($ids), ENT_QUOTES);
        $replace['#gtv_state#'] = htmlspecialchars(json_encode($state, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
        $replace['#gtv_apps#'] = htmlspecialchars(json_encode($apps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
        $template = getTemplate('core', $version, 'googletvbe', __CLASS__);
        $html = translate::exec($template, 'plugins/googletvbe/core/template/' . $version . '/googletvbe.html');
        return $this->postToHtml($_version, template_replace($replace, $html));
    }

    /* ========================================================= RÉVEIL RÉSEAU */

    /* Adresses MAC d'un champ libre : séparées par virgules, espaces ou
     * points-virgules, écrites avec « : », « - » ou sans séparateur. */
    public static function parseMacs($_text) {
        $macs = array();
        foreach (preg_split('/[\s,;]+/', strtolower((string) $_text)) as $part) {
            $hex = preg_replace('/[^0-9a-f]/', '', $part);
            if (strlen($hex) === 12) {
                $macs[] = implode(':', str_split($hex, 2));
            }
        }
        return array_values(array_unique($macs));
    }

    public static function magicPacket($_mac) {
        return str_repeat("\xff", 6) . str_repeat(hex2bin(str_replace(':', '', $_mac)), 16);
    }

    /* Envoie le paquet magique à chaque adresse MAC connue, en diffusion
     * générale et sur le /24 de la TV (certains routeurs ne relaient pas
     * la première), puis demande au démon de retenter aussitôt sa
     * connexion. */
    public function wake() {
        $macs = self::parseMacs($this->getConfiguration('mac', ''));
        if (count($macs) === 0) {
            throw new Exception(__('Aucune adresse MAC renseignée : le réveil par le réseau en a besoin.', __FILE__));
        }
        $targets = array('255.255.255.255');
        if (preg_match('/^(\d+\.\d+\.\d+)\.\d+$/', $this->getConfiguration('ip', ''), $m)) {
            $targets[] = $m[1] . '.255';
        }
        $context = stream_context_create(array('socket' => array('so_broadcast' => true)));
        $sent = 0;
        foreach ($targets as $target) {
            $socket = @stream_socket_client('udp://' . $target . ':9', $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $context);
            if ($socket === false) {
                log::add(__CLASS__, 'debug', 'WoL vers ' . $target . ' impossible : ' . $errstr);
                continue;
            }
            foreach ($macs as $mac) {
                /* Deux envois : un paquet UDP perdu ne se rattrape pas. */
                $sent += (int) (@fwrite($socket, self::magicPacket($mac)) > 0);
                @fwrite($socket, self::magicPacket($mac));
            }
            fclose($socket);
        }
        if ($sent === 0) {
            throw new Exception(__('Le paquet de réveil n\'a pas pu être envoyé.', __FILE__));
        }
        log::add(__CLASS__, 'info', $this->getHumanName() . ' : réveil réseau envoyé à ' . implode(', ', $macs));
        try {
            $this->daemonOrder('reconnect');
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    /* ========================================================= DÉCOUVERTE */

    private static function httpGet($_url, $_timeout = 3) {
        $ch = curl_init($_url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $_timeout,
            CURLOPT_TIMEOUT => $_timeout,
            CURLOPT_PROXY => '',
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code === 200 && is_string($body)) ? $body : null;
    }

    /* Adresse MAC d'une IP voisine, lue dans la table ARP du noyau : la
     * requête HTTP qui précède suffit à l'y faire entrer. */
    public static function arpMac($_ip) {
        foreach (@file('/proc/net/arp') ?: array() as $line) {
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) >= 4 && $cols[0] === $_ip && $cols[3] !== '00:00:00:00:00:00'
                && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $cols[3])) {
                return strtolower($cols[3]);
            }
        }
        return '';
    }

    /* Le certificat de la télécommande Android TV (port 6467) porte le
     * modèle : « atvremote/<produit>/<plateforme>/<modèle>/<mac> ». */
    public static function remoteCertificate($_ip) {
        $context = stream_context_create(array('ssl' => array(
            'verify_peer' => false, 'verify_peer_name' => false,
            'allow_self_signed' => true, 'capture_peer_cert' => true,
        )));
        $socket = @stream_socket_client('ssl://' . $_ip . ':6467', $errno, $errstr, 3, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return null;
        }
        $params = stream_context_get_params($socket);
        fclose($socket);
        if (!isset($params['options']['ssl']['peer_certificate'])) {
            return null;
        }
        $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        $cn = isset($cert['subject']['CN']) ? (string) $cert['subject']['CN'] : '';
        $parts = explode('/', $cn);
        return array('cn' => $cn, 'product' => isset($parts[1]) ? $parts[1] : '',
                     'model' => count($parts) >= 2 ? $parts[count($parts) - 2] : '');
    }

    /* Ce qui répond à une adresse. Lève une exception si ce n'est pas un
     * appareil Cast. */
    public static function probe($_ip) {
        $ip = trim((string) $_ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new Exception(__('Adresse IP invalide :', __FILE__) . ' ' . $_ip);
        }
        $info = json_decode((string) self::httpGet('http://' . $ip . ':8008/setup/eureka_info?params=name,build_info,device_info'), true);
        if (!is_array($info)) {
            throw new Exception(__('Aucun appareil Google Cast ne répond à cette adresse (port 8008).', __FILE__));
        }
        $device = array(
            'ip' => $ip,
            'name' => isset($info['name']) ? (string) $info['name'] : $ip,
            'udn' => isset($info['ssdp_udn']) ? (string) $info['ssdp_udn'] : '',
            'cast_version' => isset($info['cast_build_revision']) ? (string) $info['cast_build_revision'] : '',
            'manufacturer' => '',
            'model' => '',
            'product' => '',
            'remote' => false,
            'mac' => '',
        );
        $xml = self::httpGet('http://' . $ip . ':8008/ssdp/device-desc.xml');
        if ($xml !== null) {
            if (preg_match('#<manufacturer>([^<]*)</manufacturer>#', $xml, $m)) {
                $device['manufacturer'] = html_entity_decode($m[1]);
            }
            if (preg_match('#<modelName>([^<]*)</modelName>#', $xml, $m)) {
                $device['model'] = html_entity_decode($m[1]);
            }
        }
        $cert = self::remoteCertificate($ip);
        if ($cert !== null) {
            $device['remote'] = true;
            $device['product'] = $cert['product'];
            if ($device['model'] === '' && $cert['model'] !== '') {
                $device['model'] = $cert['model'];
            }
        }
        $overlay = json_decode((string) self::httpGet('http://' . $ip . ':' . self::OVERLAY_PORT . '/get', 2), true);
        $device['overlay'] = is_array($overlay) && !empty($overlay['success']);
        $device['mac'] = self::arpMac($ip);
        return $device;
    }

    /* Recherche SSDP des récepteurs DIAL (toutes les TV Cast en sont), puis
     * identification de chacun. */
    public static function discover() {
        if (!function_exists('socket_create')) {
            throw new Exception(__('L\'extension PHP sockets est absente : ajoutez la TV par son adresse IP.', __FILE__));
        }
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, 2);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 0, 'usec' => 300000));
        $search = "M-SEARCH * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\nMAN: \"ssdp:discover\"\r\nMX: 2\r\n"
                . "ST: urn:dial-multiscreen-org:service:dial:1\r\n\r\n";
        $ips = array();
        $end = microtime(true) + 3;
        $next = 0;
        while (microtime(true) < $end) {
            /* Trois envois espacés : un paquet multicast se perd facilement
             * en Wi-Fi. */
            if (microtime(true) >= $next) {
                @socket_sendto($socket, $search, strlen($search), 0, '239.255.255.250', 1900);
                $next = microtime(true) + 1;
            }
            $from = '';
            $port = 0;
            if (@socket_recvfrom($socket, $buffer, 4096, 0, $from, $port) > 0) {
                $ips[$from] = true;
            }
        }
        socket_close($socket);

        $found = array();
        foreach (array_keys($ips) as $ip) {
            try {
                $device = self::probe($ip);
            } catch (Throwable $e) {
                continue;
            }
            $existing = self::byDevice($device);
            $device['known'] = is_object($existing) ? $existing->getHumanName() : '';
            $found[] = $device;
        }
        usort($found, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        return $found;
    }

    public static function byDevice($_device) {
        if ($_device['udn'] !== '') {
            $eqLogic = self::byLogicalId($_device['udn'], __CLASS__);
            if (is_object($eqLogic)) {
                return $eqLogic;
            }
        }
        foreach (self::byType(__CLASS__) as $eqLogic) {
            if ($eqLogic->getConfiguration('ip') === $_device['ip']) {
                return $eqLogic;
            }
        }
        return null;
    }

    /* Crée l'équipement, ou met à jour celui qui existe déjà. */
    public static function createFromProbe($_device) {
        $eqLogic = self::byDevice($_device);
        if (!is_object($eqLogic)) {
            $eqLogic = new googletvbe();
            $eqLogic->setEqType_name(__CLASS__);
            $name = $_device['name'];
            /* Deux TV du même nom (« Téléviseur du salon ») : la seconde
             * est numérotée, pour qu'on les distingue dans les listes. */
            $names = array();
            foreach (self::byType(__CLASS__) as $other) {
                $names[$other->getName()] = true;
            }
            for ($i = 2; isset($names[$name]); $i++) {
                $name = $_device['name'] . ' ' . $i;
            }
            $eqLogic->setName($name);
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
            $eqLogic->setCategory('multimedia', 1);
        }
        $eqLogic->applyProbe($_device);
        $eqLogic->save();
        return $eqLogic;
    }

    public function applyProbe($_device) {
        if ($_device['udn'] !== '') {
            $this->setLogicalId($_device['udn']);
        }
        $this->setConfiguration('ip', $_device['ip']);
        foreach (array('manufacturer', 'model', 'product', 'cast_version') as $key) {
            if ($_device[$key] !== '') {
                $this->setConfiguration($key, $_device[$key]);
            }
        }
        /* TvOverlay trouvée : activée. Absente à cet instant, on ne
         * désactive rien : Android a pu simplement l'arrêter. */
        if (!empty($_device['overlay'])) {
            $this->setConfiguration('overlay', 1);
        }
        /* L'adresse MAC relevée s'ajoute à celles déjà saisies (une TV en a
         * une par interface : Wi-Fi, Ethernet). */
        if ($_device['mac'] !== '') {
            $macs = self::parseMacs($this->getConfiguration('mac', ''));
            if (!in_array($_device['mac'], $macs, true)) {
                $macs[] = $_device['mac'];
            }
            $this->setConfiguration('mac', implode(', ', $macs));
        }
    }

    /* Pour la page : configuration et dernier état brut, sans interroger la
     * TV. */
    public function toAjax() {
        $daemon = null;
        try {
            $daemon = $this->daemonOrder('status');
        } catch (Throwable $e) {
            $daemon = array('error' => $e->getMessage());
        }
        return array(
            'id' => $this->getId(),
            'ip' => $this->getConfiguration('ip'),
            'mac' => $this->getConfiguration('mac'),
            'manufacturer' => $this->getConfiguration('manufacturer'),
            'model' => $this->getConfiguration('model'),
            'product' => $this->getConfiguration('product'),
            'cast_version' => $this->getConfiguration('cast_version'),
            'udn' => $this->getLogicalId(),
            'paired' => (int) $this->getConfiguration('paired', 0),
            'daemon' => $daemon,
            'raw' => $this->getCache('raw', null),
        );
    }
}

class googletvbeCmd extends cmd {

    public function execute($_options = array()) {
        if ($this->getType() !== 'action') {
            return;
        }
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            return;
        }
        $eqLogic->runAction($this->getLogicalId(), $_options);
    }
}
