<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

// Validation de la clé API
$api_key = isset($_GET['apikey']) ? $_GET['apikey'] : (isset($_POST['apikey']) ? $_POST['apikey'] : '');
if ($api_key !== jeedom::getApiKey('jeewhatsapp')) {
  http_response_code(403);
  log::add('jeewhatsapp', 'warning', 'Callback refusé — mauvaise clé API depuis ' . $_SERVER['REMOTE_ADDR']);
  die(json_encode(['error' => 'Forbidden']));
}

// Lecture du corps JSON
$body = file_get_contents('php://input');
$data = json_decode($body, true);
if (!is_array($data)) {
  http_response_code(400);
  die(json_encode(['error' => 'Invalid JSON']));
}

// Répondre immédiatement au daemon pour libérer la connexion HTTP avant le traitement.
// Sans ça, l'interaction (interactQuery::tryToReply + sendToDaemon) dépasserait le
// timeout de 5 s configuré côté daemon, entraînant une erreur "Envoi échoué".
$response = json_encode(['status' => 'ok']);
http_response_code(200);
header('Content-Type: application/json');
header('Content-Length: ' . strlen($response));
echo $response;
if (ob_get_level() > 0) { ob_end_flush(); }
flush();
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
ignore_user_abort(true);

// Traitement après libération de la connexion HTTP
try {
  $data['instance_id'] = (int)($data['instance_id'] ?? 0);
  jeewhatsapp::callback($data);
} catch (Exception $e) {
  log::add('jeewhatsapp', 'error', 'callback.php l.' . __LINE__ . ' — Erreur callback : ' . $e->getMessage());
}
