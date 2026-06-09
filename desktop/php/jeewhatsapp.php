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

		<!-- Modal Nouvel équipement -->
		<div class="modal fade" id="modal_newJeeWhatsApp" tabindex="-1" role="dialog">
			<div class="modal-dialog modal-sm" role="document">
				<div class="modal-content">
					<div class="modal-header" style="background:linear-gradient(135deg,#075e54,#128c7e);border-radius:5px 5px 0 0;">
						<button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1;"><span>&times;</span></button>
						<h4 class="modal-title" style="color:#fff;">
							<i class="fab fa-whatsapp"></i> {{Nouveau compte WhatsApp}}
						</h4>
					</div>
					<div class="modal-body" style="padding:20px 24px;">
						<div class="form-group" style="margin:0;">
							<label style="font-weight:600;margin-bottom:8px;display:block;">{{Nom de l'équipement}}</label>
							<input type="text" id="in_newJeeWhatsAppName" class="form-control"
								placeholder="{{ex : WhatsApp maison, Bot Jeedom…}}"
								maxlength="64" autocomplete="off">
							<p class="help-block" style="margin-top:6px;font-size:0.85em;">
								{{Ce nom identifie le compte dans Jeedom. Il peut être modifié plus tard.}}
							</p>
						</div>
					</div>
					<div class="modal-footer" style="border-top:1px solid #e8e8e8;padding:12px 16px;">
						<button type="button" class="btn btn-default" data-dismiss="modal">{{Annuler}}</button>
						<button type="button" class="btn btn-success" id="btn_confirmNewJeeWhatsApp">
							<i class="fas fa-plus-circle"></i> {{Créer}}
						</button>
					</div>
				</div>
			</div>
		</div>

		<!-- Modal Don -->
		<div class="modal fade" id="modal_donJeeWhatsApp" tabindex="-1" role="dialog">
			<div class="modal-dialog" role="document">
				<div class="modal-content" style="border-radius:10px;overflow:hidden;">
					<div class="modal-header" style="background:linear-gradient(135deg,#075e54,#128c7e);border:none;padding:18px 20px;">
						<button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1;font-size:1.4em;margin-top:-2px;"><span>&times;</span></button>
						<h4 class="modal-title" style="color:#fff;font-size:1.1em;">
							<i class="fas fa-heart" style="color:#ff6b6b;margin-right:7px;"></i> {{Soutenir JeeWhatsApp}}
						</h4>
					</div>
					<div class="modal-body" style="padding:22px 24px;">
						<p style="font-size:1em;color:#333;margin-bottom:6px;">
							{{JeeWhatsApp est un plugin}} <strong>{{gratuit et open-source}}</strong> {{(AGPL v3), développé et maintenu bénévolement.}}
						</p>
						<p style="font-size:0.9em;color:#666;margin-bottom:18px;">
							{{Un don, même modeste, aide à financer le temps de développement, les tests et les mises à jour. Merci !}}
						</p>
						<div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-bottom:18px;">
							<a href="https://ko-fi.com/aldarande" target="_blank" rel="noopener"
							   class="btn btn-lg" style="background:#FF5E5B;color:#fff;border:none;min-width:140px;">
								<i class="fas fa-mug-hot"></i> Ko-fi
							</a>
							<a href="https://github.com/sponsors/Aldarande" target="_blank" rel="noopener"
							   class="btn btn-lg" style="background:#24292e;color:#fff;border:none;min-width:140px;">
								<i class="fab fa-github"></i> Sponsors
							</a>
							<a href="https://liberapay.com/Aldarande/donate" target="_blank" rel="noopener"
							   class="btn btn-lg" style="background:#F6C915;color:#111;border:none;min-width:140px;">
								<i class="fas fa-hand-holding-heart"></i> Liberapay
							</a>
						</div>
						<p style="font-size:0.82em;color:#aaa;text-align:center;margin:0;">
							{{Ko-fi : don ponctuel &bull; Sponsors : mensuel &bull; Liberapay : récurrent et anonyme possible}}
						</p>
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
					<div class="eqLogicDisplayCard cursor <?php echo $opacity; ?>" data-eqLogic_id="<?php echo (int) $eqLogic->getId(); ?>">
						<img src="<?php echo htmlspecialchars($eqLogic->getImage(), ENT_QUOTES, 'UTF-8'); ?>"/>
						<br>
						<span class="name"><?php echo $eqLogic->getHumanName(true, true); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<!-- Compatibilité Notification Manager -->
		<?php
		$nmInstalled = file_exists(dirname(dirname(__FILE__)) . '/../notificationmanager/plugin_info/info.json')
		           ||  file_exists(dirname(dirname(__FILE__)) . '/../jeeNotificationManager/plugin_info/info.json');
		?>
		<div style="margin:12px 4px 0;padding:10px 14px;border-radius:8px;
		            background:<?php echo $nmInstalled ? '#e8f8f0' : '#f5f5f5'; ?>;
		            border:1px solid <?php echo $nmInstalled ? '#25D366' : '#ddd'; ?>;
		            display:flex;align-items:center;gap:10px;font-size:0.88em;">
			<i class="fas fa-bell" style="font-size:1.3em;color:<?php echo $nmInstalled ? '#25D366' : '#aaa'; ?>;flex-shrink:0;"></i>
			<div>
				<?php if ($nmInstalled) : ?>
					<strong>{{Notification Manager détecté}}</strong> —
					{{JeeWhatsApp est compatible. Sélectionnez la commande}}
					<code>{{Envoyer un message}}</code>
					{{comme destination dans votre profil Notification Manager.}}
					{{Le champ « Titre » = numéro destinataire (laisser vide = groupe canal).}}
				<?php else : ?>
					<strong>{{Compatible Notification Manager}}</strong> —
					{{La commande}} <code>{{Envoyer un message}}</code>
					{{(type action/message) est directement utilisable comme destination dans le plugin}}
					<a href="https://community.jeedom.com/t/notification-manager/67090" target="_blank">{{Notification Manager}}</a>.
					{{Champ Titre = numéro destinataire, champ Message = texte de la notification.}}
				<?php endif; ?>
			</div>
		</div>

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
			<li role="presentation">
				<a href="#templatestab" role="tab" data-toggle="tab">
					<i class="fas fa-bookmark"></i> {{Templates}}
				</a>
			</li>
			<li role="presentation">
				<a href="#statstab" role="tab" data-toggle="tab">
					<i class="fas fa-chart-bar"></i> {{Statistiques}}
				</a>
			</li>
			<li role="presentation">
				<a href="#livetab" role="tab" data-toggle="tab">
					<i class="fas fa-satellite-dish"></i> {{Live}}
				</a>
			</li>
		</ul>

		<div class="tab-content">

			<!-- ── Onglet Équipement ──────────────────────────────────────── -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<form class="form-horizontal">
					<fieldset>

						<!-- ── Ligne 1 : Paramètres généraux | Connexion ──────── -->
						<div class="row">

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
							</div><!-- /.col-lg-6 Paramètres généraux -->

							<div class="col-lg-6">
								<legend><i class="fab fa-whatsapp" style="color:#25D366;"></i> {{Connexion}}</legend>

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
											<button class="btn btn-xs btn-danger" id="btn_logout_wa" type="button" style="display:none;" title="{{Déconnecter ce compte WhatsApp}}">
												<i class="fas fa-power-off"></i> {{Déconnexion}}
											</button>
										</div>
										<div id="wa_connected_zone" style="display:none;margin-top:6px;">
											<i class="fas fa-check-circle" style="color:#25D366;font-size:1.1em;vertical-align:middle;margin-right:5px;"></i>
											<span style="color:#25D366;font-weight:600;">{{Connecté à WhatsApp ✓}}</span>
											<p class="text-muted" style="font-size:0.8em;margin:6px 0 0;">
												<i class="fas fa-bell-slash"></i> {{Pas de notification sur votre téléphone ? C'est normal : WhatsApp n'envoie pas de notification pour vos propres messages. Ajoutez un contact dans le groupe pour en recevoir.}}
											</p>
										</div>
										<div id="wa_disconnected_zone" style="display:none;margin-top:6px;">
											<i class="fas fa-times-circle" style="color:#d9534f;font-size:1.1em;vertical-align:middle;margin-right:5px;"></i>
											<span style="color:#d9534f;">{{Session expirée — un nouveau QR code apparaîtra automatiquement. Scannez-le pour reconnecter.}}</span>
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
											style="width:180px;height:180px;border:3px solid #25D366;border-radius:10px;display:block;cursor:zoom-in;"
											title="{{Cliquez pour agrandir}}"
											data-toggle="modal" data-target="#modal_qrZoom">
										<div style="margin-top:7px;display:flex;align-items:center;gap:8px;">
											<p class="help-block" style="font-size:0.8em;margin:0;">
												{{QR code valide 30 s — se rafraîchit automatiquement}}
											</p>
											<button type="button" class="btn btn-xs btn-default" data-toggle="modal" data-target="#modal_qrZoom" title="{{Agrandir le QR code}}">
												<i class="fas fa-search-plus"></i> {{Agrandir}}
											</button>
										</div>
									</div>
								</div>
							</div><!-- /.col-lg-6 Connexion -->

						</div><!-- /.row ligne 1 -->

						<!-- ── Ligne 2 : Paramètres WhatsApp (2 colonnes) ─────── -->
							<div class="row">
								<div class="col-xs-12">
									<legend><i class="fas fa-sliders-h"></i> {{Paramètres de base}}</legend>
								</div>
							</div>
							<div class="row">
								<div class="col-lg-6">
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
												<button class="btn btn-default" type="button" id="btn_set_group_icon" title="{{Utiliser l'icône du plugin comme photo du groupe (le compte doit être admin du groupe)}}">
													<i class="fas fa-image"></i> {{Icône}}
												</button>
											</span>
										</div>
										<span class="help-block">
											<small>{{Nom exact du groupe WhatsApp utilisé comme canal de communication. Le bouton « Icône » définit l'icône du plugin comme photo du groupe (nécessite que le compte WhatsApp lié soit administrateur du groupe).}}</small>
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
<!-- Préfixe Jeedom -->
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Préfixe Jeedom}}</label>
									<div class="col-sm-7">
										<input type="text" class="eqLogicAttr form-control"
											   data-l1key="configuration" data-l2key="interaction_prefix"
											   placeholder="(vide = prefixe maison par defaut)">
										<span class="help-block">
											<small>{{Ajouté à chaque message envoyé par Jeedom dans le groupe. Permet de distinguer les messages Jeedom des messages des membres.}}</small><br>
											<small>{{Laissez vide pour utiliser le préfixe maison par défaut. Si votre Jeedom est sur une ancienne base MySQL (utf8 et non utf8mb4), les emoji saisis ici peuvent provoquer une erreur à la sauvegarde — dans ce cas utilisez du texte simple (ex : [J]).}}</small>
										</span>
									</div>
								</div>
								</div>
								<div class="col-lg-6">
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
								</div>
							</div>
							<div class="row" style="margin-top:14px;">
								<div class="col-xs-12">
									<legend style="cursor:pointer;" data-toggle="collapse" data-target="#jwa_advanced" id="jwa_advanced_toggle">
										<i class="fas fa-sliders-h"></i> {{Paramètres avancés}}
										<i class="fas fa-chevron-down" style="font-size:0.8em;margin-left:6px;"></i>
										<small class="text-muted" style="font-weight:normal;margin-left:8px;">{{(pour aller plus loin — cliquez pour afficher)}}</small>
									</legend>
								</div>
							</div>
							<div class="row collapse" id="jwa_advanced">
								<div class="col-lg-6">
<!-- Groupes additionnels (v0.3 #16) -->
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Groupes additionnels}}</label>
									<div class="col-sm-7">
										<textarea class="eqLogicAttr form-control" rows="3"
											   data-l1key="configuration" data-l2key="extra_groups"
											   placeholder="alertes=Alertes Maison&#10;famille=Groupe Famille&#10;(vide = un seul groupe canal)"></textarea>
										<span class="help-block">
											<small>{{Groupes WhatsApp supplémentaires que cet équipement peut écouter et cibler, en plus du groupe canal principal. Une ligne par groupe au format <strong>tag=Nom exact du groupe WhatsApp</strong>. Le « tag » sert à cibler le groupe via la commande « Envoyer dans un groupe additionnel » (champ Titre) et apparaît dans la commande info « Dernier groupe ».}}</small>
										</span>
									</div>
								</div>
<!-- Gestion du groupe (v0.5 #22) -->
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Gestion du groupe}}</label>
									<div class="col-sm-7">
										<div class="input-group" style="margin-bottom:6px;">
											<input type="text" class="form-control" id="grp_participant" placeholder="{{Numéro participant (ex : 33612345678)}}">
											<span class="input-group-btn">
												<button class="btn btn-default grp-action" data-op="add" type="button" title="{{Ajouter au groupe}}"><i class="fas fa-user-plus"></i></button>
												<button class="btn btn-default grp-action" data-op="remove" type="button" title="{{Retirer du groupe}}"><i class="fas fa-user-minus"></i></button>
												<button class="btn btn-default grp-action" data-op="promote" type="button" title="{{Promouvoir admin}}"><i class="fas fa-user-shield"></i></button>
												<button class="btn btn-default grp-action" data-op="demote" type="button" title="{{Rétrograder}}"><i class="fas fa-user"></i></button>
											</span>
										</div>
										<div class="input-group" style="margin-bottom:6px;">
											<input type="text" class="form-control" id="grp_subject" placeholder="{{Nouveau sujet / nom du groupe}}">
											<span class="input-group-btn">
												<button class="btn btn-default" id="grp_set_subject" type="button" title="{{Changer le sujet}}"><i class="fas fa-edit"></i> {{Sujet}}</button>
											</span>
										</div>
										<button class="btn btn-default grp-simple" data-op="inviteLink" type="button"><i class="fas fa-link"></i> {{Lien d'invitation}}</button>
										<button class="btn btn-default grp-simple" data-op="revokeInvite" type="button"><i class="fas fa-unlink"></i> {{Révoquer le lien}}</button>
										<button class="btn btn-danger grp-simple" data-op="leave" type="button" title="{{Quitter le groupe}}"><i class="fas fa-sign-out-alt"></i> {{Quitter}}</button>
										<span class="help-block">
											<small>{{Opérations d'administration sur le groupe canal. Le compte WhatsApp lié doit être <strong>administrateur</strong> du groupe. Le changement d'icône se fait via le bouton « Icône » ci-dessus.}}</small>
										</span>
										<span id="grp_result" class="help-block" style="display:none;"></span>
									</div>
								</div>
<!-- Sauvegarde de session (v0.5 #26) -->
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Sauvegarde de session}}</label>
									<div class="col-sm-7">
										<div class="input-group" style="margin-bottom:6px;">
											<input type="password" class="form-control" id="bk_pass" placeholder="{{Phrase de passe (6 caractères min.)}}" autocomplete="new-password">
											<span class="input-group-btn">
												<button class="btn btn-default" id="bk_backup" type="button" title="{{Télécharger une sauvegarde chiffrée de la session}}"><i class="fas fa-download"></i> {{Sauvegarder}}</button>
											</span>
										</div>
										<div class="input-group">
											<input type="file" class="form-control" id="bk_file" accept=".jwab">
											<span class="input-group-btn">
												<button class="btn btn-warning" id="bk_restore" type="button" title="{{Restaurer la session depuis un fichier}}"><i class="fas fa-upload"></i> {{Restaurer}}</button>
											</span>
										</div>
										<span class="help-block">
											<small>{{Sauvegarde chiffrée (AES-256) de la session WhatsApp pour la restaurer après une réinstallation, <strong>sans re-scanner le QR code</strong>. La même phrase de passe est requise pour restaurer. La restauration écrase la session actuelle (l'ancienne est conservée en <code>.bak</code>) et redémarre le démon.}}</small>
										</span>
										<span id="bk_result" class="help-block" style="display:none;"></span>
									</div>
								</div>
<!-- Messages éphémères (v0.3 #15) -->
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Messages éphémères}}</label>
									<div class="col-sm-7">
										<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ephemeral_duration">
											<option value="0">{{Désactivé (messages permanents)}}</option>
											<option value="86400">{{24 heures}}</option>
											<option value="604800">{{7 jours}}</option>
											<option value="7776000">{{90 jours}}</option>
										</select>
										<span class="help-block">
											<small>{{Si activé, tous les messages envoyés par Jeedom disparaissent automatiquement après la durée choisie (fonction "messages éphémères" de WhatsApp).}}</small>
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
								<div class="col-lg-6">
<!-- Commandes shortcuts (v0.4 #19) -->
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Commandes shortcuts}}</label>
									<div class="col-sm-7">
										<textarea class="eqLogicAttr form-control" rows="3"
											   data-l1key="configuration" data-l2key="interaction_shortcuts"
											   placeholder="/salon=#1234#&#10;/status=Maison : #5678# °C&#10;/scene=#9012#"></textarea>
										<span class="help-block">
											<small>{{Raccourcis rapides déclenchés par un message commençant par « / ». Une ligne par raccourci au format <strong>/déclencheur=cible</strong>. La cible peut être : une commande seule <strong>#id#</strong> (action → exécutée et confirmée ; info → sa valeur est renvoyée), ou un texte modèle contenant des tags <strong>#id#</strong> d'infos et les variables <strong>#args#</strong> (arguments) / <strong>#1#</strong>, <strong>#2#</strong>… (mots d'argument). Prioritaire sur le moteur d'interactions.}}</small>
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
<!-- Reconnaissance utilisateur (v0.4 #21) -->
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Reconnaissance utilisateur}}</label>
									<div class="col-sm-7">
										<textarea class="eqLogicAttr form-control" rows="3"
											   data-l1key="configuration" data-l2key="user_mapping"
											   placeholder="33612345678=Papa&#10;0698765432=Maman&#10;33700000000=Enfant"></textarea>
										<span class="help-block">
											<small>{{Associe un numéro d'expéditeur à un profil Jeedom. Une ligne par correspondance au format <strong>numéro=profil</strong>. Le profil résolu est exposé dans la commande info « Expéditeur — profil » et transmis au moteur d'interactions (compatible avec le plugin Profils). Numéros normalisés au format international.}}</small>
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
<!-- Réponses vocales / TTS (v0.4 #18) -->
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Réponses vocales (TTS)}}</label>
									<div class="col-sm-7">
										<label class="checkbox-inline">
											<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="tts_enabled" value="1"> {{Activer le mode vocal}}
										</label>
										<span class="help-block">
											<small>{{Si activé, les réponses automatiques aux interactions et aux raccourcis sont envoyées sous forme de note vocale synthétisée (Piper). Repli automatique sur le texte si la synthèse échoue. La commande action « Envoyer une note vocale » reste disponible indépendamment de cette case.}}</small>
										</span>
									</div>
								</div>
<!-- Voix TTS optionnelle (v0.4 #18) -->
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Voix de synthèse}}</label>
									<div class="col-sm-7">
										<input type="text" class="eqLogicAttr form-control"
											   data-l1key="configuration" data-l2key="tts_voice"
											   placeholder="fr_FR-siwis-medium.onnx (défaut)">
										<span class="help-block">
											<small>{{Optionnel. Nom d'un modèle de voix Piper présent dans <strong>resources/piper/voices/</strong>, ou chemin absolu d'un fichier <strong>.onnx</strong>. Laisser vide pour utiliser la voix française par défaut.}}</small>
										</span>
									</div>
								</div>
<!-- OCR sur images reçues (v0.4 #20) -->
								<div class="form-group">
									<label class="col-sm-4 control-label">{{OCR images reçues}}</label>
									<div class="col-sm-7">
										<label class="checkbox-inline">
											<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="ocr_enabled" value="1"> {{Activer}}
										</label>
										<input type="text" class="eqLogicAttr form-control" style="margin-top:6px;"
											   data-l1key="configuration" data-l2key="ocr_lang"
											   placeholder="fra (défaut) — ex: fra+eng">
										<span class="help-block">
											<small>{{Si activé, le texte des images reçues est extrait automatiquement via Tesseract et exposé dans la commande info « OCR — texte image » (<code>last_ocr_text</code>). Pratique pour lire un compteur, un ticket, un panneau… Langue Tesseract : <strong>fra</strong> par défaut, combinable (<strong>fra+eng</strong>). Échec silencieux si Tesseract est absent.}}</small>
										</span>
									</div>
								</div>
<!-- STT sur notes vocales reçues (v0.4 #17) -->
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Transcription vocale (STT)}}</label>
									<div class="col-sm-7">
										<label class="checkbox-inline">
											<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="stt_enabled" value="1"> {{Activer}}
										</label>
										<span class="help-block">
											<small>{{Si activé, les notes vocales reçues sont transcrites en texte (Vosk, hors-ligne) et exposées dans la commande info « STT — note vocale » (<code>last_voice_text</code>). Le texte est aussi réinjecté comme un message : il déclenche les raccourcis et les interactions Jeedom — vous pouvez piloter Jeedom à la voix. Combiné au mode « Réponses vocales », vous obtenez un assistant vocal complet.}}</small>
										</span>
									</div>
								</div>
								</div>
							</div>
						<!-- /.section Paramètres WhatsApp -->

					</fieldset>
				</form>

				<!-- Modal agrandissement QR code -->
				<div class="modal fade" id="modal_qrZoom" tabindex="-1" role="dialog" aria-labelledby="modal_qrZoom_label">
					<div class="modal-dialog modal-sm" role="document" style="max-width:340px;margin:60px auto;">
						<div class="modal-content" style="border:3px solid #25D366;border-radius:12px;">
							<div class="modal-header" style="background:#25D366;color:#fff;border-radius:9px 9px 0 0;padding:10px 16px;">
								<button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1;"><span>&times;</span></button>
								<h5 class="modal-title" id="modal_qrZoom_label" style="color:#fff;margin:0;">
									<i class="fab fa-whatsapp"></i> {{Scanner le QR code}}
								</h5>
							</div>
							<div class="modal-body" style="text-align:center;padding:20px;">
								<img id="wa_qr_img_zoom" src="" style="width:100%;max-width:280px;border:3px solid #25D366;border-radius:10px;">
								<p class="text-muted" style="font-size:0.82em;margin-top:12px;margin-bottom:0;">
									<i class="fas fa-mobile-alt"></i>
									{{WhatsApp → Appareils liés → Lier un appareil}}
								</p>
							</div>
						</div>
					</div>
				</div>

				<!-- Modal confirmation déconnexion -->
				<div class="modal fade" id="modal_logoutWa" tabindex="-1" role="dialog" aria-labelledby="modal_logoutWa_label">
					<div class="modal-dialog" role="document" style="max-width:480px;margin:60px auto;">
						<div class="modal-content" style="border:2px solid #d9534f;border-radius:10px;">
							<div class="modal-header" style="background:#d9534f;color:#fff;border-radius:8px 8px 0 0;padding:12px 16px;">
								<button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1;"><span>&times;</span></button>
								<h4 class="modal-title" id="modal_logoutWa_label" style="color:#fff;margin:0;">
									<i class="fas fa-exclamation-triangle"></i> {{Déconnecter le compte WhatsApp ?}}
								</h4>
							</div>
							<div class="modal-body" style="padding:18px 20px;">
								<p style="font-size:1.02em;margin-bottom:12px;">{{Cette action est irréversible et va :}}</p>
								<ul style="line-height:1.7;">
									<li><i class="fas fa-unlink" style="color:#d9534f;width:18px;"></i> {{délier l'appareil côté WhatsApp (équivalent « Se déconnecter » depuis Appareils liés sur le téléphone)}}</li>
									<li><i class="fas fa-eraser" style="color:#d9534f;width:18px;"></i> {{supprimer définitivement les identifiants stockés localement (dossier auth/)}}</li>
									<li><i class="fas fa-broom" style="color:#d9534f;width:18px;"></i> {{réinitialiser le groupe lié et les commandes info (dernier message, statut, compteurs)}}</li>
								</ul>
								<p style="margin-top:12px;">
									<i class="fas fa-qrcode" style="color:#25D366;"></i>
									{{Pour vous reconnecter ensuite, il faudra scanner un nouveau QR code.}}
								</p>
								<p class="text-muted" style="font-size:0.85em;margin-top:10px;margin-bottom:0;">
									<i class="fas fa-lightbulb"></i> {{Astuce : pour pouvoir restaurer ce compte plus tard sans rescanner, faites d'abord une « Sauvegarde de session » (Paramètres avancés).}}
								</p>
							</div>
							<div class="modal-footer" style="padding:10px 16px;">
								<button type="button" class="btn btn-default" data-dismiss="modal">
									<i class="fas fa-times"></i> {{Annuler}}
								</button>
								<button type="button" class="btn btn-danger" id="btn_logout_confirm">
									<i class="fas fa-power-off"></i> {{Oui, déconnecter}}
								</button>
							</div>
						</div>
					</div>
				</div>

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
								value="Test depuis JeeWhatsApp">
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

			<!-- ── Onglet Templates (#29) ────────────────────────────────────── -->
			<div role="tabpanel" class="tab-pane" id="templatestab">
				<div style="max-width:700px;margin:24px auto;">

					<div style="background:#25D366;color:#fff;padding:13px 20px;border-radius:8px 8px 0 0;display:flex;align-items:center;gap:10px;">
						<i class="fas fa-bookmark" style="font-size:1.4em;"></i>
						<span style="font-size:1.05em;font-weight:600;">{{Messages templates}}</span>
					</div>

					<div style="border:1px solid #ddd;border-top:none;border-radius:0 0 8px 8px;padding:22px 24px;background:#fff;">

						<p style="color:#555;margin-bottom:14px;font-size:0.9em;">
							<i class="fas fa-info-circle" style="color:#2196f3;margin-right:5px;"></i>
							{{Définissez ici des messages réutilisables. Une ligne par template au format}} <code>clé=Texte du message</code>.<br>
							{{Dans un scénario, utilisez la commande}} <strong>{{Envoyer un template}}</strong> {{et saisissez la clé dans le champ "Message".}}<br>
							{{Les tags Jeedom}} <code>#[Objet][Équipement][Commande]#</code> {{sont résolus automatiquement à l'envoi.}}
						</p>

						<div class="form-group">
							<label style="font-weight:600;margin-bottom:6px;display:block;">
								<i class="fas fa-list-ul" style="color:#25D366;margin-right:6px;"></i>
								{{Templates}}
							</label>
							<textarea class="eqLogicAttr form-control" rows="10"
								data-l1key="configuration" data-l2key="message_templates"
								placeholder="bienvenue=Bienvenue chez vous !&#10;alerte=Alerte : #[Maison][Detecteur][Presence]# !&#10;nuit=Bonne nuit - fermeture automatique des volets&#10;# Les lignes commencant par # sont des commentaires"></textarea>
							<span class="help-block" style="font-size:0.82em;margin-top:6px;">
								<strong>{{Format :}}</strong> <code>clé=texte</code> — {{une ligne par template, clé insensible à la casse}}<br>
								{{Les lignes vides et celles commençant par}} <code>#</code> {{sont ignorées.}}
							</span>
						</div>

						<!-- Aperçu dynamique -->
						<div style="border-top:1px solid #f0f0f0;margin:16px 0 14px;"></div>
						<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
							<button class="btn btn-default btn-sm" id="btn_preview_templates" type="button">
								<i class="fas fa-eye"></i> {{Aperçu des templates}}
							</button>
							<span class="text-muted" style="font-size:0.85em;">{{(sans sauvegarder)}}</span>
						</div>
						<div id="templates_preview" style="display:none;margin-top:14px;"></div>

					</div>
				</div>
			</div><!-- /#templatestab -->

		<!-- ── Onglet Statistiques (#30) ────────────────────────────────────── -->
		<div role="tabpanel" class="tab-pane" id="statstab">
			<div style="max-width:800px;margin:24px auto 32px;">

				<!-- Cards totaux -->
				<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
					<div class="jwa-stat-card" style="flex:1;min-width:140px;background:#e8f5e9;border:1px solid #a5d6a7;">
						<div style="font-size:2em;font-weight:900;color:#2e7d32;" id="stat_total_sent">—</div>
						<div style="font-size:0.82em;color:#388e3c;margin-top:2px;"><i class="fas fa-paper-plane"></i> {{Messages envoyés (30j)}}</div>
					</div>
					<div class="jwa-stat-card" style="flex:1;min-width:140px;background:#e3f2fd;border:1px solid #90caf9;">
						<div style="font-size:2em;font-weight:900;color:#1565c0;" id="stat_total_received">—</div>
						<div style="font-size:0.82em;color:#1976d2;margin-top:2px;"><i class="fas fa-inbox"></i> {{Messages reçus (30j)}}</div>
					</div>
					<div class="jwa-stat-card" style="flex:1;min-width:140px;background:#f3e5f5;border:1px solid #ce93d8;">
						<div style="font-size:2em;font-weight:900;color:#6a1b9a;" id="stat_total_all">—</div>
						<div style="font-size:0.82em;color:#7b1fa2;margin-top:2px;"><i class="fas fa-comments"></i> {{Total (30j)}}</div>
					</div>
				</div>

				<!-- Graphique barres 30 jours -->
				<div style="background:#fff;border:1px solid #e8e8e8;border-radius:10px;padding:18px 20px;margin-bottom:20px;">
					<div style="font-weight:700;font-size:0.95em;margin-bottom:14px;color:#333;">
						<i class="fas fa-chart-bar" style="color:#25D366;margin-right:6px;"></i>
						{{Activité sur 30 jours}}
					</div>
					<div id="stat_chart" style="display:flex;align-items:flex-end;gap:3px;height:100px;overflow-x:auto;padding-bottom:4px;">
						<div class="text-muted" style="font-size:0.85em;margin:auto;">{{Chargement…}}</div>
					</div>
					<div style="display:flex;gap:16px;margin-top:8px;font-size:0.78em;color:#888;">
						<span><span style="display:inline-block;width:10px;height:10px;background:#25D366;border-radius:2px;margin-right:4px;"></span>{{Envoyés}}</span>
						<span><span style="display:inline-block;width:10px;height:10px;background:#1976d2;border-radius:2px;margin-right:4px;"></span>{{Reçus}}</span>
					</div>
				</div>

				<!-- Top contacts -->
				<div style="background:#fff;border:1px solid #e8e8e8;border-radius:10px;padding:18px 20px;">
					<div style="font-weight:700;font-size:0.95em;margin-bottom:12px;color:#333;">
						<i class="fas fa-users" style="color:#25D366;margin-right:6px;"></i>
						{{Top expéditeurs}}
					</div>
					<div id="stat_contacts">
						<p class="text-muted" style="font-size:0.85em;">{{Chargement…}}</p>
					</div>
				</div>

				<div style="text-align:right;margin-top:10px;">
					<button class="btn btn-default btn-sm" id="btn_refresh_stats">
						<i class="fas fa-sync-alt"></i> {{Actualiser}}
					</button>
				</div>

			</div>
		</div><!-- /#statstab -->

		<!-- ── Onglet Live (#31) ─────────────────────────────────────────────── -->
		<div role="tabpanel" class="tab-pane" id="livetab">
			<div style="max-width:800px;margin:20px auto;">

				<!-- Toolbar -->
				<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
					<span style="font-weight:700;font-size:0.9em;">
						<i class="fas fa-satellite-dish" style="color:#25D366;"></i>
						{{Flux temps réel}}
					</span>
					<span id="live_status_badge" class="label label-default" style="font-size:0.78em;">
						<i class="fas fa-pause"></i> {{En pause}}
					</span>
					<div style="margin-left:auto;display:flex;gap:6px;">
						<button class="btn btn-success btn-sm" id="btn_live_start">
							<i class="fas fa-play"></i> {{Démarrer}}
						</button>
						<button class="btn btn-default btn-sm" id="btn_live_pause" style="display:none;">
							<i class="fas fa-pause"></i> {{Pause}}
						</button>
						<button class="btn btn-default btn-sm" id="btn_live_clear">
							<i class="fas fa-trash-alt"></i> {{Vider}}
						</button>
					</div>
				</div>

				<!-- Légende -->
				<div style="display:flex;gap:14px;margin-bottom:8px;font-size:0.78em;color:#666;flex-wrap:wrap;">
					<span><span style="display:inline-block;width:8px;height:8px;background:#25D366;border-radius:50%;margin-right:4px;"></span>{{Reçu}}</span>
					<span><span style="display:inline-block;width:8px;height:8px;background:#1976d2;border-radius:50%;margin-right:4px;"></span>{{Envoyé}}</span>
					<span><span style="display:inline-block;width:8px;height=8px;background:#888;border-radius:50%;margin-right:4px;"></span>{{Système}}</span>
					<span><span style="display:inline-block;width:8px;height:8px;background:#f57c00;border-radius:50%;margin-right:4px;"></span>{{Avertissement}}</span>
					<span><span style="display:inline-block;width:8px;height:8px;background:#c62828;border-radius:50%;margin-right:4px;"></span>{{Erreur}}</span>
				</div>

				<!-- Flux -->
				<div id="live_feed"
				     style="background:#1e1e1e;border-radius:8px;padding:12px 16px;
				            height:380px;overflow-y:auto;font-family:monospace;font-size:0.82em;
				            line-height:1.6;color:#d4d4d4;">
					<div class="text-muted" style="color:#666;font-style:italic;">
						{{Cliquez sur « Démarrer » pour commencer à recevoir les événements en temps réel.}}
					</div>
				</div>

				<div style="display:flex;align-items:center;justify-content:space-between;margin-top:6px;">
					<small class="text-muted">{{Rafraîchissement automatique toutes les 2 secondes — 100 derniers événements conservés}}</small>
					<span id="live_event_count" style="font-size:0.8em;color:#888;"></span>
				</div>

			</div>
		</div><!-- /#livetab -->

		</div><!-- /.tab-content -->
	</div><!-- /.eqLogic -->

</div><!-- /.row -->

<style>
.jwa-stat-card {
  border-radius:10px;padding:14px 18px;
}
</style>

<script>
var _waQRInterval  = null;
var _liveInterval  = null;
var _liveLastTs    = 0;
var _liveRunning   = false;
var _liveCount     = 0;

// ── Bouton don ──────────────────────────────────────────────────────────────
$('#bt_donJeeWhatsApp').on('click', function () {
  $('#modal_donJeeWhatsApp').modal('show');
});

// ── Statistiques (#30) ──────────────────────────────────────────────────────
function loadStats() {
  var eqLogic_id = $('input.eqLogicAttr[data-l1key="id"]').val();
  if (!eqLogic_id) { return; }
  $.ajax({
    type: 'POST',
    url:  'plugins/jeewhatsapp/core/ajax/jeewhatsapp.ajax.php',
    data: { action: 'getStats', eqLogic_id: eqLogic_id },
    dataType: 'json',
    success: function (data) {
      if (data.state !== 'ok') { return; }
      var r = data.result;
      $('#stat_total_sent').text(r.total_sent || 0);
      $('#stat_total_received').text(r.total_received || 0);
      $('#stat_total_all').text((r.total_sent || 0) + (r.total_received || 0));

      // Graphique barres CSS
      var days = r.days || [];
      var maxVal = 1;
      days.forEach(function (d) { maxVal = Math.max(maxVal, (d.s || 0) + (d.r || 0)); });
      var html = '';
      days.forEach(function (d) {
        var sent = d.s || 0;
        var recv = d.r || 0;
        var total = sent + recv;
        var hS = Math.round((sent / maxVal) * 90);
        var hR = Math.round((recv / maxVal) * 90);
        var label = d.d ? d.d.substring(5) : ''; // MM-DD
        html += '<div title="' + d.d + ' — ' + sent + ' envoyés, ' + recv + ' reçus"'
              + '     style="display:flex;flex-direction:column;align-items:center;gap:1px;min-width:18px;flex:1;cursor:default;">'
              + (sent > 0 ? '<div style="background:#25D366;width:100%;height:' + hS + 'px;border-radius:2px 2px 0 0;"></div>' : '<div style="height:' + hS + 'px;"></div>')
              + (recv > 0 ? '<div style="background:#1976d2;width:100%;height:' + hR + 'px;border-radius:2px 2px 0 0;"></div>' : '<div style="height:' + hR + 'px;"></div>')
              + '<div style="font-size:8px;color:#888;transform:rotate(-45deg);transform-origin:top right;margin-top:2px;white-space:nowrap;">'
              + (total > 0 ? label : '') + '</div>'
              + '</div>';
      });
      $('#stat_chart').html(html || '<div class="text-muted" style="font-size:0.85em;margin:auto;">{{Aucune donnée}}</div>');

      // Top contacts
      var contacts = r.top_contacts || {};
      var cKeys = Object.keys(contacts);
      if (cKeys.length === 0) {
        $('#stat_contacts').html('<p class="text-muted" style="font-size:0.85em;">{{Aucun message reçu}}</p>');
      } else {
        var maxC = 0;
        cKeys.forEach(function (k) { maxC = Math.max(maxC, contacts[k].n || 0); });
        var cHtml = '<table class="table" style="margin:0;"><tbody>';
        cKeys.forEach(function (k) {
          var label = contacts[k].l || k;
          var cnt   = contacts[k].n || 0;
          var pct   = Math.round((cnt / maxC) * 100);
          cHtml += '<tr>'
                 + '<td style="width:40%;padding:6px 8px;font-size:0.88em;">' + $('<div>').text(label).html() + '</td>'
                 + '<td style="padding:6px 8px;vertical-align:middle;">'
                 + '<div style="background:#e0e0e0;border-radius:4px;height:10px;"><div style="background:#25D366;width:' + pct + '%;height:10px;border-radius:4px;"></div></div>'
                 + '</td>'
                 + '<td style="width:40px;padding:6px 8px;font-size:0.88em;text-align:right;font-weight:700;">' + cnt + '</td>'
                 + '</tr>';
        });
        cHtml += '</tbody></table>';
        $('#stat_contacts').html(cHtml);
      }
    }
  });
}

// Charger les stats quand l'onglet Stats devient visible
$('a[href="#statstab"]').on('shown.bs.tab', function () { loadStats(); });
$('#btn_refresh_stats').on('click', function () { loadStats(); });

// ── Mode debug live (#31) ────────────────────────────────────────────────────
var liveTypeColors = {
  'in':   '#4caf50',
  'out':  '#42a5f5',
  'sys':  '#9e9e9e',
  'warn': '#ff9800',
  'err':  '#ef5350',
};
var liveTypeIcons = {
  'in':   'fa-arrow-down',
  'out':  'fa-arrow-up',
  'sys':  'fa-info-circle',
  'warn': 'fa-exclamation-triangle',
  'err':  'fa-times-circle',
};

function formatLiveTs(isoTs) {
  try {
    var d = new Date(isoTs);
    return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2);
  } catch (e) { return isoTs || ''; }
}

function appendLiveEvents(events) {
  var $feed   = $('#live_feed');
  var atBottom = ($feed[0].scrollHeight - $feed.scrollTop() - $feed.outerHeight()) < 40;
  // Supprimer le message "en attente"
  $feed.find('.live-hint').remove();

  events.forEach(function (ev) {
    var ts   = new Date(ev.ts).getTime();
    if (ts <= _liveLastTs) { return; }
    _liveLastTs = ts;
    _liveCount++;

    var col  = liveTypeColors[ev.type] || '#9e9e9e';
    var ico  = liveTypeIcons[ev.type]  || 'fa-circle';
    var time = formatLiveTs(ev.ts);
    var text = String(ev.text || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    $feed.append(
      '<div style="margin-bottom:3px;">'
      + '<span style="color:#888;font-size:0.85em;margin-right:8px;">' + time + '</span>'
      + '<i class="fas ' + ico + '" style="color:' + col + ';margin-right:6px;font-size:0.78em;"></i>'
      + '<span style="color:' + col + ';">' + text + '</span>'
      + '</div>'
    );
  });

  if (atBottom) { $feed[0].scrollTop = $feed[0].scrollHeight; }
  if (_liveCount > 0) { $('#live_event_count').text(_liveCount + ' {{événement(s)}}'); }
}

function fetchLiveEvents() {
  var eqLogic_id = $('input.eqLogicAttr[data-l1key="id"]').val();
  if (!eqLogic_id || !_liveRunning) { return; }
  $.ajax({
    type: 'POST',
    url:  'plugins/jeewhatsapp/core/ajax/jeewhatsapp.ajax.php',
    data: { action: 'getLiveEvents', eqLogic_id: eqLogic_id, since: _liveLastTs },
    dataType: 'json',
    success: function (data) {
      if (data.state === 'ok' && Array.isArray(data.result)) {
        appendLiveEvents(data.result);
      }
    }
  });
}

$('#btn_live_start').on('click', function () {
  _liveRunning = true;
  $('#btn_live_start').hide();
  $('#btn_live_pause').show();
  $('#live_status_badge').removeClass('label-default').addClass('label-success')
    .html('<i class="fas fa-satellite-dish fa-pulse"></i> {{En direct}}');
  // Charger tous les événements existants
  _liveLastTs = 0;
  fetchLiveEvents();
  if (_liveInterval) { clearInterval(_liveInterval); }
  _liveInterval = setInterval(fetchLiveEvents, 2000);
});

$('#btn_live_pause').on('click', function () {
  _liveRunning = false;
  if (_liveInterval) { clearInterval(_liveInterval); _liveInterval = null; }
  $('#btn_live_start').show();
  $('#btn_live_pause').hide();
  $('#live_status_badge').removeClass('label-success').addClass('label-default')
    .html('<i class="fas fa-pause"></i> {{En pause}}');
});

$('#btn_live_clear').on('click', function () {
  $('#live_feed').html('<div class="live-hint" style="color:#666;font-style:italic;">{{Flux vidé — cliquez sur « Démarrer » pour continuer.}}</div>');
  _liveLastTs = 0;
  _liveCount  = 0;
  $('#live_event_count').text('');
});

// Arrêter le live quand l'équipement se ferme
document.addEventListener('visibilitychange', function () {
  if (document.hidden && _liveRunning) {
    $('#btn_live_pause').trigger('click');
  }
});

// ── Aperçu templates (#29) ──────────────────────────────────────────────────
$('#btn_preview_templates').on('click', function () {
  var raw = $('textarea[data-l2key="message_templates"]').val() || '';
  var lines = raw.split('\n');
  var html = '';
  var count = 0;
  lines.forEach(function (line) {
    line = line.trim();
    if (line === '' || line[0] === '#') { return; }
    var eq = line.indexOf('=');
    if (eq === -1) { return; }
    var key  = line.substring(0, eq).trim();
    var text = line.substring(eq + 1).trim();
    if (key === '' || text === '') { return; }
    html += '<div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:8px;">'
          + '<code style="min-width:120px;background:#e8f5e9;color:#2e7d32;padding:3px 7px;border-radius:4px;font-size:0.88em;flex-shrink:0;">' + $('<div>').text(key).html() + '</code>'
          + '<span style="color:#333;word-break:break-word;">' + $('<div>').text(text).html() + '</span>'
          + '</div>';
    count++;
  });
  var $box = $('#templates_preview');
  if (count === 0) {
    $box.html('<p class="text-muted" style="font-size:0.9em;margin:0;">{{Aucun template valide trouvé.}}</p>').show();
  } else {
    $box.html('<p style="font-size:0.85em;color:#888;margin-bottom:10px;">{{' + count + ' template(s) défini(s) :}}</p>' + html).show();
  }
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

// ── Déconnexion du compte WhatsApp ───────────────────────────────────────────
$('#btn_logout_wa').on('click', function () {
  if (!$('input.eqLogicAttr[data-l1key="id"]').val()) {
    $.fn.showAlert({ message: '{{Sauvegardez l\'équipement d\'abord}}', level: 'warning' });
    return;
  }
  $('#modal_logoutWa').modal('show');
});

$('#btn_logout_confirm').on('click', function () {
  var eqLogic_id = $('input.eqLogicAttr[data-l1key="id"]').val();
  if (!eqLogic_id) { return; }
  var $btn = $(this);
  $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{Déconnexion…}}');
  $.ajax({
    type: 'POST',
    url:  'plugins/jeewhatsapp/core/ajax/jeewhatsapp.ajax.php',
    data: { action: 'logout', eqLogic_id: eqLogic_id },
    dataType: 'json',
    success: function (data) {
      $btn.prop('disabled', false).html('<i class="fas fa-power-off"></i> {{Oui, déconnecter}}');
      $('#modal_logoutWa').modal('hide');
      if (data.state === 'ok') {
        $.fn.showAlert({ message: '{{Compte WhatsApp déconnecté. Les identifiants locaux ont été supprimés — scannez un nouveau QR code pour reconnecter.}}', level: 'success' });
        $('#btn_logout_wa').hide();
        refreshQRStatus();
      } else {
        $.fn.showAlert({ message: '{{Erreur lors de la déconnexion : }}' + (data.result || data.error || '?'), level: 'danger' });
      }
    },
    error: function () {
      $btn.prop('disabled', false).html('<i class="fas fa-power-off"></i> {{Oui, déconnecter}}');
      $.fn.showAlert({ message: '{{Erreur de communication avec le daemon}}', level: 'danger' });
    }
  });
});

function refreshQRStatus() {
  var eqLogic_id = $('input.eqLogicAttr[data-l1key="id"]').val();
  if (!eqLogic_id) { return; }

  $.ajax({
    type: 'POST',
    url:  'plugins/jeewhatsapp/core/ajax/jeewhatsapp.ajax.php',
    data: { action: 'getQR', eqLogic_id: eqLogic_id },
    dataType: 'json',
    success: function (data) {
      if (data.state !== 'ok') {
        showStatus('error', '{{Erreur daemon — vérifiez l\'onglet Analyse → Logs → jeewhatsapp}}');
        return;
      }
      var r = data.result;
      applyStatus(r);
    },
    error: function () {
      showStatus('error', '{{Impossible de joindre le daemon — relancez-le via Plugins → JeeWhatsApp → Démarrer le daemon}}');
    }
  });
}

function applyStatus(r) {
  $('#wa_qr_zone').hide();
  $('#wa_connected_zone').hide();
  $('#wa_disconnected_zone').hide();

  // Bouton Déconnexion : visible uniquement quand un compte est lié (connecté)
  if (r && r.status === 'connected') {
    $('#btn_logout_wa').show();
  } else {
    $('#btn_logout_wa').hide();
  }

  if (r && r.qr) {
    $('#wa_qr_img').attr('src', r.qr);
    // Synchronise le QR dans le modal de zoom
    $('#wa_qr_img_zoom').attr('src', r.qr);
    $('#wa_qr_zone').show();
    showStatus('warning', '{{En attente du scan QR…}}');
  } else if (r && r.status === 'connected') {
    $('#wa_connected_zone').show();
    showStatus('success', '{{Connecté}}');
    if (_waQRInterval) { clearInterval(_waQRInterval); _waQRInterval = null; }
  } else if (r && r.status === 'logged_out') {
    $('#wa_disconnected_zone').show();
    showStatus('danger', '{{Session expirée — redémarrez le daemon pour obtenir un nouveau QR}}');
  } else if (r && r.status === 'unknown') {
    showStatus('warning', '{{Instance non démarrée — redémarrez le daemon pour inclure cet équipement}}');
  } else {
    var statusMap = {
      'connecting':   '{{Connexion en cours… (QR dans quelques secondes)}}',
      'reconnecting': '{{Reconnexion en cours…}}',
    };
    showStatus('info', statusMap[r && r.status] || '{{Connexion en cours… (QR dans quelques secondes)}}');
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

// ── Définition de l'icône du groupe (icône du plugin) ───────────────────────
$('#btn_set_group_icon').on('click', function () {
  var eqLogic_id = $('input.eqLogicAttr[data-l1key="id"]').val();
  if (!eqLogic_id) {
    $('#group_link_result').text('{{Sauvegardez l\'équipement d\'abord}}').css('color', 'red').show();
    return;
  }
  var $btn    = $(this);
  var $result = $('#group_link_result');
  $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{Icône…}}');
  $result.hide();

  $.ajax({
    type:     'POST',
    url:      'plugins/jeewhatsapp/core/ajax/jeewhatsapp.ajax.php',
    data:     { action: 'setGroupIcon', eqLogic_id: eqLogic_id },
    dataType: 'json',
    success: function (data) {
      $btn.prop('disabled', false).html('<i class="fas fa-image"></i> {{Icône}}');
      if (data.state === 'ok') {
        $result.empty()
          .append($('<i>').addClass('fas fa-check-circle').css('color', '#25D366'))
          .append(' {{Icône du groupe mise à jour}}')
          .css('color', '#25D366').show();
      } else {
        $result.text('{{Erreur : }}' + (data.result || data.error || '?')).css('color', 'red').show();
      }
    },
    error: function () {
      $btn.prop('disabled', false).html('<i class="fas fa-image"></i> {{Icône}}');
      $result.text('{{Erreur de communication avec le daemon}}').css('color', 'red').show();
    }
  });
});

// ── Gestion du groupe (v0.5 #22) ─────────────────────────────────────────────
function doGroupAction(op, value, $btn) {
  var eqLogic_id = $('input.eqLogicAttr[data-l1key="id"]').val();
  var $result = $('#grp_result');
  if (!eqLogic_id) {
    $result.text('{{Sauvegardez l\'équipement d\'abord}}').css('color', 'red').show();
    return;
  }
  var prev = $btn ? $btn.html() : null;
  if ($btn) { $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>'); }
  $result.hide();
  $.ajax({
    type:     'POST',
    url:      'plugins/jeewhatsapp/core/ajax/jeewhatsapp.ajax.php',
    data:     { action: 'groupAction', eqLogic_id: eqLogic_id, op: op, value: value || '' },
    dataType: 'json',
    success: function (data) {
      if ($btn && prev !== null) { $btn.prop('disabled', false).html(prev); }
      if (data.state === 'ok') {
        var r = data.result || {};
        if (r.link) {
          $result.empty()
            .append($('<i>').addClass('fas fa-check-circle').css('color', '#25D366'))
            .append(' ')
            .append($('<a>').attr('href', r.link).attr('target', '_blank').text(r.link))
            .css('color', '#25D366').show();
        } else {
          $result.empty()
            .append($('<i>').addClass('fas fa-check-circle').css('color', '#25D366'))
            .append(' {{Opération }}' + op + ' {{effectuée}}')
            .css('color', '#25D366').show();
        }
      } else {
        $result.text('{{Erreur : }}' + (data.result || data.error || '?')).css('color', 'red').show();
      }
    },
    error: function () {
      if ($btn && prev !== null) { $btn.prop('disabled', false).html(prev); }
      $result.text('{{Erreur de communication avec le daemon}}').css('color', 'red').show();
    }
  });
}

$('.grp-action').on('click', function () {
  doGroupAction($(this).data('op'), $('#grp_participant').val().trim(), $(this));
});
$('#grp_set_subject').on('click', function () {
  doGroupAction('subject', $('#grp_subject').val().trim(), $(this));
});
$('.grp-simple').on('click', function () {
  var op = $(this).data('op');
  if (op === 'leave' && !confirm('{{Quitter ce groupe WhatsApp ? Cette action est irréversible.}}')) { return; }
  doGroupAction(op, '', $(this));
});

// ── Sauvegarde / restauration de session (v0.5 #26) ──────────────────────────
$('#bk_backup').on('click', function () {
  var eqLogic_id = $('input.eqLogicAttr[data-l1key="id"]').val();
  var $result = $('#bk_result');
  if (!eqLogic_id) { $result.text('{{Sauvegardez l\'équipement d\'abord}}').css('color', 'red').show(); return; }
  var pass = $('#bk_pass').val() || '';
  if (pass.length < 6) { $result.text('{{Phrase de passe : 6 caractères minimum}}').css('color', 'red').show(); return; }
  var $btn = $(this); $btn.prop('disabled', true);
  $result.hide();
  var fd = new FormData();
  fd.append('action', 'backupSession');
  fd.append('eqLogic_id', eqLogic_id);
  fd.append('passphrase', pass);
  fetch('plugins/jeewhatsapp/core/ajax/jeewhatsapp.ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) {
      var ct = r.headers.get('Content-Type') || '';
      if (ct.indexOf('application/json') !== -1) { return r.json().then(function (d) { throw new Error(d.result || d.error || 'erreur'); }); }
      return r.blob();
    })
    .then(function (blob) {
      var url = URL.createObjectURL(blob);
      var now = new Date();
      var ts = now.getFullYear()
        + ('0' + (now.getMonth() + 1)).slice(-2)
        + ('0' + now.getDate()).slice(-2)
        + '-' + ('0' + now.getHours()).slice(-2)
        + ('0' + now.getMinutes()).slice(-2);
      var a = document.createElement('a');
      a.href = url; a.download = 'jeewhatsapp-session-' + eqLogic_id + '-' + ts + '.jwab';
      document.body.appendChild(a);
      a.dispatchEvent(new MouseEvent('click', { bubbles: false, cancelable: true }));
      a.remove(); URL.revokeObjectURL(url);
      $btn.prop('disabled', false);
      $result.empty().append($('<i>').addClass('fas fa-check-circle').css('color', '#25D366'))
        .append(' {{Sauvegarde téléchargée — conservez le fichier et la phrase de passe en lieu sûr}}')
        .css('color', '#25D366').show();
    })
    .catch(function (e) { $btn.prop('disabled', false); $result.text('{{Erreur : }}' + e.message).css('color', 'red').show(); });
});

$('#bk_restore').on('click', function () {
  var eqLogic_id = $('input.eqLogicAttr[data-l1key="id"]').val();
  var $result = $('#bk_result');
  if (!eqLogic_id) { $result.text('{{Sauvegardez l\'équipement d\'abord}}').css('color', 'red').show(); return; }
  var pass = $('#bk_pass').val() || '';
  var file = ($('#bk_file')[0].files || [])[0];
  if (pass.length < 6) { $result.text('{{Phrase de passe : 6 caractères minimum}}').css('color', 'red').show(); return; }
  if (!file) { $result.text('{{Sélectionnez un fichier de sauvegarde (.jwab)}}').css('color', 'red').show(); return; }
  if (!confirm('{{Restaurer cette session écrasera la session actuelle et redémarrera le démon. Continuer ?}}')) { return; }
  var $btn = $(this); $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{Restauration…}}');
  $result.hide();
  var fd = new FormData();
  fd.append('action', 'restoreSession');
  fd.append('eqLogic_id', eqLogic_id);
  fd.append('passphrase', pass);
  fd.append('session', file, file.name);
  $.ajax({
    type: 'POST', url: 'plugins/jeewhatsapp/core/ajax/jeewhatsapp.ajax.php',
    data: fd, processData: false, contentType: false, dataType: 'json',
    success: function (data) {
      $btn.prop('disabled', false).html('<i class="fas fa-upload"></i> {{Restaurer}}');
      if (data.state === 'ok') {
        $result.empty().append($('<i>').addClass('fas fa-check-circle').css('color', '#25D366'))
          .append(' {{Session restaurée — relancez le démon depuis Plugins → Gestion des démons, puis vérifiez le statut de connexion.}}')
          .css('color', '#25D366').show();
      } else {
        $result.text('{{Erreur : }}' + (data.result || data.error || '?')).css('color', 'red').show();
      }
    },
    error: function () { $btn.prop('disabled', false).html('<i class="fas fa-upload"></i> {{Restaurer}}'); $result.text('{{Erreur de communication}}').css('color', 'red').show(); }
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
