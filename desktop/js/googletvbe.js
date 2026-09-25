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

/* ================================================================== OUTILS */

function googletvbeEl(_id) {
  return document.getElementById(_id)
}

/* Ce qui vient d'une TV ou du serveur est du texte, jamais du balisage. */
function googletvbeEscape(_text) {
  var div = document.createElement('div')
  div.textContent = (_text === null || _text === undefined) ? '' : String(_text)
  return div.innerHTML
}

/* Les fenêtres du coeur : jeeDialog depuis Jeedom 4.4, bootbox sinon (il
   n'est chargé qu'avec jQuery). */
function googletvbeConfirm(_title, _message, _callback) {
  if (typeof jeeDialog !== 'undefined') {
    jeeDialog.confirm({ title: _title, message: _message, callback: function (_ok) { if (_ok) { _callback() } } })
  } else {
    bootbox.confirm({ title: _title, message: _message, callback: function (_ok) { if (_ok) { _callback() } } })
  }
}

function googletvbePrompt(_title, _value, _placeholder, _callback, _onCancel) {
  var options = {
    title: _title, value: _value, placeholder: _placeholder,
    callback: function (_v) {
      if (_v !== null) { _callback(_v) } else if (typeof _onCancel === 'function') { _onCancel() }
    }
  }
  if (typeof jeeDialog !== 'undefined') {
    jeeDialog.prompt(options)
  } else {
    bootbox.prompt(options)
  }
}

function googletvbeAlert(_title, _message, _callback) {
  if (typeof jeeDialog !== 'undefined') {
    jeeDialog.alert({ title: _title, message: _message, callback: _callback })
  } else {
    bootbox.alert({ title: _title, message: _message, callback: _callback })
  }
}

function googletvbeAjax(_action, _data, _success, _error) {
  var payload = { action: _action }
  for (var key in _data) {
    if (Object.prototype.hasOwnProperty.call(_data, key)) { payload[key] = _data[key] }
  }
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/googletvbe/core/ajax/googletvbe.ajax.php',
    data: payload,
    dataType: 'json',
    global: false,
    error: function (error) {
      if (typeof _error === 'function') { _error(error); return }
      domUtils.handleAjaxError(error)
    },
    success: function (result) {
      if (result.state !== 'ok') {
        if (typeof _error === 'function') { _error(result); return }
        jeedomUtils.showAlert({ message: googletvbeEscape(result.result), level: 'danger' })
        return
      }
      _success(result)
    }
  })
}

function googletvbeFailed(_error, _default) {
  domUtils.hideLoading()
  jeedomUtils.showAlert({ message: googletvbeEscape((_error && _error.result) ? _error.result : _default), level: 'danger' })
}

function googletvbeCurrentId() {
  var id = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (id === null) ? '' : id.value
}

function googletvbeReload(_id) {
  jeedomUtils.loadPage('index.php?v=d&m=googletvbe&p=googletvbe' + (_id ? '&id=' + _id : ''))
}

function googletvbeText(_id, _text) {
  var el = googletvbeEl(_id)
  if (el !== null) { el.textContent = (_text === null || _text === undefined) ? '' : String(_text) }
}

/* ============================================================== DÉCOUVERTE */

function googletvbeDiscover() {
  /* La recherche dure quelques secondes : un voile d'attente plutôt qu'un
     message qui disparaîtrait avant la fin. */
  domUtils.showLoading()
  googletvbeAjax('discover', {}, function (result) {
    domUtils.hideLoading()
    googletvbeShowFound(result.result)
  }, function (error) {
    googletvbeFailed(error, '{{Échec de la recherche}}')
  })
}

function googletvbeAddIp() {
  googletvbePrompt('{{Adresse IP de la TV}}', '', '192.168.0.106', function (_ip) {
    var ip = String(_ip).trim()
    if (ip === '') { return }
    domUtils.showLoading()
    googletvbeAjax('probe', { ip: ip }, function (result) {
      domUtils.hideLoading()
      googletvbeShowFound(result.result)
    }, function (error) {
      googletvbeFailed(error, '{{Aucune TV ne répond à cette adresse}}')
    })
  })
}

function googletvbeShowFound(_devices) {
  var devices = Array.isArray(_devices) ? _devices : []
  if (devices.length === 0) {
    jeedomUtils.showAlert({ message: '{{Aucune TV n\'a répondu. Vérifiez qu\'elle est sur le même réseau que Jeedom, ou ajoutez-la par son adresse IP.}}', level: 'warning' })
    return
  }
  var html = '<p>{{Cochez les TV à créer. Celles qui existent déjà verront seulement leur adresse mise à jour.}}</p>'
  /* Les choix sont retenus à chaque clic : jeeDialog retire la fenêtre du
     document avant d'appeler le rappel, les cases n'y sont plus lisibles. */
  window.googletvbeChosen = {}
  for (var i = 0; i < devices.length; i++) {
    var d = devices[i]
    window.googletvbeChosen[i] = !d.known
    html += '<div class="checkbox"><label>'
    html += '<input type="checkbox" class="googletvbeFound" data-index="' + i + '"' + (d.known ? '' : ' checked') + '> '
    html += '<b>' + googletvbeEscape(d.name) + '</b> — ' + googletvbeEscape(d.ip)
    html += ' <small>' + googletvbeEscape([d.manufacturer, d.model, d.product].filter(function (_v) { return _v }).join(' · ')) + '</small>'
    if (!d.remote) {
      html += ' <span class="label label-warning" title="{{Port 6467 fermé : sans doute un Chromecast ou une enceinte, pas une TV Android.}}">{{Cast seul}}</span>'
    }
    if (d.known) {
      html += ' <span class="label label-default">{{déjà créée :}} ' + googletvbeEscape(d.known) + '</span>'
    }
    html += '</label></div>'
  }
  googletvbeConfirm('{{TV trouvées}}', html, function () {
    var chosen = []
    for (var index in window.googletvbeChosen) {
      if (window.googletvbeChosen[index]) { chosen.push(devices[parseInt(index, 10)].ip) }
    }
    if (chosen.length === 0) {
      jeedomUtils.showAlert({ message: '{{Aucune TV cochée : rien n\'a été créé.}}', level: 'warning' })
      return
    }
    domUtils.showLoading()
    googletvbeAjax('create', { ips: JSON.stringify(chosen) }, function (result) {
      domUtils.hideLoading()
      var r = result.result
      if (r.errors && r.errors.length > 0) {
        var list = r.errors.map(function (_e) { return '<li>' + googletvbeEscape(_e) + '</li>' }).join('')
        googletvbeAlert('{{Création incomplète}}', '<p>' + r.created.length + ' {{TV créée(s). Échecs :}}</p><ul>' + list + '</ul>', function () { googletvbeReload() })
        return
      }
      googletvbeReload(r.created.length === 1 ? r.created[0] : '')
    }, function (error) {
      googletvbeFailed(error, '{{Échec de la création}}')
    })
  })
}

/* =============================================================== ÉQUIPEMENT */

function googletvbeRender(_data) {
  googletvbeText('span_googletvbeModel', [_data.manufacturer, _data.model].filter(function (_v) { return _v }).join(' '))
  googletvbeText('span_googletvbeProduct', _data.product)
  var product = googletvbeEl('span_googletvbeProduct')
  if (product !== null) { product.style.display = _data.product ? '' : 'none' }
  googletvbeText('span_googletvbeCast', _data.cast_version)

  var link = googletvbeEl('span_googletvbeLink')
  if (link !== null) {
    var daemon = _data.daemon || {}
    if (_data.loading) {
      link.textContent = '…'
      link.className = 'label label-default'
    } else if (daemon.error) {
      link.textContent = daemon.error
      link.className = 'label label-warning'
    } else if (daemon.cast) {
      link.textContent = '{{Cast connecté}}'
      link.className = 'label label-success'
    } else {
      link.textContent = '{{TV injoignable}}'
      link.className = 'label label-danger'
    }
  }
  var remote = googletvbeEl('span_googletvbeRemote')
  if (remote !== null) {
    var d = _data.daemon || {}
    if (_data.loading) {
      remote.textContent = '…'
      remote.className = 'label label-default'
    } else if (!_data.paired) {
      remote.textContent = '{{Non appairée}}'
      remote.className = 'label label-warning'
    } else if (d.remote) {
      remote.textContent = '{{Appairée, connectée}}'
      remote.className = 'label label-success'
    } else if (d.remote_rejected) {
      remote.textContent = '{{La TV refuse la connexion : appairez-la de nouveau}}'
      remote.className = 'label label-danger'
    } else {
      remote.textContent = '{{Appairée, TV injoignable}}'
      remote.className = 'label label-default'
    }
  }
  var unpair = googletvbeEl('bt_googletvbeUnpair')
  if (unpair !== null) { unpair.style.display = _data.paired ? '' : 'none' }

  var raw = googletvbeEl('pre_googletvbeRaw')
  if (raw !== null) {
    raw.textContent = _data.raw ? JSON.stringify(_data.raw, null, 2) : '{{Rien reçu pour le moment.}}'
  }
}

function printEqLogic(_eqLogic) {
  googletvbeRender({ loading: true })
  if (isset(_eqLogic.id) && _eqLogic.id !== '') {
    var id = String(_eqLogic.id)
    googletvbeAjax('data', { id: id }, function (result) {
      /* Réponse tardive d'un équipement ouvert avant celui-ci : ignorée. */
      if (String(result.result.id) !== String(googletvbeCurrentId())) { return }
      googletvbeRender(result.result)
    })
  }
}

function googletvbeReprobe() {
  var id = googletvbeCurrentId()
  if (id === '') { return }
  domUtils.showLoading()
  googletvbeAjax('reprobe', { id: id }, function (result) {
    domUtils.hideLoading()
    googletvbeReload(id)
  }, function (error) {
    googletvbeFailed(error, '{{La TV ne répond pas}}')
  })
}

function googletvbeWake() {
  var id = googletvbeCurrentId()
  if (id === '') { return }
  googletvbeAjax('wake', { id: id }, function () {
    jeedomUtils.showAlert({ message: '{{Paquet de réveil envoyé.}}', level: 'success' })
  })
}

/* ================================================================ APPAIRAGE */

function googletvbePair() {
  var id = googletvbeCurrentId()
  if (id === '') { return }
  /* L'ouverture de l'appairage prend quelques secondes, le temps que la TV
     affiche son code. */
  domUtils.showLoading()
  googletvbeAjax('pairStart', { id: id }, function () {
    domUtils.hideLoading()
    googletvbeAskCode(id, '')
  }, function (error) {
    googletvbeFailed(error, '{{La TV refuse l\'appairage}}')
  })
}

function googletvbeAskCode(_id, _previous) {
  var title = '{{Code affiché sur la TV (six caractères)}}'
  if (_previous !== '') { title = '{{Code incorrect, recopiez-le à nouveau}}' }
  /* Annuler, ou valider un code vide, abandonne cet appairage-ci ; un
     appairage déjà fait avant reste en place. */
  var cancel = function () {
    googletvbeAjax('pairCancel', { id: _id }, function () {
      jeedomUtils.showAlert({ message: '{{Appairage abandonné.}}', level: 'warning' })
    })
  }
  googletvbePrompt(title, _previous, 'A1B2C3', function (_code) {
    var code = String(_code).trim()
    if (code === '') {
      cancel()
      return
    }
    domUtils.showLoading()
    googletvbeAjax('pairFinish', { id: _id, code: code }, function () {
      domUtils.hideLoading()
      jeedomUtils.showAlert({ message: '{{Télécommande appairée.}}', level: 'success' })
      /* Le démon ouvre la connexion de commande dans la seconde. */
      setTimeout(function () { googletvbeReload(_id) }, 2500)
    }, function (error) {
      domUtils.hideLoading()
      var message = (error && error.result) ? String(error.result) : ''
      if (message.indexOf('code incorrect') !== -1) {
        googletvbeAskCode(_id, code)
        return
      }
      googletvbeFailed(error, '{{Échec de l\'appairage}}')
    })
  }, cancel)
}

function googletvbeOverlay(_action, _done) {
  var id = googletvbeCurrentId()
  if (id === '') { return }
  /* Une relance de TvOverlay prend quelques secondes. */
  domUtils.showLoading()
  googletvbeAjax(_action, { id: id }, function () {
    domUtils.hideLoading()
    jeedomUtils.showAlert({ message: _done, level: 'success' })
  }, function (error) {
    googletvbeFailed(error, '{{TvOverlay ne répond pas}}')
  })
}

function googletvbeUnpair() {
  var id = googletvbeCurrentId()
  if (id === '') { return }
  googletvbeConfirm('{{Oublier l\'appairage}}', '{{Jeedom ne pilotera plus les touches de cette TV jusqu\'au prochain appairage.}}', function () {
    googletvbeAjax('unpair', { id: id }, function () { googletvbeReload(id) })
  })
}

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  /* Sans ce champ, chaque enregistrement détruit puis recrée les commandes. */
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  if (init(_cmd.type) === 'info') {
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  }
  tr += '<span class="cmdAttr" data-l1key="unite" style="margin-left:8px;opacity:.7;"></span>'
  tr += '</td>'
  tr += '<td><span class="cmdAttr" data-l1key="htmlstate"></span></td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '</td>'

  /* Ligne créée en DOM : insertAdjacentHTML sur une table crée un <tbody> par
     insertion. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant logique}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* =============================================================== ÉCOUTEURS */

/* Pages chargées en ajax : DOMContentLoaded a déjà eu lieu, et ce script est
   réexécuté à chaque visite de la page. Les gestionnaires sont redéfinis à
   chaque chargement ; l'écouteur, lui, n'est posé qu'une fois sur le
   document et les appelle au moment du clic. */
window.googletvbeHandlers = {
  click: function (_event) {
    var target = _event.target
    if (target === null || typeof target.closest !== 'function') { return }
    var actions = {
      bt_googletvbeDiscover: googletvbeDiscover,
      bt_googletvbeAddIp: googletvbeAddIp,
      bt_googletvbeReprobe: googletvbeReprobe,
      bt_googletvbeWake: googletvbeWake,
      bt_googletvbePair: googletvbePair,
      bt_googletvbeUnpair: googletvbeUnpair,
      bt_googletvbeOverlayTest: function () { googletvbeOverlay('overlayTest', '{{Notification envoyée.}}') },
      bt_googletvbeOverlayRestart: function () { googletvbeOverlay('overlayRestart', '{{TvOverlay relancée.}}') }
    }
    for (var id in actions) {
      if (target.closest('#' + id) !== null) {
        _event.preventDefault()
        actions[id]()
        return
      }
    }
  },
  change: function (_event) {
    var box = _event.target
    if (box && box.classList && box.classList.contains('googletvbeFound') && window.googletvbeChosen) {
      window.googletvbeChosen[box.getAttribute('data-index')] = box.checked
    }
  }
}

if (!window.googletvbeListeningV2) {
  window.googletvbeListeningV2 = true
  ;['click', 'change'].forEach(function (_type) {
    document.addEventListener(_type, function (_event) {
      if (window.googletvbeHandlers && typeof window.googletvbeHandlers[_type] === 'function') {
        window.googletvbeHandlers[_type](_event)
      }
    })
  })
}
