<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-volume-up"></i> {{Commandes}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Pas du volume}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="volume_step" placeholder="2" min="1" max="20">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Pourcents ajoutés ou retirés par les commandes « Volume + » et « Volume - ».}}</span>
			</div>
		</div>

		<legend><i class="fas fa-plug"></i> {{Démon}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Port local des ordres}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="daemon_port" placeholder="55180" min="1025" max="65535">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Port sur lequel le démon attend les ordres de Jeedom, en boucle locale uniquement (127.0.0.1). À changer seulement s'il est déjà pris par un autre programme ; le démon est relancé.}}</span>
			</div>
		</div>
	</fieldset>
</form>
