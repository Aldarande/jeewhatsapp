<?php
/* This file is part of Jeedom.
 * Plugin JeeWhatsApp - Aldarande
 * Licence AGPL v3 — https://www.gnu.org/licenses/agpl-3.0.html
 */
try {
  require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
  include_file('core', 'authentification', 'php');

  // SECURITY: ajax::init() vérifie le token CSRF et la liste des actions autorisées
  ajax::init(['testSend', 'getQR', 'getStatus', 'createGroup', 'findGroup', 'setGroupIcon', 'groupAction', 'uploadVoice', 'getMedia', 'generateWebhookToken', 'getLiveEvents', 'clearLiveEvents', 'getStats', 'backupSession', 'restoreSession', 'logout']);

  if (!isConnect('admin')) {
    throw new Exception(__('401 - Accès non autorisé', __FILE__));
  }

  switch (init('action')) {

    // ── Envoi de test depuis l'onglet Test ──────────────────────────────
    case 'testSend':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception(__('eqLogic_id manquant', __FILE__)); }
      $eqLogic = jeewhatsapp::byId(intval($eqLogic_id));
      if (!is_object($eqLogic)) { throw new Exception(__('Équipement introuvable', __FILE__)); }
      $phone   = trim(init('phone', ''));
      $mention = trim(init('mention', ''));
      $message = trim(init('message', 'Test JeeWhatsApp 🚀'));
      // Résout les tags Jeedom saisis dans le champ test (#[Objet][Éq][Cmd]# ou #id#)
      // pour reproduire fidèlement ce qu'un scénario enverrait.
      try {
        $message = cmd::cmdToValue(jeedom::fromHumanReadable($message));
      } catch (Exception $e) {
        log::add('jeewhatsapp', 'warning', 'jeewhatsapp.ajax.php::testSend l.' . __LINE__ . ' — résolution des tags impossible : ' . $e->getMessage());
      }
      // phone vide = envoyer dans le groupe canal — skipPrefix=true pour les tests
      $eqLogic->sendMessage($message, $phone !== '' ? $phone : null, $mention !== '' ? $mention : null, true);
      ajax::success();
      break;

    // ── Récupération du QR code pour l'affichage dans l'UI ─────────────
    case 'getQR':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception(__('eqLogic_id manquant', __FILE__)); }
      $eqLogic = jeewhatsapp::byId(intval($eqLogic_id));
      if (!is_object($eqLogic)) { throw new Exception(__('Équipement introuvable', __FILE__)); }
      $data = $eqLogic->getQRData();
      ajax::success($data);
      break;

    // ── Recherche du groupe canal par nom ───────────────────────────────
    case 'findGroup':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception(__('eqLogic_id manquant', __FILE__)); }
      $eqLogic = jeewhatsapp::byId(intval($eqLogic_id));
      if (!is_object($eqLogic)) { throw new Exception(__('Équipement introuvable', __FILE__)); }
      $name = trim(init('name', ''));
      ajax::success($eqLogic->findGroup($name !== '' ? $name : null));
      break;

    // ── Création d'un groupe WhatsApp ───────────────────────────────────
    case 'createGroup':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception(__('eqLogic_id manquant', __FILE__)); }
      $eqLogic = jeewhatsapp::byId(intval($eqLogic_id));
      if (!is_object($eqLogic)) { throw new Exception(__('Équipement introuvable', __FILE__)); }
      $name = trim(init('name', ''));
      ajax::success($eqLogic->createGroup($name !== '' ? $name : null));
      break;

    // ── Définit l'icône du plugin comme photo du groupe WhatsApp ────────
    case 'setGroupIcon':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception(__('eqLogic_id manquant', __FILE__)); }
      $eqLogic = jeewhatsapp::byId(intval($eqLogic_id));
      if (!is_object($eqLogic)) { throw new Exception(__('Équipement introuvable', __FILE__)); }
      $tag = trim(init('tag', ''));
      ajax::success($eqLogic->setGroupIcon($tag !== '' ? $tag : null));
      break;

    // ── Gestion du groupe (admin) ───────────────────────────────────────
    case 'groupAction':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception(__('eqLogic_id manquant', __FILE__)); }
      $eqLogic = jeewhatsapp::byId(intval($eqLogic_id));
      if (!is_object($eqLogic)) { throw new Exception(__('Équipement introuvable', __FILE__)); }
      $op    = trim(init('op', ''));
      $value = trim(init('value', ''));
      $tag   = trim(init('tag', ''));
      ajax::success($eqLogic->groupAction($op, $value !== '' ? $value : null, $tag !== '' ? $tag : null));
      break;

    // ── Réception d'un enregistrement vocal depuis le widget ────────────
    case 'uploadVoice':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception(__('eqLogic_id manquant', __FILE__)); }
      $eqLogic = jeewhatsapp::byId(intval($eqLogic_id));
      if (!is_object($eqLogic)) { throw new Exception(__('Équipement introuvable', __FILE__)); }
      $eqLogic->sendVoiceRecording(isset($_FILES['audio']) ? $_FILES['audio'] : null);
      ajax::success();
      break;

    // ── Streaming d'un média entrant pour le widget (sortie binaire) ────
    case 'getMedia':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception(__('eqLogic_id manquant', __FILE__)); }
      $eqLogic = jeewhatsapp::byId(intval($eqLogic_id));
      if (!is_object($eqLogic)) { throw new Exception(__('Équipement introuvable', __FILE__)); }
      $eqLogic->streamIncomingMedia(trim(init('path', '')));
      exit(0); // contenu binaire déjà envoyé, pas de ajax::success()

    // ── Sauvegarde chiffrée de la session (téléchargement binaire) ──────
    case 'backupSession':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception(__('eqLogic_id manquant', __FILE__)); }
      $eqLogic = jeewhatsapp::byId(intval($eqLogic_id));
      if (!is_object($eqLogic)) { throw new Exception(__('Équipement introuvable', __FILE__)); }
      $blob = $eqLogic->backupSession(init('passphrase', ''));
      while (ob_get_level() > 0) { ob_end_clean(); }
      header('Content-Type: application/octet-stream');
      header('Content-Disposition: attachment; filename="jeewhatsapp-session-' . intval($eqLogic_id) . '-' . date('Ymd-Hi') . '.jwab"');
      header('Content-Length: ' . strlen($blob));
      echo $blob;
      exit(0);

    // ── Restauration de session depuis un fichier chiffré uploadé ───────
    case 'restoreSession':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception(__('eqLogic_id manquant', __FILE__)); }
      $eqLogic = jeewhatsapp::byId(intval($eqLogic_id));
      if (!is_object($eqLogic)) { throw new Exception(__('Équipement introuvable', __FILE__)); }
      if (!isset($_FILES['session']) || ($_FILES['session']['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['session']['tmp_name'])) {
        throw new Exception(__('Aucun fichier de sauvegarde reçu', __FILE__));
      }
      if (($_FILES['session']['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new Exception(__('Fichier de sauvegarde trop volumineux', __FILE__));
      }
      $blob = file_get_contents($_FILES['session']['tmp_name']);
      ajax::success($eqLogic->restoreSession(init('passphrase', ''), $blob));
      break;

    // ── Déconnexion du compte WhatsApp ──────────────────────────────────
    case 'logout':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception(__('eqLogic_id manquant', __FILE__)); }
      $eqLogic = jeewhatsapp::byId(intval($eqLogic_id));
      if (!is_object($eqLogic)) { throw new Exception(__('Équipement introuvable', __FILE__)); }
      ajax::success($eqLogic->logout());
      break;

    // ── Statut de connexion WhatsApp ────────────────────────────────────
    case 'getStatus':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception(__('eqLogic_id manquant', __FILE__)); }
      $eqLogic = jeewhatsapp::byId(intval($eqLogic_id));
      if (!is_object($eqLogic)) { throw new Exception(__('Équipement introuvable', __FILE__)); }
      $status  = $eqLogic->getConnectionStatus();
      $status['daemon'] = jeewhatsapp::deamon_info()['state'];
      ajax::success($status);
      break;

    // ── Événements live — tampon circulaire du daemon (#31) ────────────────
    case 'getLiveEvents':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception(__('eqLogic_id manquant', __FILE__)); }
      $since = (int) init('since', 0); // timestamp JS (ms) du dernier événement reçu
      $file  = dirname(__FILE__) . '/../../resources/jeewhatsappd/auth/'
             . intval($eqLogic_id) . '/events.json';
      $events = [];
      if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw) {
          $all = json_decode($raw, true);
          if (is_array($all)) {
            // Filtrer les événements plus récents que $since (millisecondes)
            foreach ($all as $ev) {
              $evTs = isset($ev['ts']) ? (int) (strtotime($ev['ts']) * 1000) : 0;
              if ($since === 0 || $evTs > $since) {
                $events[] = $ev;
              }
            }
          }
        }
      }
      ajax::success($events);
      break;

    // ── Vider le flux live — purge le tampon du daemon (#31) ───────────────
    // Passe par le daemon (action clearEvents) pour vider À LA FOIS le buffer
    // mémoire eventsBuf[id] ET le fichier events.json. Vider seulement le fichier
    // ne suffit pas : le daemon réécrirait tout depuis sa mémoire au prochain
    // événement (forum #149964, ddelec24 — logs réapparaissant au rafraîchissement).
    // Repli : si le daemon est injoignable, on vide au moins le fichier.
    case 'clearLiveEvents':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception(__('eqLogic_id manquant', __FILE__)); }
      $eqLogic = jeewhatsapp::byId(intval($eqLogic_id));
      if (!is_object($eqLogic)) { throw new Exception(__('Équipement introuvable', __FILE__)); }
      try {
        $res = $eqLogic->clearLiveEvents();
      } catch (Exception $e) {
        $file = dirname(__FILE__) . '/../../resources/jeewhatsappd/auth/'
              . intval($eqLogic_id) . '/events.json';
        if (is_file($file)) { @file_put_contents($file, '[]'); }
        $res = ['cleared' => true, 'fallback' => true];
      }
      ajax::success($res);
      break;

    // ── Statistiques (#30) ──────────────────────────────────────────────────
    case 'getStats':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception(__('eqLogic_id manquant', __FILE__)); }
      $eqLogic = jeewhatsapp::byId(intval($eqLogic_id));
      if (!is_object($eqLogic)) { throw new Exception(__('Équipement introuvable', __FILE__)); }
      ajax::success($eqLogic->getStats());
      break;

    // ── Génération (ou révocation) du token webhook REST (#33) ─────────────
    case 'generateWebhookToken':
      $token = bin2hex(random_bytes(32)); // 64 caractères hex
      config::save('webhook_token', $token, 'jeewhatsapp');
      $jeedom_port = config::byKey('port', 'network', 80);
      $jeedom_comp = config::byKey('urlcomplement', 'network', '');
      $base_url    = 'http://127.0.0.1:' . $jeedom_port . $jeedom_comp;
      ajax::success([
        'token'   => $token,
        'url'     => $base_url . '/plugins/jeewhatsapp/core/php/webhook.php',
      ]);
      break;

    default:
      throw new Exception(__('Action inconnue : ', __FILE__) . init('action'));
  }

} catch (Exception $e) {
  ajax::error(displayException($e), $e->getCode());
}
