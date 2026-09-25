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
 * Protobuf réduit au strict nécessaire, écrit à la main.
 *
 * Les protocoles de la TV (Cast, télécommande Android TV) n'emploient que
 * deux types de champ : l'entier « varint » (type 0) et la suite d'octets
 * préfixée de sa longueur (type 2 : texte, octets, sous-message). Pas de
 * .proto compilé, pas d'extension : le décodage rend, pour chaque numéro de
 * champ, la liste des valeurs rencontrées ; c'est à l'appelant de savoir
 * qu'un champ 2 est un sous-message à redécoder.
 */
class googletvbeProto {

    public static function varint($_value) {
        $value = (int) $_value;
        $out = '';
        do {
            $byte = $value & 0x7f;
            /* Décalage logique : un entier négatif ne doit pas boucler. */
            $value = ($value >> 7) & (PHP_INT_MAX >> 6);
            $out .= chr($byte | ($value ? 0x80 : 0));
        } while ($value);
        return $out;
    }

    /* Lit un varint à la position donnée et avance la position. Rend null si
     * le tampon s'arrête au milieu : l'appelant attend la suite. */
    public static function readVarint($_buffer, &$_pos) {
        $value = 0;
        $shift = 0;
        $length = strlen($_buffer);
        while ($_pos < $length) {
            $byte = ord($_buffer[$_pos++]);
            $value |= ($byte & 0x7f) << $shift;
            if (($byte & 0x80) === 0) {
                return $value;
            }
            $shift += 7;
            if ($shift > 63) {
                throw new Exception('varint trop long');
            }
        }
        return null;
    }

    public static function int($_field, $_value) {
        return self::varint($_field << 3) . self::varint($_value);
    }

    public static function bytes($_field, $_value) {
        $value = (string) $_value;
        return self::varint(($_field << 3) | 2) . self::varint(strlen($value)) . $value;
    }

    /* array(numéro => array(valeurs…)). Les types 1 et 5 (64 et 32 bits
     * fixes) sont lus et rendus bruts, pour ne pas perdre le fil d'un message
     * qui en contiendrait. */
    public static function decode($_message) {
        $fields = array();
        $pos = 0;
        $length = strlen($_message);
        while ($pos < $length) {
            $key = self::readVarint($_message, $pos);
            if ($key === null) {
                throw new Exception('message protobuf tronqué');
            }
            $field = $key >> 3;
            switch ($key & 7) {
                case 0:
                    $value = self::readVarint($_message, $pos);
                    if ($value === null) {
                        throw new Exception('message protobuf tronqué');
                    }
                    break;
                case 2:
                    $size = self::readVarint($_message, $pos);
                    if ($size === null || $pos + $size > $length) {
                        throw new Exception('message protobuf tronqué');
                    }
                    $value = (string) substr($_message, $pos, $size);
                    $pos += $size;
                    break;
                case 1:
                case 5:
                    $size = ($key & 7) === 1 ? 8 : 4;
                    if ($pos + $size > $length) {
                        throw new Exception('message protobuf tronqué');
                    }
                    $value = (string) substr($_message, $pos, $size);
                    $pos += $size;
                    break;
                default:
                    throw new Exception('type de champ protobuf inconnu : ' . ($key & 7));
            }
            $fields[$field][] = $value;
        }
        return $fields;
    }

    /* Première valeur d'un champ décodé, ou la valeur par défaut. */
    public static function first($_fields, $_field, $_default = null) {
        return isset($_fields[$_field][0]) ? $_fields[$_field][0] : $_default;
    }
}
