<?php
/**
 * JeeWhatsApp — Webhook REST externe (#33)
 *
 * Permet à des outils tiers (n8n, Node-RED, Home Assistant, scripts cron…)
 * d'envoyer des messages WhatsApp sans avoir accès à la session Jeedom.
 *
 * Authentification : token dans le header X-JWA-Token OU ?token= en query string
 * (header recommandé pour éviter que le token apparaisse dans les logs serveur)
 *
 * ── Envoi d'un message ──────────────────────────────────────────────────────
 * POST /plugins/jeewhatsapp/core/php/webhook.php
 * Content-Type: application/json
 * X-JWA-Token: <votre_token>
 *
 * {
 *   "action":      "send",          // optionnel — "send" par défaut
 *   "eqLogic_id":  42,              // optionnel — 1er équipement actif si absent
 *   "message":     "Texte à envoyer",
 *   "phone":       "33612345678"    // optionnel — vide = groupe canal
 * }
 *
 * ── Réponse succès ──────────────────────────────────────────────────────────
 * HTTP 200  {"status":"ok","sent":true,"eqLogic_id":42}
 *
 * ── Réponse erreur ──────────────────────────────────────────────────────────
 * HTTP 4xx/5xx  {"status":"error","message":"Description de l'erreur"}
 *
 * ── Générer un token ────────────────────────────────────────────────────────
 * Plugins → JeeWhatsApp → Configuration → bouton « Générer un token webhook »
 *
 * ── Sécurité ────────────────────────────────────────────────────────────────
 * - hash_equals() pour la comparaison (résistant timing attack)
 * - Token stocké en config plugin (pas en base cmd ni en query string loggé)
 * - Log de chaque appel (succès et échec)
 * - Aucune session Jeedom requise
 * - Accepte uniquement POST
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Désactive la mise en tampon pour les réponses longues
while (ob_get_level() > 0) { @ob_end_clean(); }

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

// ── Helpers ──────────────────────────────────────────────────────────────────

function jwa_json_error($message, $http = 400) {
    http_response_code($http);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Méthode POST uniquement ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jwa_json_error('Method Not Allowed — POST required', 405);
}

// ── Vérification que le webhook est activé ────────────────────────────────────
$token_stored = config::byKey('webhook_token', 'jeewhatsapp', '');
if ($token_stored === '') {
    jwa_json_error('Webhook not configured — generate a token in plugin settings (Plugins → JeeWhatsApp → Configuration)', 503);
}

// ── Authentification par token ────────────────────────────────────────────────
// Priorité : header X-JWA-Token > query param ?token=
$token_provided = '';
if (!empty($_SERVER['HTTP_X_JWA_TOKEN'])) {
    $token_provided = trim($_SERVER['HTTP_X_JWA_TOKEN']);
} elseif (!empty($_GET['token'])) {
    $token_provided = trim($_GET['token']);
}

if ($token_provided === '') {
    log::add('jeewhatsapp', 'warning',
        'webhook.php::auth — Token manquant depuis ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    jwa_json_error('Unauthorized — missing token (header X-JWA-Token or ?token=)', 401);
}

// hash_equals() : comparaison à durée constante (protection timing attack)
if (!hash_equals($token_stored, $token_provided)) {
    log::add('jeewhatsapp', 'warning',
        'webhook.php::auth — Token invalide depuis ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    jwa_json_error('Unauthorized — invalid token', 401);
}

// ── Lecture et validation du corps JSON ───────────────────────────────────────
$raw  = (string) @file_get_contents('php://input');
if ($raw === '') {
    jwa_json_error('Bad Request — JSON body required');
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    jwa_json_error('Bad Request — invalid JSON');
}

$action     = trim($body['action']  ?? 'send');
$message    = trim($body['message'] ?? '');
$phone      = trim($body['phone']   ?? '');
$eqLogicId  = isset($body['eqLogic_id']) ? intval($body['eqLogic_id']) : 0;

// ── Aiguillage des actions ────────────────────────────────────────────────────
switch ($action) {

    // ── send : envoyer un message dans le groupe canal ou à un numéro ────────
    case 'send':
        if ($message === '') {
            jwa_json_error('Bad Request — field "message" is required for action "send"');
        }

        // Résolution de l'équipement
        if ($eqLogicId > 0) {
            $eqLogic = eqLogic::byId($eqLogicId);
            if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== 'jeewhatsapp') {
                jwa_json_error('Not Found — eqLogic_id ' . $eqLogicId . ' introuvable ou mauvais type', 404);
            }
        } else {
            // Premier équipement jeewhatsapp actif
            $eqLogic = null;
            foreach (eqLogic::byType('jeewhatsapp') as $eq) {
                if ($eq->getIsEnable()) { $eqLogic = $eq; break; }
            }
            if (!is_object($eqLogic)) {
                jwa_json_error('Service Unavailable — no active jeewhatsapp equipment found', 503);
            }
        }

        if (!$eqLogic->getIsEnable()) {
            jwa_json_error('Service Unavailable — equipment is disabled', 503);
        }

        try {
            $eqLogic->sendMessage($message, $phone !== '' ? $phone : null);
            log::add('jeewhatsapp', 'info',
                'webhook.php::send — Message envoyé (eqLogic #' . $eqLogic->getId()
                . ', ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ')');
            echo json_encode([
                'status'      => 'ok',
                'sent'        => true,
                'eqLogic_id'  => $eqLogic->getId(),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            log::add('jeewhatsapp', 'error',
                'webhook.php::send — ' . $e->getMessage());
            jwa_json_error($e->getMessage(), 500);
        }
        break;

    // ── status : état de connexion de l'équipement ────────────────────────────
    case 'status':
        if ($eqLogicId > 0) {
            $eqLogic = eqLogic::byId($eqLogicId);
            if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== 'jeewhatsapp') {
                jwa_json_error('Not Found — eqLogic_id ' . $eqLogicId . ' introuvable', 404);
            }
        } else {
            $eqLogic = null;
            foreach (eqLogic::byType('jeewhatsapp') as $eq) {
                if ($eq->getIsEnable()) { $eqLogic = $eq; break; }
            }
            if (!is_object($eqLogic)) {
                jwa_json_error('Service Unavailable — no active jeewhatsapp equipment found', 503);
            }
        }
        try {
            $st = $eqLogic->getConnectionStatus();
            $st['daemon']     = jeewhatsapp::deamon_info()['state'];
            $st['eqLogic_id'] = $eqLogic->getId();
            $st['status_req'] = 'ok';
            echo json_encode($st, JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            jwa_json_error($e->getMessage(), 500);
        }
        break;

    default:
        jwa_json_error('Bad Request — unknown action "' . htmlspecialchars($action) . '". Supported: send, status');
}
