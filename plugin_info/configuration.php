<?php
/* This file is part of Jeedom.
 * Plugin JeeWhatsApp - Aldarande
 * Licence AGPL v3 — https://www.gnu.org/licenses/agpl-3.0.html
 */
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) { throw new Exception('{{401 - Accès non autorisé}}'); }

// URL de base Jeedom pour afficher l'URL du webhook
$jeedom_port = config::byKey('port', 'network', 80);
$jeedom_comp = config::byKey('urlcomplement', 'network', '');
$webhook_url = 'http://<ip-jeedom>:' . $jeedom_port . $jeedom_comp
             . '/plugins/jeewhatsapp/core/php/webhook.php';
$webhook_token = config::byKey('webhook_token', 'jeewhatsapp', '');
?>

<form class="form-horizontal">
  <fieldset>

    <!-- ── Daemon ──────────────────────────────────────────────────────────── -->
    <legend><i class="fas fa-cog"></i> {{Daemon}}</legend>
    <div class="form-group">
      <label class="col-lg-4 control-label">{{Port du daemon}}</label>
      <div class="col-lg-4">
        <input class="configKey form-control" data-l1key="socketport"
               type="number" min="1024" max="65535"
               placeholder="<?php echo jeewhatsapp::DAEMON_PORT_DEFAULT; ?>" />
        <span class="help-block"><small>{{Port HTTP local du daemon Baileys (défaut : <?php echo jeewhatsapp::DAEMON_PORT_DEFAULT; ?>). Ne modifier que si ce port est déjà utilisé.}}</small></span>
      </div>
    </div>

    <!-- ── Webhook REST (#33) ──────────────────────────────────────────────── -->
    <legend style="margin-top:20px;">
      <i class="fas fa-plug"></i> {{Webhook REST}}
      <span class="label label-info" style="font-size:0.65em;vertical-align:middle;margin-left:8px;">n8n · Node-RED · scripts</span>
    </legend>

    <div class="form-group">
      <label class="col-lg-4 control-label">{{Token d'accès}}</label>
      <div class="col-lg-7">
        <?php if ($webhook_token !== '') : ?>
          <div class="input-group" style="margin-bottom:8px;">
            <input type="text" class="form-control" id="in_webhook_token"
                   value="<?php echo htmlspecialchars($webhook_token, ENT_QUOTES, 'UTF-8'); ?>"
                   readonly style="font-family:monospace;font-size:0.88em;background:#f5f5f5;">
            <span class="input-group-btn">
              <button class="btn btn-default" type="button" id="btn_copy_webhook_token"
                      title="{{Copier le token}}">
                <i class="fas fa-copy"></i>
              </button>
            </span>
          </div>
        <?php else : ?>
          <p class="text-muted" style="margin-bottom:8px;">
            <i class="fas fa-info-circle"></i>
            {{Aucun token généré. Cliquez sur le bouton ci-dessous pour activer le webhook.}}
          </p>
        <?php endif; ?>
        <button class="btn btn-<?php echo $webhook_token !== '' ? 'default' : 'success'; ?>"
                type="button" id="btn_gen_webhook_token">
          <i class="fas fa-key"></i>
          <?php echo $webhook_token !== '' ? '{{Regénérer (révoque l\'ancien)}}' : '{{Générer un token webhook}}'; ?>
        </button>
        <?php if ($webhook_token !== '') : ?>
          <button class="btn btn-danger btn-xs" type="button" id="btn_revoke_webhook_token"
                  style="margin-left:6px;" title="{{Révoquer le webhook (désactiver)}}">
            <i class="fas fa-trash-alt"></i> {{Révoquer}}
          </button>
        <?php endif; ?>
        <span class="help-block" style="margin-top:8px;">
          <small>
            {{Token généré avec 256 bits d'entropie (bin2hex random_bytes). Révocation immédiate en cliquant « Regénérer ». Conserver ce token en lieu sûr — il permet d'envoyer des messages WhatsApp.}}
          </small>
        </span>
      </div>
    </div>

    <div class="form-group">
      <label class="col-lg-4 control-label">{{URL du webhook}}</label>
      <div class="col-lg-7">
        <div class="input-group">
          <input type="text" class="form-control" id="in_webhook_url"
                 value="<?php echo htmlspecialchars($webhook_url, ENT_QUOTES, 'UTF-8'); ?>"
                 readonly style="font-family:monospace;font-size:0.82em;background:#f5f5f5;">
          <span class="input-group-btn">
            <button class="btn btn-default" type="button" id="btn_copy_webhook_url"
                    title="{{Copier l'URL}}">
              <i class="fas fa-copy"></i>
            </button>
          </span>
        </div>
        <span class="help-block" style="margin-top:6px;">
          <small>
            <strong>{{Exemple curl :}}</strong><br>
            <code style="word-break:break-all;">
              curl -X POST "<?php echo htmlspecialchars($webhook_url, ENT_QUOTES, 'UTF-8'); ?>"
              -H "Content-Type: application/json"
              -H "X-JWA-Token: <token>"
              -d '{"message":"Bonjour depuis n8n!"}'
            </code>
          </small>
        </span>
      </div>
    </div>

    <div class="form-group">
      <div class="col-lg-offset-4 col-lg-7">
        <div class="panel panel-default" style="margin:0;">
          <div class="panel-heading" style="padding:8px 12px;font-size:0.88em;font-weight:700;">
            {{Format de la requête POST}}
          </div>
          <div class="panel-body" style="padding:10px 14px;">
<pre style="margin:0;font-size:0.82em;background:transparent;border:none;padding:0;">{
  "action":     "send",          // "send" (défaut) ou "status"
  "eqLogic_id": 42,              // optionnel — 1er équipement actif si absent
  "message":    "Votre message", // obligatoire pour send
  "phone":      "33612345678"    // optionnel — vide = groupe canal
}</pre>
          </div>
        </div>
        <div class="panel panel-success" style="margin:8px 0 0;">
          <div class="panel-heading" style="padding:8px 12px;font-size:0.88em;font-weight:700;">
            {{Réponse succès}}
          </div>
          <div class="panel-body" style="padding:10px 14px;">
<pre style="margin:0;font-size:0.82em;background:transparent;border:none;padding:0;">{"status":"ok","sent":true,"eqLogic_id":42}</pre>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Envoi de fichiers ────────────────────────────────────────────────── -->
    <legend style="margin-top:20px;">
      <i class="fas fa-folder-open"></i> {{Envoi de fichiers}}
    </legend>
    <div class="form-group">
      <label class="col-lg-4 control-label">{{Répertoires supplémentaires autorisés}}</label>
      <div class="col-lg-7">
        <textarea class="configKey form-control" data-l1key="extra_media_dirs"
                  rows="4" style="font-family:monospace;font-size:0.9em;"
                  placeholder="/mnt/nas/photos&#10;/home/pi/rapports"></textarea>
        <span class="help-block">
          <small>
            {{Un chemin absolu par ligne. S'ajoutent aux emplacements autorisés par défaut (data/, tmp/, cache/ de Jeedom et /tmp). Exemple : /mnt/nas/photos}}
            <br><strong>{{Attention :}}</strong> {{n'ajoutez que des répertoires que vous contrôlez — tout fichier lisible dans ces dossiers pourra être envoyé via WhatsApp.}}
            <br>{{Redémarrez le démon après modification.}}
          </small>
        </span>
      </div>
    </div>

  </fieldset>
</form>

<script>
// Génération / révocation du token webhook
$('#btn_gen_webhook_token').on('click', function () {
  if (!confirm('{{Générer un nouveau token ? L\'ancien ne fonctionnera plus.}}')) { return; }
  var $btn = $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
  $.ajax({
    type: 'POST',
    url:  'plugins/jeewhatsapp/core/ajax/jeewhatsapp.ajax.php',
    data: { action: 'generateWebhookToken' },
    dataType: 'json',
    success: function (data) {
      if (data.state !== 'ok') {
        jeedomUtils.showAlert({ message: data.result, level: 'danger' });
        $btn.prop('disabled', false).html('<i class="fas fa-key"></i> {{Regénérer (révoque l\'ancien)}}');
        return;
      }
      // Rafraîchit la page pour afficher le nouveau token
      jeedomUtils.loadPage('index.php?v=d&plugin=jeewhatsapp&modal=plugin_config');
    },
    error: function () {
      jeedomUtils.showAlert({ message: '{{Erreur de communication}}', level: 'danger' });
      $btn.prop('disabled', false).html('<i class="fas fa-key"></i> {{Regénérer (révoque l\'ancien)}}');
    }
  });
});

// Révocation du webhook
$('#btn_revoke_webhook_token').on('click', function () {
  if (!confirm('{{Désactiver le webhook ? Toutes les intégrations utilisant ce token cesseront de fonctionner.}}')) { return; }
  $.ajax({
    type: 'POST',
    url:  'core/ajax/config.ajax.php',
    data: { action: 'save', plugin: 'jeewhatsapp', configuration: JSON.stringify({ webhook_token: '' }) },
    dataType: 'json',
    success: function () {
      jeedomUtils.loadPage('index.php?v=d&plugin=jeewhatsapp&modal=plugin_config');
    }
  });
});

// Copie dans le presse-papiers
function copyToClipboard(val) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(val).then(function () {
      jeedomUtils.showAlert({ message: '{{Copié !}}', level: 'success' });
    });
  } else {
    var t = $('<input>').val(val).appendTo('body').select();
    document.execCommand('copy');
    t.remove();
    jeedomUtils.showAlert({ message: '{{Copié !}}', level: 'success' });
  }
}
$('#btn_copy_webhook_token').on('click', function () { copyToClipboard($('#in_webhook_token').val()); });
$('#btn_copy_webhook_url').on('click', function ()   { copyToClipboard($('#in_webhook_url').val()); });
</script>
