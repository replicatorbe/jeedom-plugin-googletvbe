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
 * Point d'entrée du démon, protégé par la clé API du plugin :
 *   GET ?apikey=…&action=devices   → les TV à suivre, en JSON
 *   POST ?apikey=…&action=push {"pushes":{ID:{logicalId:valeur,…}}}
 *                                   → valeurs changées, poussées par la TV
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

if (!jeedom::apiAccess(init('apikey'), 'googletvbe')) {
    http_response_code(401);
    echo 'Not authorized';
    die();
}

if (init('action') == 'devices') {
    /* Preuve de vie : un démon qui ne joint plus Jeedom est déclaré arrêté
     * (googletvbe::deamon_info), puis relancé. */
    cache::set('googletvbe::daemon_seen', time());
    header('Content-Type: application/json');
    echo json_encode(googletvbe::daemonDevices());
    die();
}

if (init('action') == 'push') {
    $payload = json_decode(file_get_contents('php://input'), true);
    foreach ((is_array($payload) && isset($payload['pushes']) && is_array($payload['pushes'])) ? $payload['pushes'] : array() as $id => $values) {
        $eqLogic = eqLogic::byId((int) $id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'googletvbe' || !$eqLogic->getIsEnable()) {
            continue;
        }
        try {
            $eqLogic->ingestPush($values);
        } catch (Throwable $e) {
            log::add('googletvbe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
    echo 'OK';
    die();
}

http_response_code(400);
echo 'Unknown action';
