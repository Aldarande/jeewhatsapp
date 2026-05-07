<?php
try {
  require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
  include_file('core', 'authentification', 'php');
  if (!isConnect('admin')) { throw new Exception('{{401 - Accès non autorisé}}'); }
  if (!init('action')) { throw new Exception('{{Action manquante}}'); }

  $allowed_actions = ['testSend', 'getQR', 'getStatus', 'createGroup', 'findGroup'];
  if (!in_array(init('action'), $allowed_actions)) {
    throw new Exception('{{Action non autorisée : ' . init('action') . '}}');
  }

  switch (init('action')) {

    // ── Envoi de test depuis l'onglet Test ──────────────────────────────
    case 'testSend':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception('{{eqLogic_id manquant}}'); }
      $eqLogic = jeewhatsapp::byId($eqLogic_id);
      if (!is_object($eqLogic)) { throw new Exception('{{Équipement introuvable}}'); }
      $phone   = trim(init('phone', ''));
      $mention = trim(init('mention', ''));
      $message = trim(init('message', 'Test JeeWhatsApp 🚀'));
      // phone vide = envoyer dans le groupe canal
      $eqLogic->sendMessage($message, $phone !== '' ? $phone : null, $mention !== '' ? $mention : null);
      ajax::success();
      break;

    // ── Récupération du QR code pour l'affichage dans l'UI ─────────────
    case 'getQR':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception('{{eqLogic_id manquant}}'); }
      $eqLogic = jeewhatsapp::byId($eqLogic_id);
      if (!is_object($eqLogic)) { throw new Exception('{{Équipement introuvable}}'); }
      $data = $eqLogic->getQRData();
      ajax::success($data);
      break;

    // ── Recherche du groupe canal par nom ───────────────────────────────
    case 'findGroup':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception('{{eqLogic_id manquant}}'); }
      $eqLogic = jeewhatsapp::byId($eqLogic_id);
      if (!is_object($eqLogic)) { throw new Exception('{{Équipement introuvable}}'); }
      $name = trim(init('name', ''));
      ajax::success($eqLogic->findGroup($name !== '' ? $name : null));
      break;

    // ── Création d'un groupe WhatsApp ───────────────────────────────────
    case 'createGroup':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception('{{eqLogic_id manquant}}'); }
      $eqLogic = jeewhatsapp::byId($eqLogic_id);
      if (!is_object($eqLogic)) { throw new Exception('{{Équipement introuvable}}'); }
      $name = trim(init('name', ''));
      ajax::success($eqLogic->createGroup($name !== '' ? $name : null));
      break;

    // ── Statut de connexion WhatsApp ────────────────────────────────────
    case 'getStatus':
      $eqLogic_id = init('eqLogic_id');
      if (!$eqLogic_id) { throw new Exception('{{eqLogic_id manquant}}'); }
      $eqLogic = jeewhatsapp::byId($eqLogic_id);
      if (!is_object($eqLogic)) { throw new Exception('{{Équipement introuvable}}'); }
      $status  = $eqLogic->getConnectionStatus();
      $status['daemon'] = jeewhatsapp::deamon_info()['state'];
      ajax::success($status);
      break;
  }

} catch (Exception $e) {
  ajax::error(displayException($e), $e->getCode());
}
