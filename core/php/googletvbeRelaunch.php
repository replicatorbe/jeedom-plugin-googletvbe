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
 * Relance de TvOverlay décidée par la surveillance de la minute
 * (googletvbe::overlayWatch), exécutée à part : elle dure une vingtaine de
 * secondes, que le cron de Jeedom, commun à tous les plugins, n'a pas à
 * attendre.
 *
 *   php googletvbeRelaunch.php ID_EQUIPEMENT
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die();
}

require_once __DIR__ . '/../../../../core/php/core.inc.php';

$eqLogic = eqLogic::byId(isset($argv[1]) ? (int) $argv[1] : 0);
if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'googletvbe' || !$eqLogic->getIsEnable()) {
    exit(0);
}
try {
    $eqLogic->overlayRelaunchAndCheck();
} catch (Throwable $e) {
    log::add('googletvbe', 'warning', $eqLogic->getHumanName() . ' : ' . __('relance de TvOverlay :', __FILE__) . ' ' . $e->getMessage());
}
