<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('googletvbe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor logoPrimary" id="bt_googletvbeDiscover">
				<i class="fas fa-search"></i>
				<br>
				<span>{{Rechercher des TV}}</span>
			</div>
			<div class="cursor logoSecondary" id="bt_googletvbeAddIp">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter par adresse IP}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>

		<legend><i class="fas fa-tv"></i> {{Mes TV}}</legend>
		<?php
		if (count($eqLogics) === 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucune TV pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Vérifiez que le démon est démarré (page Configuration du plugin).}}</li>';
			echo '<li>{{Cliquez sur « Rechercher des TV » : le plugin interroge le réseau local (SSDP). Vous pouvez aussi saisir l\'adresse IP de la TV.}}</li>';
			echo '<li>{{Créez la TV trouvée : ses commandes sont remplies dès que le démon la joint.}}</li>';
			echo '</ol>';
			echo '<span class="help-block" style="margin:8px 0 0 0;">{{Tout se passe sur votre réseau local, sans cloud.}}</span>';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<img src="' . $plugin->getPathImgIcon() . '">';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo '<span class="label label-default">' . htmlspecialchars((string) $eqLogic->getConfiguration('ip', '')) . '</span> ';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
			<li role="presentation"><a href="#diagtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-stethoscope"></i><span class="hidden-xs"> {{Diagnostic}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ========================= ÉQUIPEMENT ========================= -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{TV du salon}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											echo '<option value="' . $object->getId() . '">' . $object->getHumanName(true, true) . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Activer}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Visible}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tv"></i> {{Téléviseur}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Adresse IP}}</label>
								<div class="col-sm-5">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip" placeholder="192.168.0.106">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Adresses MAC}}</label>
								<div class="col-sm-8">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="mac" placeholder="94:8c:d7:00:00:00, 38:26:56:00:00:00">
									<span class="help-block">{{Pour l'allumage par le réseau (Wake-on-LAN). Une par interface de la TV : Wi-Fi et Ethernet n'ont pas la même. Celle qui répond est relevée automatiquement.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Modèle}}</label>
								<div class="col-sm-9">
									<span id="span_googletvbeModel"></span>
									<span id="span_googletvbeProduct" class="label label-default" style="margin-left:6px;"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Version Cast}}</label>
								<div class="col-sm-9">
									<span id="span_googletvbeCast"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Connexion}}</label>
								<div class="col-sm-9">
									<span id="span_googletvbeLink" class="label label-default">…</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label"></label>
								<div class="col-sm-9">
									<a class="btn btn-default btn-sm" id="bt_googletvbeReprobe"><i class="fas fa-sync"></i> {{Relire la TV}}</a>
									<a class="btn btn-default btn-sm" id="bt_googletvbeWake"><i class="fas fa-power-off"></i> {{Réveiller par le réseau}}</a>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-gamepad"></i> {{Télécommande}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{État}}</label>
								<div class="col-sm-9">
									<span id="span_googletvbeRemote" class="label label-default">…</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label"></label>
								<div class="col-sm-9">
									<a class="btn btn-primary btn-sm" id="bt_googletvbePair"><i class="fas fa-link"></i> {{Appairer la télécommande}}</a>
									<a class="btn btn-default btn-sm" id="bt_googletvbeUnpair" style="display:none;"><i class="fas fa-unlink"></i> {{Oublier l'appairage}}</a>
									<span class="help-block">{{Touches, marche/arrêt, lancement d'applis et appli au premier plan passent par le protocole de l'appli Google TV. La TV doit être allumée : elle affiche un code à six caractères, à recopier ici. Une seule fois.}}</span>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-comment-alt"></i> {{TvOverlay (garder en marche)}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Surveiller}}</label>
								<div class="col-sm-9">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="overlay">
									<span class="help-block" style="margin:0;">{{L'appli TvOverlay affiche les notifications de Jeedom par-dessus l'image ; elles s'envoient avec le plugin TvOverlay. Ce plugin-ci la garde en marche. Cochée d'office quand elle est trouvée sur la TV.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Port}}</label>
								<div class="col-sm-3">
									<input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="overlay_port" placeholder="5001">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Relance automatique}}</label>
								<div class="col-sm-5">
									<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="overlay_watchdog">
										<option value="1">{{Dans les 3 minutes après l'allumage}}</option>
										<option value="2">{{À tout moment}}</option>
										<option value="0">{{Jamais}}</option>
									</select>
								</div>
								<div class="col-sm-9 col-sm-offset-3">
									<span class="help-block" style="margin:0;">{{Android arrête TvOverlay de temps à autre. Chaque minute, TV allumée, le plugin vérifie qu'elle répond et la relance par la télécommande (appairage requis) : fiche Play Store, « Ouvrir », puis deux fois « Retour », une dizaine de secondes. Juste après l'allumage, l'écran d'accueil est affiché et personne n'est dérangé ; « à tout moment » peut interrompre un film. Au plus une relance automatique toutes les dix minutes. Indépendamment de ce réglage, le plugin TvOverlay demande une relance quand une de ses notifications trouve l'appli arrêtée (TV allumée seulement).}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Reprendre la lecture}}</label>
								<div class="col-sm-9">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="overlay_resume">
									<span class="help-block" style="margin:0;">{{Après une relance, envoie la touche Lecture : Netflix, mis en pause par le passage en arrière-plan, reprend le film.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label"></label>
								<div class="col-sm-9">
									<a class="btn btn-default btn-sm" id="bt_googletvbeOverlayTest"><i class="fas fa-comment-alt"></i> {{Envoyer une notification de test}}</a>
									<a class="btn btn-default btn-sm" id="bt_googletvbeOverlayRestart"><i class="fas fa-redo"></i> {{Relancer TvOverlay}}</a>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ========================== DIAGNOSTIC ========================= -->
			<div role="tabpanel" class="tab-pane" id="diagtab">
				<br>
				<div class="col-xs-12">
					<legend><i class="fas fa-code"></i> {{Dernier état reçu de la TV}}</legend>
					<span class="help-block">{{Le RECEIVER_STATUS et le MEDIA_STATUS Cast tels que la TV les a envoyés. C'est la pièce à joindre en cas de valeur douteuse.}}</span>
					<pre id="pre_googletvbeRaw" style="max-height:520px;overflow:auto;"></pre>
				</div>
			</div>

			<!-- ========================== COMMANDES ========================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="col-xs-12">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:300px;">{{Nom}}</th>
								<th style="width:130px;">{{Type}}</th>
								<th>{{Paramètres}}</th>
								<th style="width:160px;">{{Valeur}}</th>
								<th style="width:120px;">{{Actions}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('desktop', 'googletvbe', 'js', 'googletvbe'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
