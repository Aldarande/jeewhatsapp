<?php
/* This file is part of Jeedom.
 * Plugin JeeWhatsApp - Aldarande
 * Licence AGPL v3 — https://www.gnu.org/licenses/agpl-3.0.html
 */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}

$plugin   = plugin::byId('jeewhatsapp');
$eqLogics = eqLogic::byType('jeewhatsapp');
sendVarToJS('eqType', 'jeewhatsapp');
?>

<div class="row row-overflow">

	<!-- ── Liste des équipements ──────────────────────────────────────────── -->
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>

		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i><br>
				<span>{{Ajouter}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i><br>
				<span>{{Configuration}}</span>
			</div>
			<div class="cursor logoSecondary" id="bt_donJeeWhatsApp" title="{{Faire un don}}">
				<i class="fas fa-mug-hot"></i><br>
				<span>{{Don}}</span>
			</div>
		</div>

		<!-- Modal Don -->
		<div class="modal fade" id="modal_donJeeWhatsApp" tabindex="-1" role="dialog">
			<div class="modal-dialog" role="document">
				<div class="modal-content">
					<div class="modal-header" style="background-color:#25D366;border-radius:5px 5px 0 0;">
						<button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1;"><span>&times;</span></button>
						<h4 class="modal-title" style="color:#fff;"><i class="fas fa-mug-hot"></i> {{Soutenir JeeWhatsApp}}</h4>
					</div>
					<div class="modal-body" style="text-align:center;">
						<p style="font-size:1.1em;">{{Ce plugin est gratuit et open-source.}}<br>
						{{Si vous l'appréciez, offrez moi un café !}}</p>
						<hr>
						<a href="https://ko-fi.com/aldarande" target="_blank" class="btn btn-warning btn-lg" style="margin:8px;">
							<i class="fas fa-coffee"></i> Ko-fi
						</a>
						<a href="https://github.com/sponsors/Aldarande" target="_blank" class="btn btn-default btn-lg" style="margin:8px;">
							<i class="fab fa-github"></i> {{GitHub Sponsors}}
						</a>
					</div>
				</div>
			</div>
		</div>

		<legend><i class="fab fa-whatsapp"></i> {{Mes comptes WhatsApp}}</legend>

		<?php if (count($eqLogics) == 0) : ?>
			<br>
			<div class="text-center" style="font-size:1.2em;font-weight:bold;">
				{{Aucun compte configuré. Cliquez sur "Ajouter" pour commencer.}}
			</div>
		<?php else : ?>
			<div class="input-group" style="margin:5px;">
				<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">
				<div class="input-group-btn">
					<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>
				</div>
			</div>
			<div class="eqLogicThumbnailContainer">
				<?php foreach ($eqLogics as $eqLogic) : ?>
					<?php $opacity = $eqLogic->getIsEnable() ? '' : 'disableCard'; ?>
					<div class="eqLogicDisplayCard cursor <?php echo $opacity; ?>" data-eqLogic_id="<?php echo $eqLogic->getId(); ?>">
						<img src="<?php echo $eqLogic->getImage(); ?>"/>
						<br>
						<span class="name"><?php echo $eqLogic->getHumanName(true, true); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div><!-- /.eqLogicThumbnailDisplay -->


	<!-- ── Configuration d'un équipement ─────────────────────────────────── -->
	<div class="col-xs-12 eqLogic" style="display:none;">

		<!-- Boutons -->
		<div class="input-group pull-right" style="display:inline-flex;">
			<span class="input-group-btn">
				<a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure">
					<i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
				</a><a class="btn btn-sm btn-default eqLogicAction" data-action="copy">
					<i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span>
				</a><a class="btn btn-sm btn-success eqLogicAction" data-action="save">
					<i class="fas fa-check-circle"></i> {{Sauvegarder}}
				</a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove">
					<i class="fas fa-minus-circle"></i> {{Supprimer}}
				</a>
			</span>
		</div>

		<!-- Onglets -->
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation">
				<a href="#" class="eqLogicAction" data-action="returnToThumbnailDisplay">
					<i class="fas fa-arrow-circle-left"></i>
				</a>
			</li>
			<li role="presentation" class="active">
				<a href="#eqlogictab" role="tab" data-toggle="tab">
					<i class="fas fa-tachometer-alt"></i> {{Equipement}}<span id="tab_qr_status_dot" style="display:inline-block;width:10px;height:10px;border-radius:50%;background-color:#999;margin-left:7px;vertical-align:middle;border:1px solid rgba(0,0,0,0.15);transition:background-color 0.4s;"></span>
				</a>
			</li>
			<li role="presentation">
				<a href="#commandtab" role="tab" data-toggle="tab">
					<i class="fas fa-list"></i> {{Commandes}}
				</a>
			</li>
			<li role="presentation">
				<a href="#testtab" role="tab" data-toggle="tab">
					<i class="fas fa-paper-plane"></i> {{Test}}
				</a>
			</li>
		</ul>

		<div class="tab-content">

			<!-- ── Onglet Équipement ──────────────────────────────────────── -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<form class="form-horizontal">
					<fieldset>
						<div class="col-lg-6">
							<legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom}}</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="Mon WhatsApp">
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Objet parent}}</label>
								<div class="col-sm-7">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php foreach (jeeObject::buildTree(null, false) as $object) : ?>
											<option value="<?php echo (int) $object->getId(); ?>">
												<?php echo str_repeat('&nbsp;&nbsp;', (int) $object->getConfiguration('parentNumber')); ?>
												<?php echo htmlspecialchars($object->getName(), ENT_QUOTES, 'UTF-8'); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Catégorie}}</label>
								<div class="col-sm-7">
									<?php foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) : ?>
										<label class="checkbox-inline">
											<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
											<?php echo htmlspecialchars($value['name'], ENT_QUOTES, 'UTF-8'); ?>
										</label>
									<?php endforeach; ?>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Options}}</label>
								<div class="col-sm-7">
									<label class="checkbox-inline">
										<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked> {{Activer}}
									</label>
									<label class="checkbox-inline">
										<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked> {{Visible}}
									</label>
								</div>
							</div>

							<!-- ── Connexion WhatsApp ──────────────────────────────── -->
							<legend style="margin-top:10px;"><i class="fab fa-whatsapp" style="color:#25D366;"></i> {{Connexion}}</legend>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Statut}}</label>
								<div class="col-sm-7">
									<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
										<span id="wa_status_badge" class="label label-default" style="font-size:0.9em;padding:6px 14px;">
											<i class="fas fa-circle-notch fa-spin"></i> {{Chargement…}}
										</span>
										<button class="btn btn-xs btn-default" id="btn_refresh_qr" type="button">
											<i class="fas fa-sync-alt"></i> {{Rafraîchir}}
										</button>
									</div>
									<div id="wa_connected_zone" style="display:none;margin-top:6px;">
										<i class="fas fa-check-circle" style="color:#25D366;font-size:1.1em;vertical-align:middle;margin-right:5px;"></i>
										<span style="color:#25D366;font-weight:600;">{{Connecté à WhatsApp ✓}}</span>
									</div>
									<div id="wa_disconnected_zone" style="display:none;margin-top:6px;">
										<i class="fas fa-times-circle" style="color:#d9534f;font-size:1.1em;vertical-align:middle;margin-right:5px;"></i>
										<span style="color:#d9534f;">{{Session expirée — redémarrez le daemon}}</span>
									</div>
								</div>
							</div>

							<div class="form-group" id="wa_qr_zone" style="display:none;">
								<label class="col-sm-4 control-label">{{QR Code}}</label>
								<div class="col-sm-7">
									<p class="text-muted" style="font-size:0.83em;margin-bottom:7px;">
										<i class="fas fa-mobile-alt"></i>
										{{WhatsApp → ⋮ → Appareils liés → Lier un appareil}}
									</p>
									<img id="wa_qr_img" src=""
										style="width:180px;height:180px;border:3px solid #25D366;border-radius:10px;display:block;">
									<p class="help-block" style="font-size:0.8em;margin-top:5px;">
										{{QR code valide 30 s — se rafraîchit automatiquement}}
									</p>
								</div>
							</div>

						</div>

						<div class="col-lg-6">
							<legend><i class="fas fa-sliders-h"></i> {{Paramètres WhatsApp}}</legend>

							<!-- Nom du groupe canal -->
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Groupe canal}}</label>
								<div class="col-sm-7">
									<div class="input-group">
										<input type="text" class="eqLogicAttr form-control"
											   data-l1key="configuration" data-l2key="group_name"
											   placeholder="jeewhatsapp">
										<span class="input-group-btn">
											<button class="btn btn-default" type="button" id="btn_find_group" title="{{Rechercher ce groupe dans vos groupes WhatsApp}}">
												<i class="fas fa-search"></i> {{Rechercher}}
											</button>
											<button class="btn btn-default" type="button" id="btn_create_group" title="{{Créer ce groupe sur WhatsApp}}">
												<i class="fab fa-whatsapp"></i> {{Créer}}
											</button>
										</span>
									</div>
									<span class="help-block">
										<small>{{Nom exact du groupe WhatsApp utilisé comme canal de communication}}</small>
									</span>
								</div>
							</div>

							<!-- JID du groupe lié (readonly) -->
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Groupe lié}}</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control"
										   data-l1key="configuration" data-l2key="group_jid"
										   placeholder="{{Aucun groupe lié}}"
										   readonly style="background:#f5f5f5;cursor:default;">
									<span class="help-block">
										<small>{{JID renseigné automatiquement après recherche ou création. Sauvegardez après.}}</small>
									</span>
									<span id="group_link_result" class="help-block" style="display:none;"></span>
								</div>
							</div>

							<!-- Interactions Jeedom -->
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Interactions Jeedom}}</label>
								<div class="col-sm-7">
									<label class="checkbox-inline">
										<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="interactions_enabled" value="1"> {{Activer}}
									</label>
									<span class="help-block">
										<small>{{Répond automatiquement aux messages reçus dans le groupe via le moteur d'interactions Jeedom}}</small>
									</span>
								</div>
							</div>

							<!-- Filtre mot-clé (v0.2) -->
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Mot-clé déclencheur}}</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control"
										   data-l1key="configuration" data-l2key="interaction_keyword"
										   placeholder="ex: !jeedom (vide = tout message)">
									<span class="help-block">
										<small>{{Si renseigné, seuls les messages commençant par ce mot-clé déclenchent les interactions. Le mot-clé est ensuite retiré du message avant traitement. Insensible à la casse.}}</small>
									</span>
								</div>
							</div>

							<!-- Whitelist expéditeurs (v0.2, sécurité) -->
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Whitelist expéditeurs}}</label>
								<div class="col-sm-7">
									<textarea class="eqLogicAttr form-control" rows="3"
										   data-l1key="configuration" data-l2key="interaction_whitelist"
										   placeholder="33612345678&#10;0698765432&#10;(vide = tout le monde)"></textarea>
									<span class="help-block">
										<small>{{Si renseignée, seuls les numéros listés peuvent déclencher des interactions Jeedom. Un numéro par ligne (ou séparés par virgule). Formats acceptés : 0612345678, 33612345678, +33 6 12 34 56 78 — tous normalisés au format international.}}</small>
									</span>
								</div>
							</div>

							<!-- Présence typing (v0.3 #14) -->
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Présence "en train d'écrire"}}</label>
								<div class="col-sm-7">
									<label class="checkbox-inline">
										<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="presence_enabled" value="1"> {{Activer}}
									</label>
									<span class="help-block">
										<small>{{Affiche "en train d'écrire…" (ou "enregistre…" pour l'audio) pendant ~1s avant chaque envoi automatique. Rend les messages de Jeedom plus naturels.}}</small>
									</span>
								</div>
							</div>

							<!-- Préfixe Jeedom -->
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Préfixe Jeedom}}</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control"
										   data-l1key="configuration" data-l2key="interaction_prefix"
										   placeholder="🏠  (défaut si vide)">
									<span class="help-block">
										<small>{{Ajouté à chaque message envoyé par Jeedom dans le groupe. Permet de distinguer les messages Jeedom des messages des membres.}}</small>
									</span>
								</div>
							</div>

							<!-- Message de soutien mensuel -->
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Message de soutien}}</label>
								<div class="col-sm-7">
									<label class="checkbox-inline">
										<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="donation_enabled" value="1"> {{Envoyer 1 message par mois}}
									</label>
									<span class="help-block">
										<small>{{Une fois par mois, à un jour et une heure aléatoires (entre 10h et 19h), un rappel discret de soutien est envoyé dans le groupe canal. Tirage parmi un pool de 12 messages préparés par l'auteur. Désactivé par défaut.}}</small>
									</span>
								</div>
							</div>

						</div>
					</fieldset>
				</form>
			</div><!-- /#eqlogictab -->

			<!-- ── Onglet Commandes ───────────────────────────────────────── -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed" style="table-layout:fixed;width:100%;">
						<colgroup>
							<col style="width:70px;">
							<col style="width:250px;">
							<col style="width:100px;">
							<col>
							<col style="width:130px;">
							<col style="width:90px;">
						</colgroup>
						<thead>
							<tr>
								<th style="text-align:center;">{{#}}</th>
								<th>{{Nom}}</th>
								<th style="text-align:center;">{{Type}}</th>
								<th>{{Valeur actuelle}}</th>
								<th>{{Options}}</th>
								<th>{{Actions}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div><!-- /#commandtab -->

			<!-- ── Onglet Test ────────────────────────────────────────────── -->
			<div role="tabpanel" class="tab-pane" id="testtab">
				<div style="max-width:640px;margin:24px auto;">

					<!-- En-tête card -->
					<div style="background:#25D366;color:#fff;padding:13px 20px;border-radius:8px 8px 0 0;display:flex;align-items:center;gap:10px;">
						<i class="fab fa-whatsapp" style="font-size:1.5em;"></i>
						<span style="font-size:1.05em;font-weight:600;">{{Envoyer un message de test}}</span>
					</div>

					<div style="border:1px solid #ddd;border-top:none;border-radius:0 0 8px 8px;padding:22px 24px;background:#fff;">

						<!-- Destinataire -->
						<div class="form-group" style="margin-bottom:14px;">
							<label style="font-weight:600;margin-bottom:5px;display:block;">
								<i class="fas fa-share" style="color:#25D366;margin-right:6px;"></i>
								{{Destinataire}}
								<span class="text-muted" style="font-weight:normal;font-size:0.88em;margin-left:4px;">{{— vide = groupe canal}}</span>
							</label>
							<input type="text" id="test_phone" class="form-control"
								placeholder="{{Laisser vide pour le groupe canal — ou numéro direct ex : 33612345678}}">
						</div>

						<!-- Message -->
						<div class="form-group" style="margin-bottom:14px;">
							<label style="font-weight:600;margin-bottom:5px;display:block;">
								<i class="fas fa-comment-dots" style="color:#25D366;margin-right:6px;"></i>
								{{Message}} <span class="text-danger" title="{{Champ obligatoire}}">*</span>
							</label>
							<input type="text" id="test_message" class="form-control"
								value="Test depuis JeeWhatsApp 🚀">
						</div>

						<!-- Séparateur optionnel -->
						<div style="border-top:1px solid #f0f0f0;margin:18px 0 16px;"></div>

						<!-- Mention -->
						<div class="form-group" style="margin-bottom:20px;">
							<label style="font-weight:600;margin-bottom:5px;display:block;">
								<i class="fas fa-at" style="color:#25D366;margin-right:6px;"></i>
								{{Mention}}
								<span class="text-muted" style="font-weight:normal;font-size:0.88em;margin-left:4px;">{{— optionnel}}</span>
							</label>
							<input type="text" id="test_mention" class="form-control"
								placeholder="{{Numéro à mentionner — ex : 33612345678}}">
							<p class="help-block" style="margin-top:5px;font-size:0.85em;color:#888;">
								<i class="fas fa-info-circle"></i>
								{{Notifie le membre mentionné — ne fonctionne que si Jeedom utilise un compte dédié (pas votre compte personnel)}}
							</p>
						</div>

						<!-- Bouton + résultat inline -->
						<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
							<button class="btn btn-success" id="btn_test_send" style="min-width:170px;font-size:1em;padding:8px 18px;">
								<i class="fas fa-paper-plane"></i> {{Envoyer le test}}
							</button>
							<span id="test_result" style="font-size:0.95em;"></span>
						</div>

					</div><!-- /card body -->
				</div><!-- /max-width -->
			</div><!-- /#testtab -->

		</div><!-- /.tab-content -->
	</div><!-- /.eqLogic -->

</div><!-- /.row -->

<script>
var _waQRInterval = null;

// ── Bouton don ──────────────────────────────────────────────────────────────
$('#bt_donJeeWhatsApp').on('click', function () {
  $('#modal_donJeeWhatsApp').modal('show');
});

// ── Polling statut connexion — démarre quand l'équipement s'ouvre, s'arrête quand caché ────
// Jeedom affiche la div .eqLogic en changeant son style.display — pas d'événement natif.
// On observe l'attribut style pour détecter l'ouverture / fermeture de l'équipement.
(function () {
  var target = document.querySelector('.col-xs-12.eqLogic');
  if (!target) { return; }
  new MutationObserver(function (mutations) {
    for (var m of mutations) {
      if (m.type !== 'attributes') { continue; }
      if (target.style.display !== 'none') {
        // Jeedom populates form fields after changing display — wait before reading eqLogic_id
        setTimeout(function () {
          var eqLogic_id = $('input.eqLogicAttr[data-l1key="id"]').val();
          if (!eqLogic_id) { return; }
          refreshQRStatus();
          if (_waQRInterval) { clearInterval(_waQRInterval); }
          _waQRInterval = setInterval(refreshQRStatus, 8000);
        }, 300);
      } else {
        if (_waQRInterval) { clearInterval(_waQRInterval); _waQRInterval = null; }
      }
    }
  }).observe(target, { attributes: true, attributeFilter: ['style'] });
})();

$('#btn_refresh_qr').on('click', function () { refreshQRStatus(); });

function refreshQRStatus() {
  var eqLogic_id = $('input.eqLogicAttr[data-l1key="id"]').val();
  if (!eqLogic_id) { return; }

  $.ajax({
    type: 'POST',
    url:  'plugins/jeewhatsapp/core/ajax/jeewhatsapp.ajax.php',
    data: { action: 'getQR', eqLogic_id: eqLogic_id },
    dataType: 'json',
    success: function (data) {
      if (data.state !== 'ok') { showStatus('error', '{{Erreur daemon}}'); return; }
      var r = data.result;
      applyStatus(r);
    },
    error: function () { showStatus('error', '{{Impossible de contacter le daemon}}'); }
  });
}

function applyStatus(r) {
  $('#wa_qr_zone').hide();
  $('#wa_connected_zone').hide();
  $('#wa_disconnected_zone').hide();

  if (r && r.qr) {
    $('#wa_qr_img').attr('src', r.qr);
    $('#wa_qr_zone').show();
    showStatus('warning', '{{En attente du scan QR…}}');
  } else if (r && r.status === 'connected') {
    $('#wa_connected_zone').show();
    showStatus('success', '{{Connecté}}');
    if (_waQRInterval) { clearInterval(_waQRInterval); _waQRInterval = null; }
  } else if (r && (r.status === 'logged_out' || r.status === 'unknown')) {
    $('#wa_disconnected_zone').show();
    showStatus('danger', '{{Déconnecté}}');
  } else {
    var statusMap = {
      'connecting':   '{{Connexion en cours…}}',
      'reconnecting': '{{Reconnexion en cours…}}',
    };
    showStatus('info', statusMap[r && r.status] || '{{Connexion en cours…}}');
  }
}

function showStatus(type, label) {
  var icons = {
    success: 'fas fa-check-circle',
    warning: 'fas fa-qrcode',
    danger:  'fas fa-times-circle',
    info:    'fas fa-circle-notch fa-spin',
    error:   'fas fa-exclamation-triangle',
    default: 'fas fa-circle-notch fa-spin',
  };
  var dotColors = {
    success: '#25D366',
    warning: '#f0ad4e',
    danger:  '#d9534f',
    error:   '#d9534f',
    info:    '#aaaaaa',
  };
  var icon = icons[type] || icons['default'];
  $('#wa_status_badge')
    .removeClass('label-default label-success label-warning label-danger label-info label-primary')
    .addClass('label-' + (type === 'error' ? 'danger' : type))
    .html('<i class="' + icon + '"></i> ' + label);
  $('#tab_qr_status_dot').css('background-color', dotColors[type] || '#aaaaaa');
}

// ── Recherche du groupe canal ───────────────────────────────────────────────
$('#btn_find_group').on('click', function () {
  var eqLogic_id = $('input.eqLogicAttr[data-l1key="id"]').val();
  if (!eqLogic_id) {
    $('#group_link_result').text('{{Sauvegardez l\'équipement avant de rechercher le groupe}}').css('color', 'red').show();
    return;
  }
  var groupName = $('input.eqLogicAttr[data-l1key="configuration"][data-l2key="group_name"]').val().trim() || 'jeewhatsapp';

  var $btn    = $(this);
  var $result = $('#group_link_result');
  $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{Recherche…}}');
  $result.hide();

  $.ajax({
    type:     'POST',
    url:      'plugins/jeewhatsapp/core/ajax/jeewhatsapp.ajax.php',
    data:     { action: 'findGroup', eqLogic_id: eqLogic_id, name: groupName },
    dataType: 'json',
    success: function (data) {
      $btn.prop('disabled', false).html('<i class="fas fa-search"></i> {{Rechercher}}');
      if (data.state === 'ok') {
        var jid = data.result.jid;
        $('input.eqLogicAttr[data-l1key="configuration"][data-l2key="group_jid"]').val(jid);
        // SECURITY (F-007): échappement via .text() pour éviter XSS sur groupName
        $result.empty()
          .append($('<i>').addClass('fas fa-check-circle').css('color', '#25D366'))
          .append(' {{Groupe}} ')
          .append($('<strong>').text(groupName))
          .append(' {{trouvé — JID renseigné. Sauvegardez.}}')
          .css('color', '#25D366').show();
      } else {
        $result.text('{{Groupe introuvable : }}' + (data.result || data.error || '?')).css('color', 'red').show();
      }
    },
    error: function () {
      $btn.prop('disabled', false).html('<i class="fas fa-search"></i> {{Rechercher}}');
      $result.text('{{Erreur de communication avec le daemon}}').css('color', 'red').show();
    }
  });
});

// ── Création du groupe canal ────────────────────────────────────────────────
$('#btn_create_group').on('click', function () {
  var eqLogic_id = $('input.eqLogicAttr[data-l1key="id"]').val();
  if (!eqLogic_id) {
    $('#group_link_result').text('{{Sauvegardez l\'équipement avant de créer un groupe}}').css('color', 'red').show();
    return;
  }
  var groupName = $('input.eqLogicAttr[data-l1key="configuration"][data-l2key="group_name"]').val().trim() || 'jeewhatsapp';

  var $btn    = $(this);
  var $result = $('#group_link_result');
  $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{Création…}}');
  $result.hide();

  $.ajax({
    type:     'POST',
    url:      'plugins/jeewhatsapp/core/ajax/jeewhatsapp.ajax.php',
    data:     { action: 'createGroup', eqLogic_id: eqLogic_id, name: groupName },
    dataType: 'json',
    success: function (data) {
      $btn.prop('disabled', false).html('<i class="fab fa-whatsapp"></i> {{Créer}}');
      if (data.state === 'ok') {
        var jid = data.result.jid;
        $('input.eqLogicAttr[data-l1key="configuration"][data-l2key="group_jid"]').val(jid);
        // SECURITY (F-007): échappement via .text() pour éviter XSS sur groupName
        $result.empty()
          .append($('<i>').addClass('fas fa-check-circle').css('color', '#25D366'))
          .append(' {{Groupe}} ')
          .append($('<strong>').text(groupName))
          .append(' {{créé — JID renseigné. Sauvegardez.}}')
          .css('color', '#25D366').show();
      } else {
        $result.text('{{Erreur : }}' + (data.result || data.error || '?')).css('color', 'red').show();
      }
    },
    error: function () {
      $btn.prop('disabled', false).html('<i class="fab fa-whatsapp"></i> {{Créer}}');
      $result.text('{{Erreur de communication avec le daemon}}').css('color', 'red').show();
    }
  });
});

// ── Envoi de test ───────────────────────────────────────────────────────────
function doTestSend(extra) {
  var eqLogic_id = $('input.eqLogicAttr[data-l1key="id"]').val();
  var phone      = $('#test_phone').val().trim();
  var message    = $('#test_message').val().trim();
  var mention    = $('#test_mention').val().trim();
  var $result    = $('#test_result');

  if (!message) { $result.text('{{Veuillez saisir un message}}').css('color', 'red'); return; }
  $result.html('<i class="fas fa-spinner fa-spin"></i> {{Envoi en cours…}}').css('color', 'inherit');

  var data = { action: 'testSend', eqLogic_id: eqLogic_id, message: message };
  if (phone)   { data.phone   = phone; }
  if (mention) { data.mention = mention; }
  if (extra)   { $.extend(data, extra); }

  $.ajax({
    type: 'POST',
    url:  'plugins/jeewhatsapp/core/ajax/jeewhatsapp.ajax.php',
    data: data,
    dataType: 'json',
    success: function (resp) {
      if (resp.state === 'ok') {
        $result.text('{{Message envoyé !}}').css('color', 'green');
      } else {
        $result.text('{{Erreur : }}' + resp.result).css('color', 'red');
      }
    },
    error: function () { $result.text('{{Erreur de communication}}').css('color', 'red'); }
  });
}

$('#btn_test_send').on('click', function () { doTestSend(); });

</script>

<?php include_file('desktop', 'jeewhatsapp', 'js', 'jeewhatsapp'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
