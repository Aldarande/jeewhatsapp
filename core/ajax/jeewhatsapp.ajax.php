<?php
/* This file is part of Jeedom.
 * Plugin JeeWhatsApp - Aldarande
 * Licence AGPL v3 — https://www.gnu.org/licenses/agpl-3.0.html
 */
try {
  require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
  include_file('core', 'authentification', 'php');

  // SECURITY: ajax::init() vérifie le token CSRF et la liste des actions autorisées
  ajax::init(['testSend', 'getQR', 'getStatus', 'createGroup', 'findGroup']);

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

    default:
      throw new Exception(__('Action inconnue : ', __FILE__) . init('action'));
  }

} catch (Exception $e) {
  ajax::error(displayException($e), $e->getCode());
}
