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

require_once __DIR__ . '/../../../core/php/core.inc.php';

function googletvbe_install() {
    /* Le point d'entrée du démon ne parle qu'à un processus local. */
    config::save('api::googletvbe::mode', 'localhost', 'core');
}

/* Exécutée dans la requête HTTP de la page des plugins : rien de lent ici,
 * aucune interrogation de TV. On ne fait que rattraper les commandes
 * ajoutées par la nouvelle version. */
function googletvbe_update() {
    /* Une TV en erreur n'empêche pas la mise à jour des autres. */
    foreach (eqLogic::byType('googletvbe') as $eqLogic) {
        try {
            $eqLogic->createCommands();
        } catch (Throwable $e) {
            log::add('googletvbe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}

/* Appelée aussi à la simple désactivation du plugin : ne rien y détruire. */
function googletvbe_remove() {
    try {
        googletvbe::deamon_stop();
    } catch (Throwable $e) {
        log::add('googletvbe', 'error', __('Arrêt du démon :', __FILE__) . ' ' . $e->getMessage());
    }
}
