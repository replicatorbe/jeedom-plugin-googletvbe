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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();

    function googletvbeEq() {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'googletvbe') {
            throw new Exception(__('Équipement introuvable :', __FILE__) . ' ' . init('id'));
        }
        return $eqLogic;
    }

    /* Recherche SSDP sur le réseau local, sans rien créer. */
    if (init('action') == 'discover') {
        unautorizedInDemo();
        ajax::success(googletvbe::discover());
    }

    /* Ce qui répond à une adresse donnée, sans rien créer. */
    if (init('action') == 'probe') {
        unautorizedInDemo();
        $device = googletvbe::probe(init('ip'));
        $existing = googletvbe::byDevice($device);
        $device['known'] = is_object($existing) ? $existing->getHumanName() : '';
        ajax::success(array($device));
    }

    /* Crée les TV retenues, ou met leur adresse à jour. Chacune est
     * réinterrogée : on ne croit pas le navigateur sur parole. */
    if (init('action') == 'create') {
        unautorizedInDemo();
        $ips = json_decode(init('ips'), true);
        if (!is_array($ips) || count($ips) === 0) {
            throw new Exception(__('Aucune TV à créer.', __FILE__));
        }
        $created = array();
        $errors = array();
        foreach ($ips as $ip) {
            try {
                $created[] = googletvbe::createFromProbe(googletvbe::probe($ip))->getId();
            } catch (Throwable $e) {
                $errors[] = $ip . ' : ' . $e->getMessage();
            }
        }
        ajax::success(array('created' => $created, 'errors' => $errors));
    }

    /* Relit modèle, version et adresse MAC sur la TV. */
    if (init('action') == 'reprobe') {
        unautorizedInDemo();
        $eqLogic = googletvbeEq();
        $eqLogic->applyProbe(googletvbe::probe($eqLogic->getConfiguration('ip')));
        $eqLogic->save();
        ajax::success($eqLogic->toAjax());
    }

    if (init('action') == 'wake') {
        unautorizedInDemo();
        googletvbeEq()->wake();
        ajax::success();
    }

    /* Appairage de la télécommande : la TV affiche un code, que
     * l'utilisateur recopie. */
    if (init('action') == 'pairStart') {
        unautorizedInDemo();
        googletvbeEq()->pairStart();
        ajax::success();
    }

    if (init('action') == 'pairFinish') {
        unautorizedInDemo();
        googletvbeEq()->pairFinish(init('code'));
        ajax::success();
    }

    if (init('action') == 'pairCancel') {
        unautorizedInDemo();
        googletvbeEq()->pairCancel();
        ajax::success();
    }

    if (init('action') == 'unpair') {
        unautorizedInDemo();
        googletvbeEq()->unpair();
        ajax::success();
    }

    if (init('action') == 'overlayTest') {
        unautorizedInDemo();
        googletvbeEq()->overlaySend('/notify', array(
            'title' => 'Jeedom', 'message' => __('Notification de test', __FILE__),
            'smallIcon' => 'mdi:home-automation', 'duration' => 8,
        ));
        ajax::success();
    }

    if (init('action') == 'overlayRestart') {
        unautorizedInDemo();
        googletvbeEq()->overlayRelaunch();
        ajax::success();
    }

    if (init('action') == 'data') {
        ajax::success(googletvbeEq()->toAjax());
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

/* Throwable : en PHP 8 une Error n'hérite pas d'Exception. */
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
