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
 * Connexion TLS persistante vers la TV, sans jamais bloquer le démon.
 *
 * Le démon sert plusieurs connexions dans une seule boucle stream_select :
 * une TV éteinte, dont le réseau ne répond plus, ne doit pas figer les
 * autres ni le canal des ordres. Tout est donc asynchrone :
 *
 *   idle ──open()──▶ connecting ──TCP établi──▶ handshake ──TLS──▶ ready
 *     ▲                                                              │
 *     └─────────── fail() : fermeture, nouvel essai après attente ◀──┘
 *
 * L'attente entre deux essais double à chaque échec, de 1 à 30 secondes, et
 * revient à 1 dès qu'une connexion aboutit. Les écritures passent par un
 * tampon : une socket non bloquante peut n'en accepter qu'une partie.
 *
 * La TV présente un certificat auto-signé : il n'est pas vérifié. Les
 * sous-classes découpent les messages (onData) et entretiennent la
 * connexion (onTick).
 */
abstract class googletvbeLink {

    const CONNECT_TIMEOUT = 8;
    const MAX_BACKOFF = 30;

    public $label;
    protected $host;
    protected $port;
    protected $ssl;
    protected $stream = null;
    protected $state = 'idle';
    protected $since = 0.0;
    protected $retryAt = 0.0;
    protected $backoff = 1;
    protected $in = '';
    protected $out = '';
    protected $lastRx = 0.0;
    /* Dernière raison d'échec, pour ne pas répéter le même avertissement à
     * chaque essai d'une TV éteinte. */
    protected $lastError = '';

    /* Silence au-delà duquel la connexion est tenue pour morte. */
    protected $idleTimeout = 20;

    public function __construct($_label, $_host, $_port, $_ssl = array()) {
        $this->label = $_label;
        $this->host = $_host;
        $this->port = (int) $_port;
        $this->ssl = array_merge(array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ), $_ssl);
    }

    abstract protected function onReady();
    abstract protected function onData();
    abstract protected function onDown($_reason);
    protected function onTick($_now) {
    }

    public function isReady() {
        return $this->state === 'ready';
    }

    public function host() {
        return $this->host;
    }

    /* Les flux à surveiller dans stream_select. */
    public function watch(&$_read, &$_write) {
        if ($this->stream === null) {
            return;
        }
        if ($this->state === 'connecting') {
            /* La fin d'une connexion TCP asynchrone se lit en écriture. */
            $_write[] = $this->stream;
            return;
        }
        $_read[] = $this->stream;
        if ($this->state === 'ready' && $this->out !== '') {
            $_write[] = $this->stream;
        }
    }

    public function owns($_stream) {
        return $this->stream !== null && $_stream === $this->stream;
    }

    public function tick($_now) {
        switch ($this->state) {
            case 'idle':
                if ($_now >= $this->retryAt) {
                    $this->open($_now);
                }
                return;
            case 'connecting':
            case 'handshake':
                if ($_now - $this->since > self::CONNECT_TIMEOUT) {
                    $this->fail('pas de réponse');
                } elseif ($this->state === 'handshake') {
                    $this->handshake();
                }
                return;
            case 'ready':
                if ($_now - $this->lastRx > $this->idleTimeout) {
                    $this->fail('plus de nouvelles depuis ' . $this->idleTimeout . ' s');
                    return;
                }
                $this->onTick($_now);
                return;
        }
    }

    /* Appelée quand stream_select signale ce flux. */
    public function handle($_readable, $_writable) {
        if ($this->state === 'connecting') {
            /* Connexion refusée ou injoignable : l'adresse distante reste
             * vide. */
            if (@stream_socket_get_name($this->stream, true) === false) {
                $this->fail('connexion refusée');
                return;
            }
            $this->state = 'handshake';
            $this->handshake();
            return;
        }
        if ($this->state === 'handshake') {
            $this->handshake();
            return;
        }
        if ($this->state !== 'ready') {
            return;
        }
        if ($_writable) {
            $this->flush();
        }
        if ($_readable && $this->stream !== null) {
            $this->receive();
        }
    }

    public function send($_bytes) {
        if ($this->state !== 'ready') {
            throw new Exception('TV non connectée (' . $this->label . ')');
        }
        $this->out .= $_bytes;
        $this->flush();
    }

    public function close() {
        if ($this->stream !== null) {
            @fclose($this->stream);
        }
        $this->stream = null;
        $this->state = 'idle';
        $this->in = '';
        $this->out = '';
    }

    /* Nouvelle tentative immédiate, par exemple après un réveil par le
     * réseau : inutile d'attendre la fin de l'attente en cours. */
    public function retryNow() {
        if ($this->state === 'idle') {
            $this->retryAt = 0.0;
            $this->backoff = 1;
        }
    }

    protected function open($_now) {
        $this->since = $_now;
        $context = stream_context_create(array('ssl' => $this->ssl));
        $stream = @stream_socket_client('tcp://' . $this->host . ':' . $this->port, $errno, $errstr, self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $context);
        if ($stream === false) {
            $this->fail($errstr !== '' ? $errstr : 'connexion impossible');
            return;
        }
        stream_set_blocking($stream, false);
        $this->stream = $stream;
        $this->state = 'connecting';
    }

    protected function handshake() {
        $result = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($result === 0) {
            return;
        }
        if ($result !== true) {
            $error = error_get_last();
            $this->fail('échec TLS' . ($error ? ' : ' . $error['message'] : ''));
            return;
        }
        $this->state = 'ready';
        $this->backoff = 1;
        $this->lastRx = googletvbeClock();
        if ($this->lastError !== '') {
            googletvbeLog('info', $this->label . ' : connexion rétablie');
        } else {
            googletvbeLog('debug', $this->label . ' : connecté');
        }
        $this->lastError = '';
        $this->onReady();
    }

    protected function receive() {
        while ($this->stream !== null) {
            $chunk = @fread($this->stream, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $this->in .= $chunk;
            $this->lastRx = googletvbeClock();
        }
        if ($this->stream !== null && feof($this->stream)) {
            if ($this->in !== '') {
                $this->dispatch();
            }
            $this->fail('connexion fermée par la TV');
            return;
        }
        $this->dispatch();
    }

    private function dispatch() {
        try {
            $this->onData();
        } catch (Throwable $e) {
            /* Un message illisible fait perdre le fil du flux : on repart
             * d'une connexion neuve plutôt que de lire de travers. */
            $this->fail('flux illisible : ' . $e->getMessage());
        }
    }

    protected function flush() {
        while ($this->out !== '' && $this->stream !== null) {
            $written = @fwrite($this->stream, $this->out);
            if ($written === false || $written === 0) {
                return;
            }
            $this->out = (string) substr($this->out, $written);
        }
    }

    protected function fail($_reason) {
        $wasReady = $this->state === 'ready';
        $this->close();
        $this->retryAt = googletvbeClock() + $this->backoff;
        $this->backoff = min($this->backoff * 2, self::MAX_BACKOFF);
        if ($wasReady || $_reason !== $this->lastError) {
            googletvbeLog($wasReady ? 'warning' : 'debug', $this->label . ' : ' . $_reason);
        }
        $this->lastError = $_reason;
        $this->onDown($_reason);
    }
}
