<?php
/* This file is part of Jeedom.
 * Plugin JeeWhatsApp - Aldarande
 * Licence AGPL v3 — https://www.gnu.org/licenses/agpl-3.0.html
 */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

// SECURITY (F-003): le callback n'est légitime que depuis le daemon local (CWE-346)
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
  http_response_code(403);
  log::add('jeewhatsapp', 'warning', 'callback.php l.' . __LINE__ . ' — IP non locale refusée : ' . $remote);
  die(json_encode(['error' => 'Forbidden']));
}

// SECURITY (F-010): exiger Content-Type JSON (CWE-20)
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== 0) {
  http_response_code(415);
  die(json_encode(['error' => 'Content-Type must be application/json']));
}

// SECURITY (F-001/F-006): API key acceptée en priorité via header X-API-Key (CWE-598)
// Fallback GET/POST conservé pour compatibilité ascendante uniquement
$api_key = $_SERVER['HTTP_X_API_KEY']
        ?? ($_GET['apikey'] ?? ($_POST['apikey'] ?? ''));
if (!hash_equals(jeedom::getApiKey('jeewhatsapp'), (string)$api_key)) {
  http_response_code(403);
  log::add('jeewhatsapp', 'warning', 'callback.php l.' . __LINE__ . ' — Clé API invalide depuis ' . $remote);
  die(json_encode(['error' => 'Forbidden']));
}

// SECURITY (F-010, CWE-770) : rate-limiting simple (60 req/min/IP). Le callback
// est appelé par le daemon local pour chaque message reçu ; au-delà de ~1/sec
// soutenu sur la minute, on coupe pour éviter qu'un processus malveillant local
// (qui aurait obtenu la clé API) saturent le PHP-FPM avec des callbacks bidons.
$rlKey = 'jeewhatsapp::ratelimit::callback::' . md5($remote);
$rlCount = (int) cache::byKey($rlKey)->getValue(0);
if ($rlCount > 60) {
  http_response_code(429);
  log::add('jeewhatsapp', 'warning', 'callback.php l.' . __LINE__
    . ' — Rate-limit atteint (' . $rlCount . ' req/min) depuis ' . $remote);
  die(json_encode(['error' => 'Too Many Requests']));
}
cache::set($rlKey, $rlCount + 1, 60);

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
