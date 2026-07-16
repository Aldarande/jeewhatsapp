<?php
/* This file is part of Jeedom.
 * Plugin JeeWhatsApp - Aldarande
 * Licence AGPL v3 — https://www.gnu.org/licenses/agpl-3.0.html
 */

class jeewhatsapp extends eqLogic {

  const DAEMON_PORT_DEFAULT = 55148;

  // -------------------------------------------------------------------------
  // Port daemon
  // -------------------------------------------------------------------------

  public static function getPort() {
    return config::byKey('socketport', __CLASS__, self::DAEMON_PORT_DEFAULT);
  }

  // -------------------------------------------------------------------------
  // Dépendances Node.js (Baileys)
  // -------------------------------------------------------------------------

  public static function dependancy_info() {
    $return = [
      'log'           => log::getPathToLog(__CLASS__ . '_dependancy'),
      'progress_file' => jeedom::getTmpFolder(__CLASS__) . '/dependancy',
    ];
    if (file_exists($return['progress_file'])) {
      $return['state'] = 'in_progress';
      return $return;
    }
    $node_modules = dirname(__FILE__) . '/../../resources/jeewhatsappd/node_modules';
    $return['state'] = file_exists($node_modules . '/@whiskeysockets/baileys/package.json') ? 'ok' : 'nok';
    return $return;
  }

  public static function dependancy_install() {
    log::remove(__CLASS__ . '_dependancy');
    return [
      'script' => dirname(__FILE__) . '/../../resources/install_dep.sh ' . jeedom::getTmpFolder(__CLASS__) . '/dependancy',
      'log'    => log::getPathToLog(__CLASS__ . '_dependancy'),
    ];
  }

  // -------------------------------------------------------------------------
  // Exclusions de sauvegarde Jeedom
  // Exclut les binaires et modèles lourds installés par install_dep.sh.
  // Ces fichiers sont re-téléchargés automatiquement à la prochaine
  // installation des dépendances — inutile de les inclure dans le backup.
  // -------------------------------------------------------------------------

  public static function backupExclude() {
    return [
      // ── Binaires et modèles re-téléchargeables (install_dep.sh) ──────────
      'resources/piper/piper',               // binaire Piper TTS (~50 Mo)
      'resources/piper/voices',              // modèles vocaux Piper (~100 Mo)
      'resources/piper/piper.tar.gz',        // archive d'installation Piper
      'resources/stt/model-fr',             // modèle Vosk STT français (~40 Mo)
      'resources/jeewhatsappd/node_modules', // dépendances Node.js (npm)

      // ── Données volatiles dans auth/{id}/ — reconstruites à la reconnexion ──
      // Les credentials Baileys (creds.json, pre-key-*, session-*, …) sont
      // conservés — ce sont eux qui permettent de restaurer la session sans QR.
      'history.json',    // historique widget (50 msgs) — reconstruit à l'usage
      'events.json',     // tampon debug live — données temps réel sans valeur
      'status.txt',      // statut courant du daemon — volatile
      'qr.txt',          // QR code temporaire — expiré en 30 s
      'group_jid.txt',   // JID groupe en cache — retrouvé auto à la connexion

      // ── Statistiques (data/) ──────────────────────────────────────────────
      'data/',           // stats_sent_30d / stats_received_30d — reconstruites

      // ── Sauvegardes de session pré-restauration ───────────────────────────
      // auth/{id}.bak_YYYYmmddHHMMSS : copie de l'ancienne session faite par
      // restoreSession() avant écrasement (rollback LOCAL uniquement). La session
      // active est déjà dans le backup — inutile d'y dupliquer d'anciens
      // credentials WhatsApp. Le chemin est complet et contigu : GNU tar exclut
      // alors bien le dossier daté et son contenu (motif vérifié sur tar 1.34).
      // Complément à jeewhatsapp::pruneSessionBackups() qui borne leur nombre.
      'resources/jeewhatsappd/auth/*.bak_*',
    ];
  }

  // -------------------------------------------------------------------------
  // Daemon
  // -------------------------------------------------------------------------

  public static function deamon_info() {
    $return = ['launchable' => 'ok', 'launchable_message' => '', 'state' => 'nok', 'log' => __CLASS__];
    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/daemon.pid';
    if (file_exists($pid_file)) {
      $pid = intval(trim(file_get_contents($pid_file)));
      $alive = false;
      if ($pid > 0) {
        if (function_exists('posix_kill')) {
          $alive = @posix_kill($pid, 0);
        } else {
          $alive = @file_exists("/proc/$pid");
        }
      }
      if ($alive) { $return['state'] = 'ok'; }
    }
    if (self::dependancy_info()['state'] !== 'ok') {
      $return['launchable']         = 'nok';
      $return['launchable_message'] = __('Dépendances non installées', __FILE__);
    }
    return $return;
  }

  public static function deamon_start($_automatic = false) {
    self::deamon_stop();
    $daemon_info = self::deamon_info();
    if ($daemon_info['launchable'] !== 'ok') {
      throw new Exception(__('Impossible de lancer le daemon', __FILE__) . ' : ' . $daemon_info['launchable_message']);
    }

    // prefix et group_name sont passés ici pour que le daemon puisse les utiliser
    // dès le démarrage sans avoir à rappeler le PHP (aucune API Jeedom exposée au daemon)
    $instances = [];
    foreach (eqLogic::byType(__CLASS__) as $eqLogic) {
      if (!$eqLogic->getIsEnable()) { continue; }
      $instances[] = [
        'id'         => $eqLogic->getId(),
        'prefix'     => $eqLogic->getConfiguration('interaction_prefix', "\xF0\x9F\x8F\xA0 "),
        'group_name' => $eqLogic->getConfiguration('group_name', 'jeewhatsapp'),
        // v0.3 #16 — groupes canaux additionnels (tag=NomDuGroupe, 1 par ligne)
        'groups'     => self::parseExtraGroups($eqLogic->getConfiguration('extra_groups', '')),
      ];
    }

    $jeedom_port = config::byKey('port', 'network', 80);
    $jeedom_comp = config::byKey('urlcomplement', 'network', '');
    $api_key     = jeedom::getApiKey(__CLASS__);
    // SECURITY (F-001/F-006): callback sans apikey en query string — l'API key transite
    // via variable d'environnement JEEDOM_APIKEY et header X-API-Key (CWE-214, CWE-598)
    $callback    = 'http://127.0.0.1:' . $jeedom_port . $jeedom_comp
                 . '/plugins/jeewhatsapp/core/php/callback.php';

    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/daemon.pid';
    $log_file = log::getPathToLog(__CLASS__);

    // SECURITY (F-004, CWE-306): secret partagé pour authentifier les requêtes PHP→daemon
    // Régénéré à chaque démarrage du daemon, stocké en cache Jeedom (TTL 7j > usage normal)
    // Le daemon le lit via env var JEEDOM_DAEMON_SECRET, le PHP le passe en header X-Daemon-Secret
    $daemon_secret = bin2hex(random_bytes(32));
    cache::set('jeewhatsapp::daemon_secret', $daemon_secret, 86400 * 7);

    // SECURITY (F-001 + F-004): API key + daemon secret passés via env, jamais en CLI
    // Répertoires médias supplémentaires (SECURITY F-014) — validation côté PHP avant passage au daemon
    $extraDirsRaw = config::byKey('extra_media_dirs', 'jeewhatsapp', '');
    $extraDirs = [];
    foreach (explode("\n", $extraDirsRaw) as $line) {
      $dir = trim($line);
      if ($dir === '' || $dir[0] === '#') { continue; }
      if ($dir[0] !== '/' || strpos($dir, '..') !== false) {
        log::add(__CLASS__, 'warning',
          'jeewhatsapp.class.php::deamon_start() l.' . __LINE__
          . ' — Répertoire supplémentaire ignoré (chemin non absolu ou traversal) : ' . $dir);
        continue;
      }
      $extraDirs[] = $dir;
    }

    $cmd  = 'JEEDOM_APIKEY=' . escapeshellarg($api_key) . ' ';
    $cmd .= 'JEEDOM_DAEMON_SECRET=' . escapeshellarg($daemon_secret) . ' ';
    $cmd .= 'node ' . escapeshellarg(dirname(__FILE__) . '/../../resources/jeewhatsappd/jeewhatsappd.js');
    $cmd .= ' --instances '  . escapeshellarg(json_encode($instances));
    $cmd .= ' --port '       . self::getPort();
    $cmd .= ' --callback '   . escapeshellarg($callback);
    $cmd .= ' --pid-file '   . escapeshellarg($pid_file);
    $cmd .= ' --log-file '   . escapeshellarg($log_file);
    if (!empty($extraDirs)) {
      $cmd .= ' --extra-media-dirs ' . escapeshellarg(json_encode($extraDirs));
    }
    if (log::getLogLevel(__CLASS__) === 'debug') { $cmd .= ' --debug'; }
    $cmd .= ' >> ' . escapeshellarg($log_file) . ' 2>&1 &';

    log::add(__CLASS__, 'info', 'Démarrage daemon (port ' . self::getPort() . ', ' . count($instances) . ' instance(s))');
    shell_exec($cmd);

    $timeout = 60;
    $i = 0;
    while ($i++ < $timeout) {
      sleep(1);
      if (self::deamon_info()['state'] === 'ok') { break; }
    }
    if (self::deamon_info()['state'] !== 'ok') {
      throw new Exception(__('Impossible de démarrer le daemon après ', __FILE__) . $timeout . __(' secondes', __FILE__));
    }
  }

  public static function deamon_stop() {
    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/daemon.pid';
    if (file_exists($pid_file)) {
      $pid = intval(trim(file_get_contents($pid_file)));
      if ($pid > 0) { shell_exec('kill -15 ' . $pid . ' 2>/dev/null || kill -9 ' . $pid . ' 2>/dev/null'); }
      @unlink($pid_file);
    }
    // Libère le port dans tous les cas (system::fuserk() absent dans Jeedom 4.4+)
    $port = intval(self::getPort());
    if ($port > 0) {
      shell_exec('fuser -k ' . $port . '/tcp > /dev/null 2>&1');
    }
  }

  // -------------------------------------------------------------------------
  // Callback — appelé par callback.php à chaque message reçu
  // -------------------------------------------------------------------------

  public static function callback($_data) {
    $eqLogic = eqLogic::byId((int)($_data['instance_id'] ?? 0));
    if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== __CLASS__) { return; }
    $eqLogic->updateFromMessage($_data);
  }

  // -------------------------------------------------------------------------
  // Mise à jour des commandes depuis un message entrant
  // -------------------------------------------------------------------------

  public function updateFromMessage($_data) {
    // Aiguillage par type d'événement (introduit en v0.2 avec les réactions et médias)
    $eventType = $_data['event_type'] ?? 'message';
    if ($eventType === 'reaction') {
      $this->updateFromReaction($_data);
      return;
    }
    if ($eventType === 'attachment') {
      $this->updateFromAttachment($_data);
      return;
    }
    if ($eventType === 'poll_vote') {
      $this->updateFromPollVote($_data);
      return;
    }
    if ($eventType === 'read_receipt') {
      $this->checkAndUpdateCmd('last_read_at', $_data['read_at'] ?? date('Y-m-d H:i:s'));
      log::add('jeewhatsapp', 'info',
        'jeewhatsapp.class.php::updateFromMessage() l.' . __LINE__
        . ' — Accusé de lecture (' . ($_data['read_status'] ?? 'read') . ') msg '
        . ($_data['message_id'] ?? '?'));
      return;
    }

    // v0.4 #21 — Reconnaissance utilisateur : résout un profil Jeedom à partir du
    // numéro/JID expéditeur via le mapping configuré (user_mapping). Fallback sur le
    // nom WhatsApp puis sur le numéro brut si aucune correspondance.
    $resolvedProfile = $this->resolveSenderProfile($_data['sender'] ?? '');
    $profileForInteract = $resolvedProfile
      ?? ($_data['sender_name'] ?? null)
      ?? ($_data['sender'] ?? '');

    // checkAndUpdateCmd met à jour la valeur et déclenche les scénarios/interactions associés
    $this->checkAndUpdateCmd('last_message',     $_data['message']      ?? '');
    $this->checkAndUpdateCmd('last_sender',      $_data['sender']       ?? '');
    $this->checkAndUpdateCmd('last_sender_name', $_data['sender_name']  ?? '');
    $this->checkAndUpdateCmd('last_sender_profile', $resolvedProfile ?? '');
    $this->checkAndUpdateCmd('last_received_at', $_data['received_at']  ?? date('Y-m-d H:i:s'));
    // v0.3 #16 — groupe d'origine ('' = groupe canal par défaut, sinon tag additionnel)
    $this->checkAndUpdateCmd('last_group',       $_data['group_tag']    ?? '');
    $this->checkAndUpdateCmd('last_group_name',  $_data['group_name']   ?? '');

    // Historique widget + statistiques (#30)
    $senderLabel = $resolvedProfile ?? ($_data['sender_name'] ?? ($_data['sender'] ?? ''));
    $this->appendHistory('in', $_data['message'] ?? '', $senderLabel);
    $this->appendStats('r', $_data['sender'] ?? '', $_data['sender_name'] ?? '');

    // Compteur messages reçus aujourd'hui (cache TTL jusqu'à minuit)
    $this->incrementMessagesTodayCounter();

    if ($this->getConfiguration('interactions_enabled', 0) != 1) {
      log::add('jeewhatsapp', 'debug', 'jeewhatsapp.class.php::updateFromMessage() — Interactions désactivées — équipement #' . $this->getId());
      return;
    }
    $message = $_data['message'] ?? '';
    $sender  = $_data['sender']  ?? '';
    if ($message === '' || $sender === '') { return; }

    // Whitelist d'expéditeurs : si configurée, seuls les numéros listés peuvent
    // déclencher des interactions. Refus silencieux (log debug) sinon.
    // Format : 1 numéro par ligne ou séparés par virgule. Tous les non-chiffres
    // sont supprimés avant comparaison (06 12 34 56 78 == 33612345678 après normalisation FR).
    if (!self::isSenderAllowed($this, $sender)) {
      log::add('jeewhatsapp', 'debug',
        'jeewhatsapp.class.php::updateFromMessage() l.' . __LINE__
        . ' — Interaction refusée (sender ' . $sender . ' hors whitelist)');
      return;
    }

    // Filtre par mot-clé : si configuré, le message doit commencer par le keyword
    // (insensible à la casse, espace de séparation toléré). Le keyword est retiré
    // du message avant transmission à interactQuery pour permettre des phrases naturelles.
    // Ex : keyword="!jeedom" → message "!jeedom allume salon" devient "allume salon".
    $keyword = trim((string) $this->getConfiguration('interaction_keyword', ''));
    if ($keyword !== '') {
      if (stripos(ltrim($message), $keyword) !== 0) {
        log::add('jeewhatsapp', 'debug',
          'jeewhatsapp.class.php::updateFromMessage() l.' . __LINE__
          . ' — Interaction ignorée (manque keyword "' . $keyword . '") : ' . $message);
        return;
      }
      // Retire le keyword + espace optionnel pour passer à interactQuery uniquement la commande utile
      $message = trim(substr(ltrim($message), strlen($keyword)));
      if ($message === '') {
        log::add('jeewhatsapp', 'debug',
          'jeewhatsapp.class.php::updateFromMessage() l.' . __LINE__
          . ' — Keyword seul reçu, rien à traiter');
        return;
      }
    }

    log::add('jeewhatsapp', 'debug', 'jeewhatsapp.class.php::updateFromMessage() — Interaction de ' . $sender . ' : ' . $message);

    // v0.4 #19 — Commandes shortcuts (slash) : prioritaires sur interactQuery.
    // Si le message commence par '/' et qu'au moins un raccourci est configuré,
    // on le traite directement (exécution cmd ou modèle texte) sans passer par le NLP.
    $shortcut = $this->handleShortcut($message);
    if ($shortcut !== null) {
      if (isset($shortcut['reply']) && trim((string) $shortcut['reply']) !== '') {
        log::add('jeewhatsapp', 'info', 'jeewhatsapp.class.php::updateFromMessage() — réponse raccourci : ' . $shortcut['reply']);
        try {
          $this->sendReply($shortcut['reply']);
        } catch (Exception $e) {
          log::add('jeewhatsapp', 'error', 'jeewhatsapp.class.php::updateFromMessage() l.' . __LINE__ . ' — Erreur envoi réponse raccourci : ' . $e->getMessage());
        }
      }
      return;
    }

    $reply = interactQuery::tryToReply($message, [
      'plugin'     => 'jeewhatsapp',
      'profile'    => $profileForInteract,
      'emptyReply' => 0,
    ]);

    if (!is_array($reply) || !isset($reply['reply']) || trim($reply['reply']) === '') {
      log::add('jeewhatsapp', 'debug', 'jeewhatsapp.class.php::updateFromMessage() — aucune réponse pour : ' . $message);
      return;
    }

    log::add('jeewhatsapp', 'info', 'jeewhatsapp.class.php::updateFromMessage() — réponse dans le groupe : ' . $reply['reply']);

    try {
      // En mode groupe, la réponse va dans le groupe canal (pas au sender direct)
      // sendReply() route vers une note vocale si tts_enabled, sinon texte.
      $this->sendReply($reply['reply']);
      log::add('jeewhatsapp', 'info', 'jeewhatsapp.class.php::updateFromMessage() — message transmis au daemon');
    } catch (Exception $e) {
      log::add('jeewhatsapp', 'error', 'jeewhatsapp.class.php::updateFromMessage() l.' . __LINE__ . ' — Erreur envoi réponse interaction : ' . $e->getMessage());
    }
  }

  // -------------------------------------------------------------------------
  // Envoi d'un message via le daemon
  // $_phone = null  → groupe canal configuré
  // $_phone = '...' → destinataire direct (override)
  // $_skipPrefix    → true pour les tests (pas de préfixe 🏠)
  // -------------------------------------------------------------------------

  public function sendMessage($_message, $_phone = null, $_mention = null, $_skipPrefix = false, $_tag = null) {
    $prefix  = $this->getConfiguration('interaction_prefix', "\xF0\x9F\x8F\xA0 ");
    $message = (!$_skipPrefix && $prefix !== '') ? $prefix . $_message : $_message;

    // Résolution carnet de contacts : "Didier" → "33612345678"
    if ($_phone !== null && $_phone !== '') {
      $_phone = $this->resolvePhone($_phone);
    }

    // Déduplication anti-doublon (forum #149964). Filet de sécurité indépendant
    // de la cause (retry scénario, double déclencheur, latence…) : on refuse
    // d'ré-émettre exactement le même (message + destinataire) dans une fenêtre
    // courte. Les envois de test (skipPrefix) et distincts ne sont pas bloqués.
    // Fenêtre désactivable via config eqLogic 'dedup_window' (secondes, 0 = off).
    $dedupWindow = (int) $this->getConfiguration('dedup_window', 4);
    if ($dedupWindow > 0 && !$_skipPrefix) {
      $sig      = md5($this->getId() . '|' . ($_phone ?? '') . '|' . ($_tag ?? '') . '|' . $message);
      $dedupKey = 'jeewhatsapp::dedup::' . $sig;
      $last     = cache::byKey($dedupKey)->getValue(0);
      if ($last > 0 && (time() - (int) $last) < $dedupWindow) {
        log::add('jeewhatsapp', 'info',
          'jeewhatsapp.class.php::sendMessage() l.' . __LINE__
          . ' — Doublon ignoré (même message + destinataire en moins de ' . $dedupWindow . ' s) — anti-duplication');
        return ['deduplicated' => true];
      }
      cache::set($dedupKey, time(), max($dedupWindow, 10));
    }

    $params = [
      'instance_id' => $this->getId(),
      'message'     => $message,
    ];
    if ($_phone !== null && $_phone !== '') {
      $params['phone'] = $_phone;
    }
    if ($_mention !== null && $_mention !== '') {
      $params['mention'] = $_mention;
    }
    // v0.3 #16 — ciblage d'un groupe additionnel par tag
    if ($_tag !== null && $_tag !== '') {
      $params['group_tag'] = $_tag;
    }
    if (($p = $this->presenceParam()) !== null) { $params['presence'] = $p; }
    if (($e = $this->ephemeralParam()) !== null) { $params['ephemeral'] = $e; }

    $result = $this->sendToDaemon('send', $params);
    // P2 — si WhatsApp est déconnecté, le daemon met le message en file (outbox)
    // et répond {queued:true} au lieu d'une erreur : on traite comme un succès.
    if (is_array($result) && !empty($result['queued'])) {
      log::add('jeewhatsapp', 'info',
        'jeewhatsapp.class.php::sendMessage() l.' . __LINE__
        . ' — Message mis en file (WhatsApp déconnecté) — ' . ($result['pending'] ?? '?') . ' en attente');
    }
    $this->incrementSentCounters();
    // Historique widget : ajoute le message sortant (sans préfixe dans l'historique)
    if (!$_skipPrefix) {
      $this->appendHistory('out', $_message);
    }
    // Statistiques (#30)
    if (!$_skipPrefix) {
      $this->appendStats('s');
    }
    return $result;
  }

  // -------------------------------------------------------------------------
  // Cron horaire : envoi mensuel d'un message de soutien aléatoire
  // Déclenché par cron Jeedom toutes les heures (cf install.php).
  // - Lit la prochaine date planifiée par eqLogic (cache)
  // - Si jamais planifié → planifie un jour aléatoire du mois courant ou suivant
  // - Si l'heure est arrivée → envoie + replanifie pour le mois suivant
  // -------------------------------------------------------------------------

  public static function cronDonation() {
    foreach (eqLogic::byType(__CLASS__) as $eqLogic) {
      if (!$eqLogic->getIsEnable()) { continue; }
      if ($eqLogic->getConfiguration('donation_enabled', 0) != 1) { continue; }

      $cacheKey = 'jeewhatsapp::donation::next::' . $eqLogic->getId();
      $cached   = cache::byKey($cacheKey);
      $next_ts  = (int) $cached->getValue(0);
      $now      = time();

      // Première planification (cache vide ou expiré)
      if ($next_ts <= 0) {
        $next_ts = self::scheduleNextDonation();
        cache::set($cacheKey, $next_ts, 86400 * 65); // TTL 65j (couvre 2 mois)
        log::add('jeewhatsapp', 'info',
          'jeewhatsapp.class.php::cronDonation() l.' . __LINE__
          . ' — Première planification donation pour eqLogic #' . $eqLogic->getId()
          . ' à ' . date('Y-m-d H:i', $next_ts));
        continue;
      }

      // Heure d'envoi atteinte
      if ($now >= $next_ts) {
        try {
          $msg = self::getDonationMessage();
          $eqLogic->sendMessage($msg['text']);
          log::add('jeewhatsapp', 'info',
            'jeewhatsapp.class.php::cronDonation() l.' . __LINE__
            . ' — Donation ' . $msg['id'] . ' envoyée pour eqLogic #' . $eqLogic->getId());
        } catch (Exception $e) {
          log::add('jeewhatsapp', 'error',
            'jeewhatsapp.class.php::cronDonation() l.' . __LINE__
            . ' — Erreur envoi donation eqLogic #' . $eqLogic->getId() . ' : ' . $e->getMessage());
        }
        // Replanifier le mois suivant quoi qu'il arrive (évite spam si erreur)
        $next_ts = self::scheduleNextDonation(true);
        cache::set($cacheKey, $next_ts, 86400 * 65);
        log::add('jeewhatsapp', 'info',
          'jeewhatsapp.class.php::cronDonation() l.' . __LINE__
          . ' — Prochaine donation eqLogic #' . $eqLogic->getId()
          . ' planifiée à ' . date('Y-m-d H:i', $next_ts));
      }
    }
  }

  // Planifie un jour/heure aléatoire entre 10h et 19h
  // $_forceNextMonth = true → force le mois suivant (utilisé après envoi)
  private static function scheduleNextDonation($_forceNextMonth = false) {
    $now = time();
    if ($_forceNextMonth) {
      $base  = strtotime('+1 month', $now);
      $year  = (int) date('Y', $base);
      $month = (int) date('n', $base);
    } else {
      $year  = (int) date('Y', $now);
      $month = (int) date('n', $now);
    }
    $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));

    // PHP 7+ : random_int est cryptographiquement sûr
    $day    = random_int(1, $daysInMonth);
    $hour   = random_int(10, 19);
    $minute = random_int(0, 59);
    $ts     = mktime($hour, $minute, 0, $month, $day, $year);

    // Si la date tirée est déjà passée (cas où on planifie pour le mois courant
    // mais on est déjà au 25 du mois), bascule au mois suivant
    if (!$_forceNextMonth && $ts <= $now + 3600) {
      return self::scheduleNextDonation(true);
    }
    return $ts;
  }

  // -------------------------------------------------------------------------
  // Pool de messages de soutien — sélection aléatoire selon catégorie/occasion
  // Source : core/config/donation_messages.json
  //
  // $_filter = null            → tirage parmi messages sans occasion (général)
  // $_filter = 'sunday'        → tirage parmi messages d'occasion = sunday
  // $_filter = 'christmas'     → idem
  // $_filter = 'birthday'      → idem
  // $_filter = 'any'           → tirage parmi tous les messages (pool complet)
  // $_filter = ['short','humor'] → tirage parmi catégories listées
  // -------------------------------------------------------------------------

  public static function getDonationMessage($_filter = null) {
    $path = dirname(__FILE__) . '/../config/donation_messages.json';
    if (!file_exists($path)) {
      throw new Exception(__('Pool de messages introuvable', __FILE__) . ' : ' . $path);
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data) || !isset($data['messages'])) {
      throw new Exception(__('Pool de messages invalide (JSON corrompu)', __FILE__));
    }

    $pool = $data['messages'];

    // Filtrage
    if ($_filter === 'any') {
      // pas de filtre — pool complet
    } elseif (is_array($_filter)) {
      $pool = array_filter($pool, function ($m) use ($_filter) {
        return in_array($m['category'] ?? null, $_filter, true);
      });
    } elseif ($_filter !== null && $_filter !== '') {
      // Filtre par occasion exacte
      $pool = array_filter($pool, function ($m) use ($_filter) {
        return ($m['occasion'] ?? null) === $_filter;
      });
    } else {
      // Défaut : exclut les messages liés à une occasion spécifique
      $pool = array_filter($pool, function ($m) {
        return ($m['occasion'] ?? null) === null;
      });
    }

    $pool = array_values($pool);
    if (empty($pool)) {
      throw new Exception(__('Aucun message dans le pool pour ce filtre', __FILE__));
    }

    return $pool[array_rand($pool)];
  }

  // -------------------------------------------------------------------------
  // Envoi d'une position GPS (lat, long, nom optionnel)
  // -------------------------------------------------------------------------

  public function sendLocation($_latitude, $_longitude, $_name = '', $_phone = null) {
    $lat = (float) $_latitude;
    $lng = (float) $_longitude;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
      throw new Exception(__('Coordonnées GPS invalides (lat ∈ [-90,90], long ∈ [-180,180])', __FILE__));
    }
    $params = [
      'instance_id'   => $this->getId(),
      'latitude'      => $lat,
      'longitude'     => $lng,
      'location_name' => trim((string) $_name),
    ];
    if ($_phone !== null && $_phone !== '') { $params['phone'] = $_phone; }
    if (($e = $this->ephemeralParam()) !== null) { $params['ephemeral'] = $e; }
    $result = $this->sendToDaemon('sendLocation', $params);
    $this->incrementSentCounters();
    return $result;
  }

  // -------------------------------------------------------------------------
  // Envoi d'une carte de contact vCard
  // -------------------------------------------------------------------------

  public function sendContactCard($_contactPhone, $_contactName = '', $_phone = null) {
    if (!is_string($_contactPhone) || trim($_contactPhone) === '') {
      throw new Exception(__('Numéro de contact obligatoire', __FILE__));
    }
    $params = [
      'instance_id'   => $this->getId(),
      'contact_phone' => trim($_contactPhone),
      'contact_name'  => trim((string) $_contactName),
    ];
    if ($_phone !== null && $_phone !== '') { $params['phone'] = $_phone; }
    if (($e = $this->ephemeralParam()) !== null) { $params['ephemeral'] = $e; }
    $result = $this->sendToDaemon('sendContact', $params);
    $this->incrementSentCounters();
    return $result;
  }

  // -------------------------------------------------------------------------
  // Envoi d'un média (image, vidéo, audio, document) — chemin local serveur
  // $_path    = chemin absolu du fichier (lisible par www-data)
  // $_caption = légende optionnelle (image/vidéo/document uniquement)
  // $_phone   = destinataire optionnel (null = groupe canal)
  // -------------------------------------------------------------------------

  public function sendMediaFile($_path, $_caption = '', $_phone = null) {
    if (!is_string($_path) || trim($_path) === '') {
      throw new Exception(__('Chemin du fichier vide', __FILE__));
    }
    $path = trim($_path);
    if (!file_exists($path)) {
      throw new Exception(__('Fichier introuvable', __FILE__) . ' : ' . $path);
    }
    if (!is_readable($path)) {
      throw new Exception(__('Fichier non lisible', __FILE__) . ' : ' . $path);
    }

    // Préfixe Jeedom appliqué uniquement si une caption non vide est fournie
    $caption = '';
    if ($_caption !== null && trim($_caption) !== '') {
      $prefix  = $this->getConfiguration('interaction_prefix', "\xF0\x9F\x8F\xA0 ");
      $caption = $prefix !== '' ? $prefix . $_caption : $_caption;
    }

    $params = [
      'instance_id' => $this->getId(),
      'media_path'  => $path,
      'message'     => $caption,
    ];
    if ($_phone !== null && $_phone !== '') {
      $params['phone'] = $_phone;
    }
    if (($p = $this->presenceParam()) !== null) { $params['presence'] = $p; }
    if (($e = $this->ephemeralParam()) !== null) { $params['ephemeral'] = $e; }

    $result = $this->sendToDaemon('sendMedia', $params);
    $this->incrementSentCounters();
    return $result;
  }

  // -------------------------------------------------------------------------
  // Synthèse vocale (TTS) — v0.4 #18
  // Génère une note vocale Opus (.ogg PTT) via Piper + ffmpeg puis l'envoie.
  // $_phone = null → groupe canal ; sinon destinataire direct.
  // -------------------------------------------------------------------------

  public function speak($_text, $_phone = null) {
    $text = trim((string) $_text);
    if ($text === '') {
      throw new Exception(__('Texte à synthétiser vide', __FILE__));
    }
    // Résolution carnet de contacts
    if ($_phone !== null && $_phone !== '') {
      $_phone = $this->resolvePhone($_phone);
    }

    $script = realpath(__DIR__ . '/../../resources/piper/tts.sh');
    if ($script === false || !is_file($script)) {
      throw new Exception(__('Synthèse vocale indisponible : Piper non installé (resources/piper/tts.sh manquant)', __FILE__));
    }

    // Voix optionnelle : chemin absolu d'un modèle .onnx ou nom de fichier dans resources/piper/voices/
    $voice = trim((string) $this->getConfiguration('tts_voice', ''));
    if ($voice !== '' && strpos($voice, '/') === false) {
      $voice = realpath(__DIR__ . '/../../resources/piper/voices/' . $voice) ?: '';
    }

    $tmpDir = jeedom::getTmpFolder('jeewhatsapp');
    if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0775, true); }
    // SECURITY (F-009) : random_bytes non prédictible (vs uniqid)
    $out = $tmpDir . '/tts_' . $this->getId() . '_' . bin2hex(random_bytes(8)) . '.ogg';

    $cmd = escapeshellarg($script) . ' ' . escapeshellarg($text) . ' ' . escapeshellarg($out);
    if ($voice !== '') { $cmd .= ' ' . escapeshellarg($voice); }

    $output = [];
    $rc = 0;
    exec($cmd . ' 2>&1', $output, $rc);

    if ($rc !== 0 || !file_exists($out) || filesize($out) === 0) {
      @unlink($out);
      throw new Exception(
        __('Échec de la synthèse vocale Piper', __FILE__)
        . ' (rc=' . $rc . ') : ' . implode(' ', $output));
    }

    try {
      // Pas de légende → note vocale pure (PTT). L'extension .ogg active ptt:true côté daemon.
      return $this->sendMediaFile($out, '', $_phone);
    } finally {
      @unlink($out);
    }
  }

  // Envoie une réponse en respectant le mode vocal (tts_enabled) : note vocale
  // synthétisée si activé, sinon message texte classique. Fallback texte si la
  // synthèse échoue (Piper absent, voix introuvable…).
  public function sendReply($_text, $_phone = null) {
    if ($this->getConfiguration('tts_enabled', 0) == 1) {
      try {
        return $this->speak($_text, $_phone);
      } catch (Exception $e) {
        log::add('jeewhatsapp', 'warning',
          'jeewhatsapp.class.php::sendReply() l.' . __LINE__
          . ' — synthèse vocale impossible, repli sur texte : ' . $e->getMessage());
      }
    }
    return $this->sendMessage($_text, $_phone);
  }

  // -------------------------------------------------------------------------
  // Réponse "quoted" au dernier message reçu dans le groupe canal
  // -------------------------------------------------------------------------

  public function replyToLast($_message) {
    $prefix  = $this->getConfiguration('interaction_prefix', "\xF0\x9F\x8F\xA0 ");
    $message = $prefix !== '' ? $prefix . $_message : $_message;

    $params = [
      'instance_id' => $this->getId(),
      'message'     => $message,
    ];
    if (($p = $this->presenceParam()) !== null) { $params['presence'] = $p; }
    if (($e = $this->ephemeralParam()) !== null) { $params['ephemeral'] = $e; }
    $result = $this->sendToDaemon('replyLast', $params);
    $this->incrementSentCounters();
    return $result;
  }

  // Retourne 'composing' si l'option de présence (typing) est activée pour cet
  // équipement, sinon null. Lue depuis la config eqLogic presence_enabled (v0.3 #14).
  private function presenceParam() {
    return ((int) $this->getConfiguration('presence_enabled', 0) === 1) ? 'composing' : null;
  }

  // Retourne la durée d'expiration des messages éphémères (en secondes) si l'option
  // est activée pour cet équipement, sinon null. Config eqLogic ephemeral_duration
  // (0=désactivé, 86400=24h, 604800=7j, 7776000=90j). (v0.3 #15)
  private function ephemeralParam() {
    $secs = (int) $this->getConfiguration('ephemeral_duration', 0);
    return $secs > 0 ? $secs : null;
  }

  // v0.3 #16 — Parse la configuration textarea des groupes additionnels.
  // Format attendu : une ligne par groupe « tag=Nom du groupe WhatsApp ».
  // Les lignes vides ou sans « = » sont ignorées. Retourne [{tag, name}, …].
  public static function parseExtraGroups($_raw) {
    $groups = [];
    if (!is_string($_raw) || trim($_raw) === '') { return $groups; }
    foreach (preg_split('/\r\n|\r|\n/', $_raw) as $line) {
      $line = trim($line);
      if ($line === '' || strpos($line, '=') === false) { continue; }
      list($tag, $name) = explode('=', $line, 2);
      $tag  = trim($tag);
      $name = trim($name);
      if ($tag === '' || $name === '') { continue; }
      $groups[] = ['tag' => $tag, 'name' => $name];
    }
    return $groups;
  }

  // -------------------------------------------------------------------------
  // v0.4 #19 — Commandes shortcuts (slash)
  // Parse la config `interaction_shortcuts` (textarea, une ligne par raccourci) :
  //   /trigger = cible
  // La cible est soit :
  //   - une commande unique `#id#`  (action → exécutée ; info → sa valeur est renvoyée)
  //   - un texte modèle pouvant contenir des tags `#id#` d'infos et les placeholders
  //     `#args#` (arguments complets) / `#1#`, `#2#`… (mots d'argument), évalué via
  //     jeedom::evaluateExpression().
  // Retourne une map [trigger_minuscule_sans_slash => cible].
  // -------------------------------------------------------------------------
  public static function parseShortcuts($_raw) {
    $map = [];
    if (!is_string($_raw) || trim($_raw) === '') { return $map; }
    foreach (preg_split('/\r\n|\r|\n/', $_raw) as $line) {
      $line = trim($line);
      if ($line === '' || $line[0] !== '/' || strpos($line, '=') === false) { continue; }
      list($trigger, $target) = explode('=', $line, 2);
      $trigger = strtolower(ltrim(trim($trigger), '/'));
      // un raccourci = un seul token (le premier mot)
      $tokens  = preg_split('/\s+/', $trigger, -1, PREG_SPLIT_NO_EMPTY);
      $trigger = $tokens[0] ?? '';
      $target  = trim($target);
      if ($trigger === '' || $target === '') { continue; }
      $map[$trigger] = $target;
    }
    return $map;
  }

  // Tente de traiter $_message comme un raccourci slash.
  // Retourne :
  //   - null               → ce n'est pas un raccourci (laisser interactQuery gérer)
  //   - ['reply' => texte] → raccourci traité, réponse à renvoyer dans le groupe
  public function handleShortcut($_message) {
    $msg = ltrim((string) $_message);
    if ($msg === '' || $msg[0] !== '/') { return null; }
    $map = self::parseShortcuts($this->getConfiguration('interaction_shortcuts', ''));
    if (empty($map)) { return null; }

    $parts   = preg_split('/\s+/', $msg, 2);
    $trigger = strtolower(ltrim($parts[0], '/'));
    $args    = isset($parts[1]) ? trim($parts[1]) : '';

    if (!isset($map[$trigger])) {
      return ['reply' => '❓ ' . __('Raccourci inconnu : ', __FILE__) . '/' . $trigger];
    }
    $target = $map[$trigger];

    // Cas 1 : cible = une seule commande #id#
    if (preg_match('/^#(\d+)#$/', $target, $m)) {
      $cmd = cmd::byId((int) $m[1]);
      if (!is_object($cmd)) {
        return ['reply' => '⚠️ ' . __('Commande introuvable pour /', __FILE__) . $trigger];
      }
      try {
        if ($cmd->getType() === 'action') {
          $opts = [];
          if ($args !== '') {
            switch ($cmd->getSubType()) {
              case 'message': $opts['message'] = $args; $opts['title'] = ''; break;
              case 'slider':  $opts['slider']  = $args; break;
              case 'color':   $opts['color']   = $args; break;
            }
          }
          $cmd->execute($opts);
          return ['reply' => '✅ ' . $cmd->getHumanName(true, true)];
        }
        // info → renvoie la valeur courante
        $val  = $cmd->execCmd();
        $unit = trim((string) $cmd->getUnite());
        return ['reply' => $cmd->getName() . ' : ' . $val . ($unit !== '' ? ' ' . $unit : '')];
      } catch (Exception $e) {
        log::add('jeewhatsapp', 'error', 'jeewhatsapp.class.php::handleShortcut() l.' . __LINE__
          . ' — Erreur exécution raccourci /' . $trigger . ' : ' . $e->getMessage());
        return ['reply' => '⚠️ ' . __('Erreur sur le raccourci /', __FILE__) . $trigger];
      }
    }

    // Cas 2 : cible = texte modèle (placeholders + tags #id#)
    $tpl   = str_replace('#args#', $args, $target);
    $words = ($args === '') ? [] : preg_split('/\s+/', $args);
    foreach ($words as $i => $w) {
      $tpl = str_replace('#' . ($i + 1) . '#', $w, $tpl);
    }
    try {
      $resolved = jeedom::evaluateExpression($tpl);
    } catch (Exception $e) {
      $resolved = $tpl;
    }
    return ['reply' => (string) $resolved];
  }

  // v0.3 #16 — Envoi d'un message vers un groupe additionnel identifié par son tag.
  public function sendGroup($_tag, $_message) {
    if ($_tag === null || trim($_tag) === '') {
      throw new Exception(__('Tag de groupe manquant pour send_group', __FILE__));
    }
    return $this->sendMessage($_message, null, null, false, trim($_tag));
  }

  // -------------------------------------------------------------------------
  // Recherche du groupe canal par nom — met à jour la configuration
  // -------------------------------------------------------------------------

  public function findGroup($_name = null) {
    $name = ($_name !== null && $_name !== '') ? $_name : $this->getConfiguration('group_name', 'jeewhatsapp');
    $result = $this->sendToDaemon('findGroup', [
      'instance_id' => $this->getId(),
      'message'     => $name,
    ]);
    if (!empty($result['jid'])) {
      $this->setConfiguration('group_jid', $result['jid']);
      $this->save();
    }
    return $result;
  }

  // -------------------------------------------------------------------------
  // Compteurs d'envoi — stockés en cache (TTL 1h), reset à chaque heure
  // -------------------------------------------------------------------------

  public function incrementSentCounters() {
    $now        = time();
    $hour_start = mktime(date('G', $now), 0, 0, date('n', $now), date('j', $now), date('Y', $now));
    $key        = 'jeewhatsapp::sent::' . $this->getId() . '::hour';

    $stored = cache::byKey($key)->getValue(['count' => 0, 'period_start' => 0]);
    if (!is_array($stored)) { $stored = ['count' => 0, 'period_start' => 0]; }

    if (($stored['period_start'] ?? 0) < $hour_start) {
      $counters = ['count' => 1, 'period_start' => $hour_start];
    } else {
      $counters = ['count' => ($stored['count'] ?? 0) + 1, 'period_start' => $stored['period_start']];
    }

    cache::set($key, $counters, 3700);
    $this->checkAndUpdateCmd('sent_hour', $counters['count']);
  }

  // -------------------------------------------------------------------------
  // Mise à jour des cmds info sur réception d'une réaction emoji (v0.2)
  // Une réaction ne déclenche jamais d'interactQuery — seulement les cmds info
  // last_reaction et last_reaction_from + checkAndUpdateCmd qui peut déclencher
  // un scénario "valeur change" si besoin (ex: ❤️ → allume ambiance).
  // -------------------------------------------------------------------------

  public function updateFromReaction($_data) {
    $emoji = (string) ($_data['reaction'] ?? '');
    $from  = (string) ($_data['sender']   ?? '');
    $this->checkAndUpdateCmd('last_reaction',      $emoji);
    $this->checkAndUpdateCmd('last_reaction_from', $from);
    $this->checkAndUpdateCmd('last_reaction_at',   $_data['received_at'] ?? date('Y-m-d H:i:s'));
    log::add('jeewhatsapp', 'info',
      'jeewhatsapp.class.php::updateFromReaction() l.' . __LINE__
      . ' — Réaction ' . ($emoji !== '' ? $emoji : '(retirée)') . ' de ' . $from);
  }

  // -------------------------------------------------------------------------
  // Réception d'un média (image, vidéo, audio, document, sticker) — v0.2
  // Le daemon a déjà téléchargé le fichier dans data/jeewhatsapp/incoming/{eqId}/{date}/
  // On expose chemin + type + mime via 4 cmds info. Mise à jour also de
  // last_message avec la caption (si présente) pour rester cohérent avec les
  // scénarios existants qui surveillent last_message.
  // -------------------------------------------------------------------------

  public function updateFromAttachment($_data) {
    $kind  = (string) ($_data['attachment_kind'] ?? '');
    $mime  = (string) ($_data['attachment_mime'] ?? '');
    $path  = (string) ($_data['attachment_path'] ?? '');
    $size  = (int)    ($_data['attachment_size'] ?? 0);
    $cap   = (string) ($_data['caption'] ?? '');
    $from  = (string) ($_data['sender'] ?? '');
    $name  = (string) ($_data['sender_name'] ?? '');
    $at    = $_data['received_at'] ?? date('Y-m-d H:i:s');

    $this->checkAndUpdateCmd('last_attachment_path', $path);
    $this->checkAndUpdateCmd('last_attachment_type', $kind);
    $this->checkAndUpdateCmd('last_attachment_mime', $mime);
    $this->checkAndUpdateCmd('last_attachment_size', $size);
    // Pour cohérence avec les scénarios existants : last_message contient la
    // caption (vide si pas de légende), last_sender/_name/_received_at sont MAJ
    $this->checkAndUpdateCmd('last_message',     $cap !== '' ? $cap : '[' . $kind . ']');
    $this->checkAndUpdateCmd('last_sender',      $from);
    $this->checkAndUpdateCmd('last_sender_name', $name);
    $this->checkAndUpdateCmd('last_received_at', $at);
    $this->incrementMessagesTodayCounter();
    // Historique widget : média reçu (on stocke le tag type pour l'affichage)
    $this->appendHistory('in', $cap !== '' ? $cap : '[' . $kind . ']', $name !== '' ? $name : $from, $kind);

    log::add('jeewhatsapp', 'info',
      'jeewhatsapp.class.php::updateFromAttachment() l.' . __LINE__
      . ' — Média ' . $kind . ' (' . $mime . ', ' . round($size / 1024) . 'KB) reçu de '
      . $from . ' → ' . $path);

    // OCR (v0.4 #20) : si activé et que le média est une image, extraire le texte
    // via Tesseract et l'exposer dans la cmd info last_ocr_text. Échec silencieux
    // (log warning) pour ne jamais bloquer le traitement de la réception.
    if ($kind === 'image' && $this->getConfiguration('ocr_enabled', 0) == 1) {
      try {
        $text = $this->runOcr($path);
        $this->checkAndUpdateCmd('last_ocr_text', $text);
        log::add('jeewhatsapp', 'info',
          'jeewhatsapp.class.php::updateFromAttachment() l.' . __LINE__
          . ' — OCR : ' . mb_strlen($text) . ' caractère(s) extrait(s)');
      } catch (Exception $e) {
        log::add('jeewhatsapp', 'warning',
          'jeewhatsapp.class.php::updateFromAttachment() l.' . __LINE__
          . ' — OCR impossible : ' . $e->getMessage());
      }
    }

    // STT (v0.4 #17) : si activé et que le média est une note vocale/audio, transcrire
    // via Vosk et l'exposer dans last_voice_text, puis ré-injecter le texte comme un
    // message normal → déclenche raccourcis/interactions (assistant vocal complet :
    // voix entrante → commande Jeedom → réponse texte ou vocale selon tts_enabled).
    if ($kind === 'audio' && $this->getConfiguration('stt_enabled', 0) == 1) {
      try {
        $transcript = $this->transcribe($path);
        $this->checkAndUpdateCmd('last_voice_text', $transcript);
        log::add('jeewhatsapp', 'info',
          'jeewhatsapp.class.php::updateFromAttachment() l.' . __LINE__
          . ' — STT : « ' . $transcript . ' »');
        if ($transcript !== '') {
          // Ré-injection comme message texte (réutilise whitelist/keyword/shortcuts/interactQuery)
          $this->updateFromMessage([
            'event_type'  => 'message',
            'message'     => $transcript,
            'sender'      => $from,
            'sender_name' => $name,
            'received_at' => $at,
            'group_tag'   => $_data['group_tag']  ?? '',
            'group_name'  => $_data['group_name'] ?? '',
          ]);
        }
      } catch (Exception $e) {
        log::add('jeewhatsapp', 'warning',
          'jeewhatsapp.class.php::updateFromAttachment() l.' . __LINE__
          . ' — STT impossible : ' . $e->getMessage());
      }
    }
  }

  // -------------------------------------------------------------------------
  // STT — transcription d'une note vocale via Vosk (offline, local) (v0.4 #17)
  // Retourne le texte reconnu (chaîne, éventuellement vide). Lève une exception
  // si Python/Vosk/le modèle sont absents ou si le script échoue.
  // -------------------------------------------------------------------------

  public function transcribe($_path) {
    $path = trim((string) $_path);
    if ($path === '' || !is_file($path) || !is_readable($path)) {
      throw new Exception(__('Fichier audio introuvable ou non lisible', __FILE__) . ' : ' . $path);
    }

    $script = realpath(__DIR__ . '/../../resources/stt/stt.py');
    if ($script === false || !is_file($script)) {
      throw new Exception(__('Transcription indisponible : Vosk non installé (resources/stt/stt.py manquant)', __FILE__));
    }

    // Modèle optionnel : nom d'un sous-dossier de resources/stt/ ou chemin absolu
    $modelCfg = trim((string) $this->getConfiguration('stt_model', ''));
    $model = '';
    if ($modelCfg !== '') {
      $model = (strpos($modelCfg, '/') === 0)
        ? $modelCfg
        : (realpath(__DIR__ . '/../../resources/stt/' . $modelCfg) ?: '');
    }

    $py = trim((string) shell_exec('command -v python3 2>/dev/null')) ?: 'python3';

    $cmd = escapeshellarg($py) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($path);
    if ($model !== '') { $cmd .= ' ' . escapeshellarg($model); }
    $cmd .= ' 2>/dev/null';

    $output = [];
    $rc = 0;
    exec($cmd, $output, $rc);
    if ($rc !== 0) {
      throw new Exception(__('Échec de la transcription Vosk', __FILE__) . ' (rc=' . $rc . ')');
    }

    return trim(implode(' ', $output));
  }

  // -------------------------------------------------------------------------
  // OCR — extraction de texte d'une image via Tesseract (v0.4 #20)
  // Retourne le texte reconnu (chaîne, éventuellement vide). Lève une exception
  // si Tesseract est absent, le fichier introuvable ou la commande échoue.
  // -------------------------------------------------------------------------

  public function runOcr($_path) {
    $path = trim((string) $_path);
    if ($path === '' || !is_file($path) || !is_readable($path)) {
      throw new Exception(__('Image introuvable ou non lisible', __FILE__) . ' : ' . $path);
    }

    // Localisation du binaire (PATH puis emplacements usuels)
    $bin = trim((string) shell_exec('command -v tesseract 2>/dev/null'));
    if ($bin === '') {
      foreach (['/usr/bin/tesseract', '/usr/local/bin/tesseract'] as $cand) {
        if (is_executable($cand)) { $bin = $cand; break; }
      }
    }
    if ($bin === '') {
      throw new Exception(__('Tesseract non installé (OCR indisponible)', __FILE__));
    }

    // Langue : configurable, validée (lettres/chiffres/+ uniquement pour éviter l'injection)
    $lang = trim((string) $this->getConfiguration('ocr_lang', 'fra'));
    if ($lang === '' || !preg_match('/^[a-zA-Z0-9_+]+$/', $lang)) { $lang = 'fra'; }

    // Sortie sur stdout : tesseract <image> stdout -l <lang>
    $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($path) . ' stdout -l ' . escapeshellarg($lang) . ' 2>/dev/null';
    $output = [];
    $rc = 0;
    exec($cmd, $output, $rc);
    if ($rc !== 0) {
      throw new Exception(__('Échec de Tesseract', __FILE__) . ' (rc=' . $rc . ')');
    }

    return trim(implode("\n", $output));
  }

  // Cron daily : supprime les médias entrants > 30 jours dans data/jeewhatsapp/incoming/
  // Évite l'accumulation infinie. Le seuil 30j est en dur (modifiable plus tard).
  public static function cronCleanupIncoming() {
    // Filet de sécurité : purge les anciennes sauvegardes de session
    // auth/{id}.bak_* même pour les instances jamais re-restaurées et les
    // backups orphelins d'équipements supprimés (que restoreSession() ne
    // toucherait plus). Placé AVANT le return anticipé ci-dessous.
    try {
      $purgedBak = self::pruneSessionBackups();
      if ($purgedBak > 0) {
        log::add('jeewhatsapp', 'info',
          'jeewhatsapp.class.php::cronCleanupIncoming() l.' . __LINE__
          . ' — ' . $purgedBak . ' ancienne(s) sauvegarde(s) de session purgée(s)');
      }
    } catch (Exception $e) {
      log::add('jeewhatsapp', 'warning',
        'jeewhatsapp.class.php::cronCleanupIncoming() l.' . __LINE__ . ' — purge backups : ' . $e->getMessage());
    }

    $base = __DIR__ . '/../../../../data/jeewhatsapp/incoming';
    if (!is_dir($base)) { return; }
    $maxAge  = 30 * 86400;
    $now     = time();
    $deleted = 0;
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $eqDir) {
      foreach (glob($eqDir . '/*', GLOB_ONLYDIR) ?: [] as $dateDir) {
        $dirAge = $now - filemtime($dateDir);
        if ($dirAge <= $maxAge) { continue; }
        // Dossier daté > 30j : supprimer tous les fichiers puis le dossier
        foreach (glob($dateDir . '/*') ?: [] as $f) {
          if (is_file($f) && @unlink($f)) { $deleted++; }
        }
        @rmdir($dateDir);
      }
    }
    if ($deleted > 0) {
      log::add('jeewhatsapp', 'info',
        'jeewhatsapp.class.php::cronCleanupIncoming() l.' . __LINE__
        . ' — ' . $deleted . ' média(s) entrants supprimés (> 30j)');
    }
  }

  // -------------------------------------------------------------------------
  // Envoi d'une réaction emoji sur le dernier message reçu (v0.2)
  // -------------------------------------------------------------------------

  public function reactToLast($_emoji) {
    $emoji = trim((string) $_emoji);
    if ($emoji === '') {
      throw new Exception(__('Emoji obligatoire (ex: coeur, pouce, tada)', __FILE__));
    }
    return $this->sendToDaemon('reactLast', [
      'instance_id' => $this->getId(),
      'message'     => $emoji,
    ]);
  }

  // -------------------------------------------------------------------------
  // Envoi d'un sondage (poll) (v0.3 #9)
  // $_question  : intitulé du sondage
  // $_options   : chaîne "opt1|opt2|opt3" (2 à 12 options)
  // $_selectable: nombre de choix sélectionnables (1 = choix unique)
  // -------------------------------------------------------------------------

  public function sendPoll($_question, $_options, $_selectable = 1, $_phone = null) {
    $question = trim((string) $_question);
    if ($question === '') {
      throw new Exception(__('Question du sondage obligatoire', __FILE__));
    }
    $opts = array_values(array_filter(array_map('trim', explode('|', (string) $_options)), function ($o) { return $o !== ''; }));
    if (count($opts) < 2) {
      throw new Exception(__('Au moins 2 options requises (séparées par |)', __FILE__));
    }
    if (count($opts) > 12) {
      throw new Exception(__('12 options maximum (limite WhatsApp)', __FILE__));
    }
    $params = [
      'instance_id'     => $this->getId(),
      'poll_question'   => $question,
      'poll_options'    => implode('|', $opts),
      'poll_selectable' => max(1, (int) $_selectable),
    ];
    if ($_phone !== null && $_phone !== '') { $params['phone'] = $_phone; }
    if (($e = $this->ephemeralParam()) !== null) { $params['ephemeral'] = $e; }
    $result = $this->sendToDaemon('sendPoll', $params);
    $this->incrementSentCounters();
    return $result;
  }

  // Réception d'un vote de sondage (v0.3 #9) — met à jour les cmds info.
  // poll_results est un JSON [{"name":"…","votes":N}, …] exploitable en scénario.
  public function updateFromPollVote($_data) {
    $this->checkAndUpdateCmd('poll_question', (string) ($_data['poll_name'] ?? ''));
    $this->checkAndUpdateCmd('poll_results',  (string) ($_data['poll_results'] ?? '[]'));
    $this->checkAndUpdateCmd('poll_total',    (int)    ($_data['poll_total'] ?? 0));
    log::add('jeewhatsapp', 'info',
      'jeewhatsapp.class.php::updateFromPollVote() l.' . __LINE__
      . ' — Vote sondage (' . (int) ($_data['poll_total'] ?? 0) . ' vote(s))');
  }

  // -------------------------------------------------------------------------
  // Envoi d'un sticker (v0.3 #10)
  // $_path : chemin absolu d'un .webp (envoyé tel quel) ou image jpg/png/gif
  //          (convertie en WebP 512×512 par le daemon via sharp)
  // -------------------------------------------------------------------------

  public function sendSticker($_path, $_phone = null) {
    if (!is_string($_path) || trim($_path) === '') {
      throw new Exception(__('Chemin du fichier vide', __FILE__));
    }
    $path = trim($_path);
    if (!file_exists($path)) {
      throw new Exception(__('Fichier introuvable', __FILE__) . ' : ' . $path);
    }
    if (!is_readable($path)) {
      throw new Exception(__('Fichier non lisible', __FILE__) . ' : ' . $path);
    }
    $params = [
      'instance_id' => $this->getId(),
      'media_path'  => $path,
    ];
    if ($_phone !== null && $_phone !== '') { $params['phone'] = $_phone; }
    if (($e = $this->ephemeralParam()) !== null) { $params['ephemeral'] = $e; }
    $result = $this->sendToDaemon('sendSticker', $params);
    $this->incrementSentCounters();
    return $result;
  }

  // -------------------------------------------------------------------------
  // Définit l'icône du plugin comme photo de profil du groupe WhatsApp (v0.4)
  // $_tag = null → groupe canal par défaut ; sinon groupe additionnel par tag.
  // Le compte lié doit être administrateur du groupe.
  // -------------------------------------------------------------------------

  public function setGroupIcon($_tag = null, $_iconPath = null) {
    $icon = ($_iconPath !== null && trim((string) $_iconPath) !== '')
      ? trim((string) $_iconPath)
      : realpath(__DIR__ . '/../../plugin_info/jeewhatsapp_icon.png');

    if ($icon === false || !is_file($icon) || !is_readable($icon)) {
      throw new Exception(__('Icône du plugin introuvable', __FILE__) . ' : ' . $icon);
    }

    $params = [
      'instance_id' => $this->getId(),
      'media_path'  => $icon,
    ];
    if ($_tag !== null && trim((string) $_tag) !== '') {
      $params['group_tag'] = trim((string) $_tag);
    }
    return $this->sendToDaemon('setGroupIcon', $params);
  }

  // -------------------------------------------------------------------------
  // Marque le dernier message reçu comme lu (coches bleues) (v0.5 #23)
  // -------------------------------------------------------------------------

  public function markRead() {
    return $this->sendToDaemon('markRead', [
      'instance_id' => $this->getId(),
    ]);
  }

  // -------------------------------------------------------------------------
  // Réception d'un enregistrement vocal du widget (navigateur) (v0.6 #28)
  // Reçoit le blob audio uploadé ($_FILES), le convertit en Opus (.ogg PTT)
  // via ffmpeg, puis l'envoie comme note vocale dans le groupe canal.
  // -------------------------------------------------------------------------

  public function sendVoiceRecording($_file) {
    if (!is_array($_file) || !isset($_file['tmp_name']) || ($_file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($_file['tmp_name'])) {
      throw new Exception(__('Aucun fichier audio reçu', __FILE__));
    }
    if (($_file['size'] ?? 0) > 10 * 1024 * 1024) {
      throw new Exception(__('Enregistrement trop volumineux (max 10 Mo)', __FILE__));
    }

    // SECURITY (F-008) : valider le MIME réel via finfo avant d'invoquer ffmpeg.
    // Bloque l'écriture d'octets arbitraires dans tmp/ même en admin-only.
    $allowedMimes = ['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg',
                     'audio/aac', 'audio/wav', 'audio/x-wav',
                     'video/webm'];   // Chrome envoie parfois video/webm pour de l'audio-only
    if (function_exists('finfo_open')) {
      $finfo = @finfo_open(FILEINFO_MIME_TYPE);
      $mime  = $finfo ? @finfo_file($finfo, $_file['tmp_name']) : '';
      if ($finfo) { finfo_close($finfo); }
      if ($mime !== '' && !in_array($mime, $allowedMimes, true)) {
        throw new Exception(__('Type MIME non autorisé : ', __FILE__) . $mime);
      }
    }

    $tmpDir = jeedom::getTmpFolder('jeewhatsapp');
    if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0775, true); }
    // SECURITY (F-009) : random_bytes au lieu de uniqid (non prédictible)
    $in  = $tmpDir . '/rec_' . $this->getId() . '_' . bin2hex(random_bytes(8));
    $out = $in . '.ogg';
    if (!move_uploaded_file($_file['tmp_name'], $in)) {
      throw new Exception(__('Échec de la réception du fichier audio', __FILE__));
    }

    try {
      $ff = trim((string) shell_exec('command -v ffmpeg 2>/dev/null')) ?: 'ffmpeg';
      $cmd = escapeshellarg($ff) . ' -y -i ' . escapeshellarg($in)
           . ' -c:a libopus -b:a 32k -ar 48000 -ac 1 ' . escapeshellarg($out) . ' 2>/dev/null';
      $o = []; $rc = 0;
      exec($cmd, $o, $rc);
      if ($rc !== 0 || !file_exists($out) || filesize($out) === 0) {
        throw new Exception(__('Conversion audio (ffmpeg) échouée', __FILE__) . ' (rc=' . $rc . ')');
      }
      return $this->sendMediaFile($out, '');
    } finally {
      @unlink($in);
      @unlink($out);
    }
  }

  // -------------------------------------------------------------------------
  // Streame un média entrant vers le navigateur (widget) (v0.6 #28)
  // SECURITY : le chemin demandé est confiné au dossier incoming/{eqId} via
  // realpath (empêche tout accès hors de ce dossier). Réservé à isConnect('admin')
  // côté ajax. Contourne le .htaccess de /data (lecture serveur + readfile).
  // -------------------------------------------------------------------------

  public function streamIncomingMedia($_path) {
    $base = realpath(__DIR__ . '/../../../../data/jeewhatsapp/incoming/' . $this->getId());
    $full = ($_path !== '') ? realpath($_path) : false;
    if ($base === false || $full === false
        || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0
        || !is_file($full) || !is_readable($full)) {
      throw new Exception(__('Média introuvable ou accès refusé', __FILE__));
    }

    $ext   = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $mimes = [
      'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
      'gif' => 'image/gif',  'webp' => 'image/webp',
      'ogg' => 'audio/ogg',  'opus' => 'audio/ogg', 'mp3' => 'audio/mpeg',
      'm4a' => 'audio/mp4',  'aac' => 'audio/aac',   'wav' => 'audio/wav',
      'mp4' => 'video/mp4',  '3gp' => 'video/3gpp',  'pdf' => 'application/pdf',
    ];
    $mime = $mimes[$ext] ?? 'application/octet-stream';

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($full));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($full);
  }

  // -------------------------------------------------------------------------
  // Sauvegarde/restauration chiffrée de la session Baileys (v0.5 #26)
  // Permet de restaurer la connexion après réinstallation sans re-scanner le QR.
  // Archive tar (PharData) → 100 % portable.
  //
  // Format JWAB3 (actuel, AES-256-GCM AUTHENTIFIÉ) :
  //   "JWAB3" (5o) + salt(16) + iv(12) + tag(16) + cipher
  //   Clé dérivée par PBKDF2-SHA256, 200 000 itérations.
  //   AEAD : toute modification du fichier déclenche un échec de déchiffrement.
  //
  // Format JWAB2 (legacy v0.5+correctif F-003) :
  //   "JWAB2" (5o) + salt(16) + iv(16) + cipher (AES-256-CBC + PBKDF2 200k).
  //   Lu en lecture seule ; nouvelles sauvegardes en JWAB3.
  //
  // Format JWAB1 (legacy v0.5 initial) :
  //   "JWAB1" (5o) + IV(16) + cipher (AES-256-CBC + sha256 brut).
  //   Lu en lecture seule ; KDF vulnérable au brute-force.
  // -------------------------------------------------------------------------

  const BACKUP_KDF_ITERATIONS = 200000;

  // Nombre maximum de sauvegardes de session `{id}.bak_*` conservées par
  // instance (la plus récente d'abord). Les .bak_ sont créés par restoreSession()
  // avant chaque écrasement ; sans purge ils s'accumulent indéfiniment dans
  // resources/jeewhatsappd/auth/ et gonflent les sauvegardes Jeedom (auth/ n'en
  // est pas exclu) tout en y laissant traîner des credentials WhatsApp.
  const MAX_SESSION_BACKUPS = 1;

  // Dossier parent des sessions Baileys (un sous-dossier par équipement + les
  // backups `{id}.bak_*`). Source unique du chemin auth/ (statique pour être
  // réutilisable depuis les crons et la purge sans instance).
  private static function authBaseDir() {
    return __DIR__ . '/../../resources/jeewhatsappd/auth';
  }

  private function authDir() {
    return self::authBaseDir() . '/' . $this->getId();
  }

  // -------------------------------------------------------------------------
  // Purge des anciennes sauvegardes de session `{id}.bak_YYYYmmddHHMMSS`.
  // Ne conserve que les $_keep plus récentes par instance (la plus récente
  // d'abord). Appelée après chaque restauration réussie et par le cron daily.
  //
  // $_id  : limite la purge à cette instance (null = balayage de toutes).
  // $_keep: nombre de backups conservés par instance (défaut MAX_SESSION_BACKUPS).
  // Retourne le nombre de dossiers supprimés.
  // -------------------------------------------------------------------------

  public static function pruneSessionBackups($_id = null, $_keep = self::MAX_SESSION_BACKUPS) {
    return self::pruneSessionBackupsIn(self::authBaseDir(), $_id, $_keep);
  }

  // Cœur testable de la purge — opère sur un dossier parent arbitraire, sans
  // dépendance Jeedom (pas de log::, pas de config::), pour pouvoir être validé
  // en isolation. Ne PAS appeler directement : passer par pruneSessionBackups().
  private static function pruneSessionBackupsIn($_parent, $_id = null, $_keep = self::MAX_SESSION_BACKUPS) {
    if (!is_dir($_parent)) { return 0; }
    $keep = max(0, (int) $_keep);

    // Filtre STRICT : `{id}.bak_` suivi d'EXACTEMENT 14 chiffres (date YmdHis).
    // - les sessions actives (`{id}` numérique seul, sans suffixe) ne matchent jamais ;
    // - un id différent dont le préfixe coïnciderait (5 vs 55) est exclu car l'id
    //   est ancré (^...) et suivi obligatoirement de `.bak_` ;
    // - tout nom non conforme (`{id}.bak_notadate`, `{id}.backup_…`) est ignoré.
    // L'id est capturé en groupe 1 pour regrouper les backups par instance.
    $idPat = ($_id === null) ? '(\d+)' : '(' . preg_quote((string) $_id, '#') . ')';
    $regex = '#^' . $idPat . '\.bak_(\d{14})$#';

    $byId = [];
    foreach (scandir($_parent) as $entry) {
      if ($entry === '.' || $entry === '..') { continue; }
      if (!is_dir($_parent . '/' . $entry)) { continue; }
      if (!preg_match($regex, $entry, $m)) { continue; }
      $byId[$m[1]][] = $entry;
    }

    $deleted = 0;
    foreach ($byId as $dirs) {
      if (count($dirs) <= $keep) { continue; }
      // Suffixe YmdHis lexicographiquement ordonnable → tri décroissant =
      // du plus récent au plus ancien. On garde les $keep premiers.
      rsort($dirs);
      foreach (array_slice($dirs, $keep) as $old) {
        if (self::rrmdir($_parent . '/' . $old)) { $deleted++; }
      }
    }
    return $deleted;
  }

  // Suppression récursive best-effort d'un dossier et de son contenu.
  // Retourne true si le dossier n'existe plus à la fin. Ne suit pas les liens
  // symboliques (les supprime sans descendre dedans).
  private static function rrmdir($_dir) {
    if (is_link($_dir)) { return @unlink($_dir); }
    if (!is_dir($_dir)) { return !file_exists($_dir); }
    $items = @scandir($_dir);
    if ($items === false) { return false; }
    foreach ($items as $item) {
      if ($item === '.' || $item === '..') { continue; }
      $path = $_dir . '/' . $item;
      if (is_dir($path) && !is_link($path)) {
        self::rrmdir($path);
      } else {
        @unlink($path);
      }
    }
    return @rmdir($_dir);
  }

  public function backupSession($_passphrase) {
    $pass = (string) $_passphrase;
    if (strlen($pass) < 6) {
      throw new Exception(__('Phrase de passe trop courte (6 caractères minimum)', __FILE__));
    }
    $authDir = realpath($this->authDir());
    if ($authDir === false || !is_dir($authDir)) {
      throw new Exception(__('Aucune session à sauvegarder (équipement jamais connecté ?)', __FILE__));
    }

    $tmp = jeedom::getTmpFolder('jeewhatsapp');
    if (!is_dir($tmp)) { @mkdir($tmp, 0775, true); }
    $tarPath = $tmp . '/session_' . $this->getId() . '_' . bin2hex(random_bytes(8)) . '.tar';
    @unlink($tarPath);

    $configFile = $authDir . '/_jwa_config.json';
    try {
      // Inclut le carnet de contacts (stocké en DB, pas dans auth/) dans l'archive
      file_put_contents($configFile, json_encode(
        ['contacts' => $this->getConfiguration('contacts', '')],
        JSON_UNESCAPED_UNICODE
      ));
      $phar = new PharData($tarPath);
      $phar->buildFromDirectory($authDir);
      unset($phar);
      $plain = file_get_contents($tarPath);
    } finally {
      @unlink($tarPath);
      @unlink($configFile);
    }
    if ($plain === false || $plain === '') {
      throw new Exception(__('Archive de session vide', __FILE__));
    }

    // SECURITY (F-003 + F-007) : PBKDF2-SHA256 + sel + AES-256-GCM (AEAD).
    // GCM ajoute un tag d'authentification (16 o) qui détecte toute altération
    // du ciphertext. IV GCM = 12 octets (recommandation NIST SP800-38D).
    $salt   = random_bytes(16);
    $iv     = random_bytes(12);
    $key    = hash_pbkdf2('sha256', $pass, $salt, self::BACKUP_KDF_ITERATIONS, 32, true);
    $tag    = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false || $tag === '') {
      throw new Exception(__('Échec du chiffrement de la session', __FILE__));
    }
    return 'JWAB3' . $salt . $iv . $tag . $cipher;
  }

  public function restoreSession($_passphrase, $_blob) {
    $pass = (string) $_passphrase;
    if (strlen($pass) < 6) {
      throw new Exception(__('Phrase de passe trop courte', __FILE__));
    }
    if (!is_string($_blob) || strlen($_blob) < 5 + 16 + 16) {
      throw new Exception(__('Fichier de sauvegarde invalide', __FILE__));
    }
    $magic = substr($_blob, 0, 5);
    $plain = false;
    if ($magic === 'JWAB3') {
      // Format actuel (F-007) : magic + salt(16) + iv(12) + tag(16) + cipher
      if (strlen($_blob) < 5 + 16 + 12 + 16 + 16) {
        throw new Exception(__('Fichier de sauvegarde invalide (JWAB3 tronqué)', __FILE__));
      }
      $salt   = substr($_blob, 5, 16);
      $iv     = substr($_blob, 21, 12);
      $tag    = substr($_blob, 33, 16);
      $cipher = substr($_blob, 49);
      $key    = hash_pbkdf2('sha256', $pass, $salt, self::BACKUP_KDF_ITERATIONS, 32, true);
      $plain  = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    } elseif ($magic === 'JWAB2') {
      // Legacy F-003 : magic + salt(16) + iv(16) + cipher (CBC, pas d'authenticité)
      if (strlen($_blob) < 5 + 16 + 16 + 16) {
        throw new Exception(__('Fichier de sauvegarde invalide (JWAB2 tronqué)', __FILE__));
      }
      $salt   = substr($_blob, 5, 16);
      $iv     = substr($_blob, 21, 16);
      $cipher = substr($_blob, 37);
      $key    = hash_pbkdf2('sha256', $pass, $salt, self::BACKUP_KDF_ITERATIONS, 32, true);
      $plain  = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
      log::add('jeewhatsapp', 'info',
        'jeewhatsapp.class.php::restoreSession() l.' . __LINE__
        . ' — Sauvegarde JWAB2 (legacy CBC) restaurée — la prochaine sera ré-encodée en JWAB3 (GCM)');
    } elseif ($magic === 'JWAB1') {
      // Legacy v0.5 initial : magic + iv(16) + cipher (CBC + sha256 brut)
      $iv     = substr($_blob, 5, 16);
      $cipher = substr($_blob, 21);
      $key    = hash('sha256', $pass, true);
      $plain  = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
      log::add('jeewhatsapp', 'info',
        'jeewhatsapp.class.php::restoreSession() l.' . __LINE__
        . ' — Sauvegarde JWAB1 (legacy KDF faible) restaurée — pensez à la régénérer en JWAB3');
    } else {
      throw new Exception(__('Fichier de sauvegarde invalide (magic inconnu)', __FILE__));
    }
    if ($plain === false || $plain === '') {
      throw new Exception(__('Déchiffrement impossible — phrase de passe incorrecte ou fichier corrompu', __FILE__));
    }

    $tmp = jeedom::getTmpFolder('jeewhatsapp');
    if (!is_dir($tmp)) { @mkdir($tmp, 0775, true); }
    $tarPath = $tmp . '/restore_' . $this->getId() . '_' . bin2hex(random_bytes(8)) . '.tar';
    if (file_put_contents($tarPath, $plain) === false) {
      throw new Exception(__('Écriture de l\'archive de restauration impossible', __FILE__));
    }

    try {
      // Valide que c'est bien une archive tar
      $phar = new PharData($tarPath);

      // SECURITY (F-001, CWE-22 Tar Slip) : valider explicitement chaque entrée
      // de l'archive avant extraction — PharData::extractTo ne refuse pas
      // systématiquement les chemins contenant `..` selon les versions de PHP.
      // Un fichier .jwab forgé pourrait sinon écrire en dehors de auth/{id}/.
      $pharPrefix = 'phar://' . $tarPath . '/';
      foreach (new RecursiveIteratorIterator($phar) as $entry) {
        $rel = str_replace('\\', '/', $entry->getPathname());
        if (strpos($rel, $pharPrefix) === 0) { $rel = substr($rel, strlen($pharPrefix)); }
        if ($rel === '' || $rel[0] === '/' || strpos($rel, "\0") !== false
            || preg_match('#(^|/)\.\.(/|$)#', $rel)) {
          throw new Exception(__('Archive de restauration invalide : chemin suspect détecté (', __FILE__) . $rel . ')');
        }
      }

      $authDir = $this->authDir();
      // Sauvegarde de l'éventuelle session existante avant écrasement
      if (is_dir($authDir)) {
        @rename($authDir, $authDir . '.bak_' . date('YmdHis'));
      }
      if (!is_dir($authDir)) { @mkdir($authDir, 0700, true); }

      $phar->extractTo($authDir, null, true);
      unset($phar);
      @chmod($authDir, 0700);

      // Restaure le carnet de contacts s'il est présent dans l'archive (JWAB3+)
      $contactsFile = $authDir . '/_jwa_config.json';
      if (is_file($contactsFile)) {
        $cfg = json_decode(file_get_contents($contactsFile), true);
        if (is_array($cfg) && array_key_exists('contacts', $cfg)) {
          $this->setConfiguration('contacts', $cfg['contacts']);
          $this->save();
          log::add('jeewhatsapp', 'info',
            'jeewhatsapp.class.php::restoreSession() l.' . __LINE__
            . ' — Carnet de contacts restauré pour l\'instance ' . $this->getId());
        }
        @unlink($contactsFile);
      }
    } catch (Exception $e) {
      @unlink($tarPath);
      throw new Exception(__('Archive de restauration illisible : ', __FILE__) . $e->getMessage());
    }
    @unlink($tarPath);

    // Purge des anciennes sauvegardes de session : on ne conserve que la plus
    // récente (celle créée juste au-dessus, état pré-restauration utile pour un
    // rollback). Best-effort — un échec de purge ne doit pas faire échouer une
    // restauration par ailleurs réussie.
    try {
      $purged = self::pruneSessionBackups($this->getId());
      if ($purged > 0) {
        log::add('jeewhatsapp', 'info',
          'jeewhatsapp.class.php::restoreSession() l.' . __LINE__
          . ' — ' . $purged . ' ancienne(s) sauvegarde(s) de session purgée(s) pour l\'instance ' . $this->getId());
      }
    } catch (Exception $e) {
      log::add('jeewhatsapp', 'warning',
        'jeewhatsapp.class.php::restoreSession() l.' . __LINE__ . ' — purge backups : ' . $e->getMessage());
    }

    // Arrête le daemon pour qu'il recharge les credentials restaurés au prochain démarrage.
    // On ne redémarre PAS ici : deamon_start() bloque ~60 s en AJAX (timeout HTTP)
    // et un double-démarrage (UI + background) cause une désynchronisation du daemon_secret.
    // Le message JS demande à l'utilisateur de relancer le daemon depuis la page du plugin.
    try {
      self::deamon_stop();
    } catch (Exception $e) {
      log::add('jeewhatsapp', 'warning', 'jeewhatsapp.class.php::restoreSession() l.' . __LINE__ . ' — arrêt daemon : ' . $e->getMessage());
    }
    return ['restored' => true, 'restart_required' => true];
  }

  // -------------------------------------------------------------------------
  // Archive / épingle / met en sourdine la conversation du groupe canal (v0.5 #24)
  // $_op : archive|unarchive|pin|unpin|mute|unmute
  // $_value : pour mute, durée en heures (null = 8h par défaut)
  // $_tag : groupe additionnel optionnel (null = groupe canal)
  // -------------------------------------------------------------------------

  public function chatModify($_op, $_value = null, $_tag = null) {
    $params = [
      'instance_id' => $this->getId(),
      'chat_op'     => $_op,
    ];
    if ($_value !== null && trim((string) $_value) !== '') {
      $params['chat_value'] = $_value;
    }
    if ($_tag !== null && trim((string) $_tag) !== '') {
      $params['group_tag'] = trim((string) $_tag);
    }
    return $this->sendToDaemon('chatModify', $params);
  }

  // -------------------------------------------------------------------------
  // Publie un statut WhatsApp éphémère 24h (texte ou image) (v0.5 #25)
  // $_text  : texte du statut (ou légende si image)
  // $_image : chemin absolu d'une image optionnelle (statut image)
  // L'audience est construite côté daemon (participants du groupe canal).
  // -------------------------------------------------------------------------

  public function postStatus($_text = '', $_image = null) {
    $params = ['instance_id' => $this->getId()];
    if ($_image !== null && trim((string) $_image) !== '') {
      $img = trim((string) $_image);
      if (!is_file($img) || !is_readable($img)) {
        throw new Exception(__('Image introuvable ou non lisible', __FILE__) . ' : ' . $img);
      }
      $params['media_path'] = $img;
    }
    if ($_text !== null && trim((string) $_text) !== '') {
      $params['message'] = $_text;
    }
    if (!isset($params['media_path']) && !isset($params['message'])) {
      throw new Exception(__('Statut vide : fournir un texte ou une image', __FILE__));
    }
    $result = $this->sendToDaemon('postStatus', $params);
    $this->incrementSentCounters();
    return $result;
  }

  // -------------------------------------------------------------------------
  // Gestion du groupe canal (v0.5 #22) — opérations d'administration
  // $_op : add|remove|promote|demote|subject|description|inviteLink|revokeInvite|leave
  // $_value : numéro (ops participant) ou texte (subject/description)
  // $_tag : groupe additionnel optionnel (null = groupe canal)
  // Le compte WhatsApp lié doit être administrateur du groupe.
  // -------------------------------------------------------------------------

  public function groupAction($_op, $_value = null, $_tag = null) {
    $op = trim((string) $_op);
    if ($op === '') {
      throw new Exception(__('Opération de groupe manquante', __FILE__));
    }
    $params = [
      'instance_id' => $this->getId(),
      'group_op'    => $op,
    ];
    if (in_array($op, ['add', 'remove', 'promote', 'demote'], true)) {
      $phone = self::normalizePhone((string) $_value);
      if ($phone === '') {
        throw new Exception(__('Numéro du participant requis', __FILE__));
      }
      $params['phone'] = $phone;
    } elseif (in_array($op, ['subject', 'description'], true)) {
      $params['message'] = (string) $_value;
    }
    if ($_tag !== null && trim((string) $_tag) !== '') {
      $params['group_tag'] = trim((string) $_tag);
    }
    return $this->sendToDaemon('groupAction', $params);
  }

  // -------------------------------------------------------------------------
  // Édition du dernier message envoyé par Jeedom (v0.3 #11)
  // -------------------------------------------------------------------------

  public function editLast($_newText) {
    $text = trim((string) $_newText);
    if ($text === '') {
      throw new Exception(__('Nouveau texte obligatoire', __FILE__));
    }
    // Réapplique le préfixe Jeedom comme pour un envoi normal
    $prefix = $this->getConfiguration('interaction_prefix', "\xF0\x9F\x8F\xA0 ");
    $text   = $prefix !== '' ? $prefix . $text : $text;
    return $this->sendToDaemon('editLast', [
      'instance_id' => $this->getId(),
      'message'     => $text,
    ]);
  }

  // -------------------------------------------------------------------------
  // Suppression "pour tous" du dernier message envoyé (v0.3 #12)
  // -------------------------------------------------------------------------

  public function revokeLast() {
    return $this->sendToDaemon('revokeLast', [
      'instance_id' => $this->getId(),
    ]);
  }

  // -------------------------------------------------------------------------
  // Transfert du dernier message reçu vers un destinataire (v0.3 #13)
  // $_phone : null/'' = groupe canal, sinon numéro ou JID
  // -------------------------------------------------------------------------

  public function forwardLastTo($_phone = null) {
    $params = ['instance_id' => $this->getId()];
    if ($_phone !== null && $_phone !== '') { $params['phone'] = $_phone; }
    if (($e = $this->ephemeralParam()) !== null) { $params['ephemeral'] = $e; }
    $result = $this->sendToDaemon('forwardTo', $params);
    $this->incrementSentCounters();
    return $result;
  }

  // -------------------------------------------------------------------------
  // Whitelist : vérifie si un sender est autorisé à déclencher des interactions
  // - Whitelist vide → tout le monde est autorisé (comportement legacy v0.1)
  // - Sinon normalisation (chiffres uniquement) + conversion FR 0X → 33X côté
  //   stockage et côté sender pour matcher peu importe le format saisi
  // -------------------------------------------------------------------------

  public static function isSenderAllowed($_eqLogic, $_sender) {
    $raw = trim((string) $_eqLogic->getConfiguration('interaction_whitelist', ''));
    if ($raw === '') { return true; } // pas de whitelist = tout passe
    $normalized = self::normalizePhone($_sender);
    if ($normalized === '') { return false; }
    // Sépare par virgule OU saut de ligne (textarea ou csv)
    $entries = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($entries as $entry) {
      $n = self::normalizePhone($entry);
      if ($n !== '' && $n === $normalized) { return true; }
    }
    return false;
  }

  // -------------------------------------------------------------------------
  // v0.4 #21 — Reconnaissance utilisateur
  // Parse la config `user_mapping` (textarea, une ligne `numéro=Profil`) :
  //   numéro = identifiant WhatsApp (06…, 336…, JID complet — normalisé en chiffres)
  //   Profil = nom de profil Jeedom (ou simple libellé) associé à cet expéditeur
  // Retourne une map [numéro_normalisé => profil].
  // -------------------------------------------------------------------------
  public static function parseUserMapping($_raw) {
    $map = [];
    if (!is_string($_raw) || trim($_raw) === '') { return $map; }
    foreach (preg_split('/\r\n|\r|\n/', $_raw) as $line) {
      $line = trim($line);
      if ($line === '' || strpos($line, '=') === false) { continue; }
      list($num, $profile) = explode('=', $line, 2);
      $num     = self::normalizePhone($num);
      $profile = trim($profile);
      if ($num === '' || $profile === '') { continue; }
      $map[$num] = $profile;
    }
    return $map;
  }

  // Résout le profil Jeedom associé à un expéditeur via `user_mapping`.
  // Retourne le profil mappé, ou null si aucun mapping ne correspond.
  public function resolveSenderProfile($_sender) {
    $map = self::parseUserMapping($this->getConfiguration('user_mapping', ''));
    if (empty($map)) { return null; }
    $n = self::normalizePhone($_sender);
    return ($n !== '' && isset($map[$n])) ? $map[$n] : null;
  }

  // Normalise un numéro WhatsApp : ne garde que les chiffres, convertit 0[67]XXXXXXXX → 336/337XXXXXXXX
  public static function normalizePhone($_phone) {
    $digits = preg_replace('/\D/', '', (string) $_phone);
    if ($digits === null) { return ''; }
    if (preg_match('/^0([67]\d{8})$/', $digits, $m)) {
      $digits = '33' . $m[1];
    }
    return $digits;
  }

  // -------------------------------------------------------------------------
  // Carnet de contacts — résolution Nom → Numéro
  // -------------------------------------------------------------------------

  // Parse la configuration "contacts" (format "Nom=Numéro", une entrée par ligne).
  // Retourne un tableau associatif insensible à la casse : ['didier' => '33612345678', ...]
  public static function parseContacts($_raw) {
    $result = [];
    foreach (preg_split('/\r?\n/', (string) $_raw) as $line) {
      $line = trim($line);
      if ($line === '' || $line[0] === '#') { continue; }
      $pos = strpos($line, '=');
      if ($pos === false) { continue; }
      $name   = trim(substr($line, 0, $pos));
      $number = trim(substr($line, $pos + 1));
      if ($name !== '' && $number !== '') {
        $result[strtolower($name)] = $number;
      }
    }
    return $result;
  }

  // Résout un destinataire : si $_phone correspond à un nom du carnet de contacts
  // (insensible à la casse), retourne le numéro associé ; sinon retourne $_phone tel quel.
  public function resolvePhone($_phone) {
    if ($_phone === null || trim((string) $_phone) === '') { return $_phone; }
    $contacts = self::parseContacts($this->getConfiguration('contacts', ''));
    $key = strtolower(trim((string) $_phone));
    if (isset($contacts[$key])) {
      log::add('jeewhatsapp', 'debug',
        'jeewhatsapp.class.php::resolvePhone() — contact résolu : "' . $_phone . '" → "' . $contacts[$key] . '"');
      return $contacts[$key];
    }
    return $_phone;
  }

  // -------------------------------------------------------------------------
  // Compteur de messages reçus aujourd'hui (cache jusqu'à minuit local)
  // -------------------------------------------------------------------------

  public function incrementMessagesTodayCounter() {
    $key       = 'jeewhatsapp::msg_today::' . $this->getId();
    $stored    = (int) cache::byKey($key)->getValue(0);
    $now       = time();
    $tomorrow0 = strtotime('tomorrow 00:00:00');
    $ttl       = max(60, $tomorrow0 - $now);
    $new       = $stored + 1;
    cache::set($key, $new, $ttl);
    $this->checkAndUpdateCmd('messages_today', $new);
    return $new;
  }

  // Cron daily (cf install.php) : reset à 00:00 même si aucun message reçu pendant 24h.
  // Utile pour rafraîchir la commande info à 0 chaque minuit.
  public static function cronResetMessagesToday() {
    foreach (eqLogic::byType(__CLASS__) as $eqLogic) {
      if (!$eqLogic->getIsEnable()) { continue; }
      $key = 'jeewhatsapp::msg_today::' . $eqLogic->getId();
      cache::set($key, 0, 86400);
      $eqLogic->checkAndUpdateCmd('messages_today', 0);
    }
    log::add('jeewhatsapp', 'info', 'jeewhatsapp.class.php::cronResetMessagesToday() l.' . __LINE__ . ' — Compteurs messages_today remis à zéro');
  }

  // Cron 5 min : interroge le daemon pour rafraîchir la cmd info connected_since.
  // getConnectionStatus() met aussi à jour la cmd automatiquement.
  public static function cronRefreshStatus() {
    foreach (eqLogic::byType(__CLASS__) as $eqLogic) {
      if (!$eqLogic->getIsEnable()) { continue; }
      try {
        $eqLogic->getConnectionStatus();
      } catch (Exception $e) {
        // Daemon down ou autre : on ignore silencieusement (log debug)
        log::add('jeewhatsapp', 'debug', 'jeewhatsapp.class.php::cronRefreshStatus() l.' . __LINE__ . ' — Daemon indisponible pour eqLogic #' . $eqLogic->getId());
      }
    }
  }

  // -------------------------------------------------------------------------
  // Historique de conversation pour le widget (50 messages max)
  // Fichier : resources/jeewhatsappd/auth/{id}/history.json
  // Chaque entrée : {dir:'in'|'out', text, sender, time, kind}
  // -------------------------------------------------------------------------

  private function historyFile() {
    return __DIR__ . '/../../resources/jeewhatsappd/auth/' . $this->getId() . '/history.json';
  }

  public function appendHistory($_dir, $_text, $_sender = '', $_kind = 'text') {
    $file = $this->historyFile();
    $dir  = dirname($file);
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }

    $history = [];
    if (is_file($file)) {
      $raw = @file_get_contents($file);
      if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) { $history = $decoded; }
      }
    }

    $history[] = [
      'dir'    => $_dir,
      'text'   => (string) $_text,
      'sender' => (string) $_sender,
      'time'   => date('H:i'),
      'date'   => date('Y-m-d H:i:s'),
      'kind'   => $_kind,
    ];

    // Conserver les 50 derniers messages uniquement
    if (count($history) > 50) {
      $history = array_slice($history, -50);
    }

    @file_put_contents($file, json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  // -------------------------------------------------------------------------
  // QR code — récupéré depuis le daemon
  // -------------------------------------------------------------------------

  public function getQRData() {
    return $this->sendToDaemon('getQR', ['instance_id' => $this->getId()]);
  }

  public function getConnectionStatus() {
    $st = $this->sendToDaemon('getStatus', ['instance_id' => $this->getId()]);
    // Mise à jour automatique de la cmd info connected_since si disponible
    if (isset($st['connected_since']) && $st['connected_since']) {
      // ISO 8601 → format Jeedom YYYY-MM-DD HH:MM:SS
      $ts = strtotime($st['connected_since']);
      if ($ts !== false) {
        $this->checkAndUpdateCmd('connected_since', date('Y-m-d H:i:s', $ts));
      }
    }
    // P2 — expose le nombre de messages en file d'attente (outbox) du daemon
    if (isset($st['outbox_pending'])) {
      $this->checkAndUpdateCmd('outbox_pending', (int) $st['outbox_pending']);
    }
    return $st;
  }

  // -------------------------------------------------------------------------
  // Déconnexion propre du compte WhatsApp
  // 1) demande au daemon de faire un logout Baileys (délie l'appareil côté
  //    téléphone) + supprime les credentials locaux auth/{id}/
  // 2) nettoie les données locales liées au compte côté Jeedom :
  //    - group_jid en configuration (le groupe canal n'est plus résolu)
  //    - cmds info volatiles (dernier message, statut de connexion, etc.)
  //    - compteurs en cache
  // Après ça, un nouveau QR code est nécessaire pour se reconnecter.
  // -------------------------------------------------------------------------
  public function logout() {
    $result = ['logged_out' => false, 'files_removed' => 0];
    // 1) logout + nettoyage côté daemon (best effort : on continue même si KO)
    try {
      $result = $this->sendToDaemon('logout', ['instance_id' => $this->getId()]);
    } catch (Exception $e) {
      log::add('jeewhatsapp', 'warning',
        'jeewhatsapp.class.php::logout() l.' . __LINE__
        . ' — logout daemon : ' . $e->getMessage() . ' (nettoyage local poursuivi)');
    }

    // 2) nettoyage des données locales Jeedom liées au compte
    $this->setConfiguration('group_jid', '');
    $this->save();

    // Réinitialise les cmds info volatiles (état du compte/connexion)
    foreach (['last_message', 'last_sender', 'last_sender_name', 'last_sender_profile',
              'last_received_at', 'last_group', 'last_group_name', 'last_voice_text',
              'last_ocr_text', 'last_attachment_path', 'last_attachment_type',
              'last_attachment_mime', 'connected_since'] as $logicalId) {
      $cmd = $this->getCmd('info', $logicalId);
      if (is_object($cmd)) { $cmd->event(''); }
    }

    // Purge des compteurs en cache
    foreach (['jeewhatsapp::sent::' . $this->getId() . '::hour',
              'jeewhatsapp::msg_today::' . $this->getId()] as $cacheKey) {
      try { cache::delete($cacheKey); } catch (Exception $e) {}
    }

    log::add('jeewhatsapp', 'info',
      'jeewhatsapp.class.php::logout() l.' . __LINE__
      . ' — Compte WhatsApp déconnecté et données locales nettoyées (eqLogic #' . $this->getId() . ')');
    return $result;
  }

  public function createGroup($_name = null) {
    $name = ($_name !== null && $_name !== '') ? $_name : $this->getConfiguration('group_name', 'jeewhatsapp');
    $result = $this->sendToDaemon('createGroup', [
      'instance_id' => $this->getId(),
      'message'     => $name,
    ]);
    if (!empty($result['jid'])) {
      $this->setConfiguration('group_jid', $result['jid']);
      $this->save();
    }
    return $result;
  }

  // -------------------------------------------------------------------------
  // Communication avec le daemon
  // -------------------------------------------------------------------------

  // SECURITY (F-009, CWE-532): masque les champs sensibles d'un payload daemon
  // avant log debug. Conserve la structure pour debug efficace mais retire les PII.
  private static function redactPayloadForLog($_action, $_params) {
    $clone = $_params;
    foreach (['message', 'caption'] as $k) {
      if (isset($clone[$k]) && is_string($clone[$k]) && strlen($clone[$k]) > 8) {
        $clone[$k] = substr($clone[$k], 0, 8) . '…(' . strlen($clone[$k]) . ' chars)';
      }
    }
    foreach (['phone', 'mention', 'contact_phone'] as $k) {
      if (isset($clone[$k]) && is_string($clone[$k]) && strlen($clone[$k]) > 4) {
        $digits = preg_replace('/\D/', '', $clone[$k]);
        $clone[$k] = strlen($digits) > 4
          ? substr($digits, 0, 2) . '…' . substr($digits, -2)
          : '****';
      }
    }
    return json_encode(array_merge(['action' => $_action], $clone));
  }

  // Vide le flux live (buffer mémoire daemon + fichier events.json) (#31)
  public function clearLiveEvents() {
    return $this->sendToDaemon('clearEvents', ['instance_id' => $this->getId()]);
  }

  private function sendToDaemon($_action, $_params = []) {
    $url     = 'http://127.0.0.1:' . self::getPort() . '/action';
    $payload = json_encode(array_merge(['action' => $_action], $_params));

    // SECURITY (F-009, CWE-532): masquer les données sensibles dans les logs debug
    // Le payload complet contient potentiellement messages WhatsApp + numéros téléphones
    $logPayload = self::redactPayloadForLog($_action, $_params);
    log::add('jeewhatsapp', 'debug', 'jeewhatsapp.class.php::sendToDaemon() — ' . $_action . ' → ' . $logPayload);

    // SECURITY (F-004, CWE-306): header X-Daemon-Secret authentifie la requête locale.
    // Le daemon refuse 401 sans header valide → empêche un process local malveillant
    // d'envoyer des messages WhatsApp via le HTTP daemon bindé sur 127.0.0.1.
    $secret = cache::byKey('jeewhatsapp::daemon_secret')->getValue('');
    $headers = "Content-Type: application/json\r\n";
    if ($secret !== '') {
      $headers .= "X-Daemon-Secret: " . $secret . "\r\n";
    }
    // ignore_errors permet de lire le corps même sur HTTP 4xx/5xx (erreurs renvoyées par le daemon)
    $opts = ['http' => [
      'method'        => 'POST',
      'header'        => $headers,
      'content'       => $payload,
      'timeout'       => 15,
      'ignore_errors' => true,
    ]];
    // @ supprime le warning PHP si la connexion échoue (remplacé par l'exception ci-dessous)
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    if ($raw === false) {
      throw new Exception(__('Daemon non joignable sur le port ', __FILE__) . self::getPort() . __(' — vérifiez qu\'il est démarré', __FILE__));
    }
    // SECURITY (F-009): tronquer la réponse à 200 chars (peut contenir un QR base64, etc.)
    $logRaw = strlen($raw) > 200 ? substr($raw, 0, 200) . '…(' . strlen($raw) . ' bytes)' : $raw;
    log::add('jeewhatsapp', 'debug', 'jeewhatsapp.class.php::sendToDaemon() — réponse : ' . $logRaw);
    $decoded = json_decode($raw, true);
    if (isset($decoded['error'])) {
      throw new Exception($decoded['error']);
    }
    return $decoded['result'] ?? $decoded;
  }

  // -------------------------------------------------------------------------
  // Création automatique des commandes à la sauvegarde
  // -------------------------------------------------------------------------

  public function postSave() {
    // Création idempotente : chaque commande n'est créée que si elle est absente
    $info_cmds = [
      ['logicalId' => 'last_message',     'name' => 'Dernier message',         'subType' => 'string'],
      ['logicalId' => 'last_sender',      'name' => 'Expéditeur',              'subType' => 'string'],
      ['logicalId' => 'last_sender_name', 'name' => 'Nom expéditeur',          'subType' => 'string'],
      ['logicalId' => 'last_received_at', 'name' => 'Reçu le',                 'subType' => 'string'],
      ['logicalId' => 'sent_hour',        'name' => 'Envoyés (heure en cours)', 'subType' => 'numeric', 'isHistorized' => 1],
      ['logicalId' => 'messages_today',   'name' => 'Reçus aujourd\'hui',       'subType' => 'numeric', 'isHistorized' => 1],
      ['logicalId' => 'connected_since',  'name' => 'Connecté depuis',          'subType' => 'string'],
      ['logicalId' => 'last_reaction',      'name' => 'Dernière réaction',       'subType' => 'string'],
      ['logicalId' => 'last_reaction_from', 'name' => 'Réaction — expéditeur',   'subType' => 'string'],
      ['logicalId' => 'last_reaction_at',   'name' => 'Réaction — date',         'subType' => 'string'],
      ['logicalId' => 'last_attachment_path', 'name' => 'Dernier média — chemin', 'subType' => 'string'],
      ['logicalId' => 'last_attachment_type', 'name' => 'Dernier média — type',   'subType' => 'string'],
      ['logicalId' => 'last_attachment_mime', 'name' => 'Dernier média — mime',   'subType' => 'string'],
      ['logicalId' => 'last_attachment_size', 'name' => 'Dernier média — taille', 'subType' => 'numeric'],
      ['logicalId' => 'poll_question', 'name' => 'Sondage — question',          'subType' => 'string'],
      ['logicalId' => 'poll_results',  'name' => 'Sondage — résultats (JSON)',  'subType' => 'string'],
      ['logicalId' => 'poll_total',    'name' => 'Sondage — total votes',       'subType' => 'numeric', 'isHistorized' => 1],
      ['logicalId' => 'last_group',      'name' => 'Dernier groupe — tag',      'subType' => 'string'],
      ['logicalId' => 'last_group_name', 'name' => 'Dernier groupe — nom',      'subType' => 'string'],
      ['logicalId' => 'last_sender_profile', 'name' => 'Expéditeur — profil',   'subType' => 'string'],
      ['logicalId' => 'last_ocr_text',    'name' => 'OCR — texte image',        'subType' => 'string'],
      ['logicalId' => 'last_voice_text',  'name' => 'STT — note vocale',        'subType' => 'string'],
      ['logicalId' => 'last_read_at',     'name' => 'Lu le',                    'subType' => 'string'],
    ];

    $order = 0;
    foreach ($info_cmds as $def) {
      $cmd = $this->getCmd('info', $def['logicalId']);
      if (!is_object($cmd)) {
        $cmd = new jeewhatsappCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($def['logicalId']);
        $cmd->setType('info');
        $cmd->setSubType($def['subType']);
        $cmd->setName($def['name']);
        $cmd->setIsVisible(1);
        $cmd->setOrder($order);
        if (!empty($def['isHistorized'])) {
          $cmd->setIsHistorized(1);
        }
        $cmd->save();
      } elseif (!empty($def['isHistorized']) && !$cmd->getIsHistorized()) {
        $cmd->setIsHistorized(1);
        $cmd->save();
      }
      $order++;
    }

    // Commande action : Envoyer un message dans le groupe canal
    $send = $this->getCmd('action', 'send_message');
    if (!is_object($send)) {
      $send = new jeewhatsappCmd();
      $send->setEqLogic_id($this->getId());
      $send->setLogicalId('send_message');
      $send->setType('action');
      $send->setSubType('message');
      $send->setName('Envoyer un message');
      $send->setIsVisible(1);
      $send->setOrder($order++);
      $send->save();
    }
    // Le champ "Titre" sert d'override optionnel (numéro direct ou JID de groupe)
    // NM (Notification Manager) utilise ce champ "Titre" comme destinataire.
    // Laisser vide = groupe canal par defaut.
    if ($send->getDisplay('title_placeholder') !== 'Vide = groupe canal — numéro ou nom du carnet de contacts (NM : numero destinataire)') {
      $send->setDisplay('title_placeholder', 'Vide = groupe canal — numéro ou nom du carnet de contacts (NM : numero destinataire)');
      $send->save();
    }

    // Commande action : Répondre (quoted) au dernier message reçu dans le groupe canal
    $reply = $this->getCmd('action', 'reply');
    if (!is_object($reply)) {
      $reply = new jeewhatsappCmd();
      $reply->setEqLogic_id($this->getId());
      $reply->setLogicalId('reply');
      $reply->setType('action');
      $reply->setSubType('message');
      $reply->setName('Répondre');
      $reply->setIsVisible(1);
      $reply->setOrder($order++);
      $reply->save();
    }

    // Commande action : Envoyer un média (image/vidéo/audio/document)
    // Convention : title = chemin absolu du fichier, message = caption optionnelle
    $media = $this->getCmd('action', 'send_media');
    if (!is_object($media)) {
      $media = new jeewhatsappCmd();
      $media->setEqLogic_id($this->getId());
      $media->setLogicalId('send_media');
      $media->setType('action');
      $media->setSubType('message');
      $media->setName('Envoyer un média');
      $media->setIsVisible(1);
      $media->setOrder($order++);
      $media->save();
    }
    if ($media->getDisplay('title_placeholder') !== 'Chemin absolu du fichier (image/vidéo/audio/document)') {
      $media->setDisplay('title_placeholder', 'Chemin absolu du fichier (image/vidéo/audio/document)');
      $media->save();
    }
    if ($media->getDisplay('message_placeholder') !== 'Légende optionnelle (ignorée pour audio)') {
      $media->setDisplay('message_placeholder', 'Légende optionnelle (ignorée pour audio)');
      $media->save();
    }

    // Commande action : Envoyer une localisation GPS (v0.2)
    // Convention : title = "lat|long" ou "lat|long|nom", message = inutilisé
    $loc = $this->getCmd('action', 'send_location');
    if (!is_object($loc)) {
      $loc = new jeewhatsappCmd();
      $loc->setEqLogic_id($this->getId());
      $loc->setLogicalId('send_location');
      $loc->setType('action');
      $loc->setSubType('message');
      $loc->setName('Envoyer une localisation');
      $loc->setIsVisible(1);
      $loc->setOrder($order++);
      $loc->save();
    }
    if ($loc->getDisplay('title_placeholder') !== 'lat|long ou lat|long|nom (ex: 48.8566|2.3522|Tour Eiffel)') {
      $loc->setDisplay('title_placeholder', 'lat|long ou lat|long|nom (ex: 48.8566|2.3522|Tour Eiffel)');
      $loc->save();
    }
    if ($loc->getDisplay('message_placeholder') !== 'Inutilisé (ignoré)') {
      $loc->setDisplay('message_placeholder', 'Inutilisé (ignoré)');
      $loc->save();
    }

    // Commande action : Envoyer une carte de contact (vCard) (v0.2)
    // Convention : title = numéro du contact, message = nom affiché (optionnel)
    $contact = $this->getCmd('action', 'send_contact');
    if (!is_object($contact)) {
      $contact = new jeewhatsappCmd();
      $contact->setEqLogic_id($this->getId());
      $contact->setLogicalId('send_contact');
      $contact->setType('action');
      $contact->setSubType('message');
      $contact->setName('Envoyer un contact');
      $contact->setIsVisible(1);
      $contact->setOrder($order++);
      $contact->save();
    }
    if ($contact->getDisplay('title_placeholder') !== 'Numéro du contact (ex: 33612345678)') {
      $contact->setDisplay('title_placeholder', 'Numéro du contact (ex: 33612345678)');
      $contact->save();
    }
    if ($contact->getDisplay('message_placeholder') !== 'Nom affiché (optionnel)') {
      $contact->setDisplay('message_placeholder', 'Nom affiché (optionnel)');
      $contact->save();
    }

    // Commande action : Réagir avec un emoji au dernier message (v0.2)
    // Convention : message = emoji (❤️ 👍 🎉 ...), title inutilisé
    $react = $this->getCmd('action', 'react_last');
    if (!is_object($react)) {
      $react = new jeewhatsappCmd();
      $react->setEqLogic_id($this->getId());
      $react->setLogicalId('react_last');
      $react->setType('action');
      $react->setSubType('message');
      $react->setName('Réagir au dernier message');
      $react->setIsVisible(1);
      $react->setOrder($order++);
      $react->save();
    }
    if ($react->getDisplay('title_placeholder') !== 'Inutilisé') {
      $react->setDisplay('title_placeholder', 'Inutilisé');
      $react->save();
    }
    if ($react->getDisplay('message_placeholder') !== 'Emoji UTF-8 (ex: coeur, pouce) — vide pour retirer') {
      $react->setDisplay('message_placeholder', 'Emoji UTF-8 (ex: coeur, pouce) — vide pour retirer');
      $react->save();
    }

    // Commande action : Envoyer un sondage (v0.3 #9)
    // Convention : title = question, message = "opt1|opt2|opt3"
    $poll = $this->getCmd('action', 'send_poll');
    if (!is_object($poll)) {
      $poll = new jeewhatsappCmd();
      $poll->setEqLogic_id($this->getId());
      $poll->setLogicalId('send_poll');
      $poll->setType('action');
      $poll->setSubType('message');
      $poll->setName('Envoyer un sondage');
      $poll->setIsVisible(1);
      $poll->setOrder($order++);
      $poll->save();
    }
    if ($poll->getDisplay('title_placeholder') !== 'Question du sondage') {
      $poll->setDisplay('title_placeholder', 'Question du sondage');
      $poll->save();
    }
    if ($poll->getDisplay('message_placeholder') !== 'Options séparées par | (ex: Oui|Non|Peut-être)') {
      $poll->setDisplay('message_placeholder', 'Options séparées par | (ex: Oui|Non|Peut-être)');
      $poll->save();
    }

    // Commande action : Envoyer un sticker (v0.3 #10)
    // Convention : title = chemin absolu (.webp ou image convertie), message inutilisé
    $sticker = $this->getCmd('action', 'send_sticker');
    if (!is_object($sticker)) {
      $sticker = new jeewhatsappCmd();
      $sticker->setEqLogic_id($this->getId());
      $sticker->setLogicalId('send_sticker');
      $sticker->setType('action');
      $sticker->setSubType('message');
      $sticker->setName('Envoyer un sticker');
      $sticker->setIsVisible(1);
      $sticker->setOrder($order++);
      $sticker->save();
    }
    if ($sticker->getDisplay('title_placeholder') !== 'Chemin absolu (.webp, ou .png/.jpg converti en WebP)') {
      $sticker->setDisplay('title_placeholder', 'Chemin absolu (.webp, ou .png/.jpg converti en WebP)');
      $sticker->save();
    }
    if ($sticker->getDisplay('message_placeholder') !== 'Inutilisé (ignoré)') {
      $sticker->setDisplay('message_placeholder', 'Inutilisé (ignoré)');
      $sticker->save();
    }

    // Commande action : Envoyer une note vocale (TTS Piper) (v0.4 #18)
    // Convention : message = texte à synthétiser, title = destinataire optionnel
    $voice = $this->getCmd('action', 'send_voice');
    if (!is_object($voice)) {
      $voice = new jeewhatsappCmd();
      $voice->setEqLogic_id($this->getId());
      $voice->setLogicalId('send_voice');
      $voice->setType('action');
      $voice->setSubType('message');
      $voice->setName('Envoyer une note vocale');
      $voice->setIsVisible(1);
      $voice->setOrder($order++);
      $voice->save();
    }
    if ($voice->getDisplay('title_placeholder') !== 'Destinataire optionnel (vide = groupe canal)') {
      $voice->setDisplay('title_placeholder', 'Destinataire optionnel (vide = groupe canal)');
      $voice->save();
    }
    if ($voice->getDisplay('message_placeholder') !== 'Texte à dire à voix haute (synthèse vocale)') {
      $voice->setDisplay('message_placeholder', 'Texte à dire à voix haute (synthèse vocale)');
      $voice->save();
    }

    // Commande action : Marquer le dernier message reçu comme lu (v0.5 #23)
    // Aucun paramètre — coches bleues sur le dernier message reçu dans le groupe canal
    $markRead = $this->getCmd('action', 'mark_read');
    if (!is_object($markRead)) {
      $markRead = new jeewhatsappCmd();
      $markRead->setEqLogic_id($this->getId());
      $markRead->setLogicalId('mark_read');
      $markRead->setType('action');
      $markRead->setSubType('other');
      $markRead->setName('Marquer comme lu');
      $markRead->setIsVisible(1);
      $markRead->setOrder($order++);
      $markRead->save();
    }

    // Commandes action : Archive / Épingle / Sourdine la conversation (v0.5 #24)
    $chatActions = [
      ['logicalId' => 'archive_chat', 'name' => 'Archiver la conversation',  'title' => 'Vide = archiver, 0 = désarchiver'],
      ['logicalId' => 'pin_chat',     'name' => 'Épingler la conversation',  'title' => 'Vide = épingler, 0 = désépingler'],
      ['logicalId' => 'mute_chat',    'name' => 'Mettre en sourdine',        'title' => 'Durée en heures (vide = 8h, 0 = réactiver)'],
    ];
    foreach ($chatActions as $def) {
      $c = $this->getCmd('action', $def['logicalId']);
      if (!is_object($c)) {
        $c = new jeewhatsappCmd();
        $c->setEqLogic_id($this->getId());
        $c->setLogicalId($def['logicalId']);
        $c->setType('action');
        $c->setSubType('message');
        $c->setName($def['name']);
        $c->setIsVisible(1);
        $c->setOrder($order++);
        $c->save();
      }
      if ($c->getDisplay('title_placeholder') !== $def['title']) {
        $c->setDisplay('title_placeholder', $def['title']);
        $c->save();
      }
    }

    // Commande action : Publier un statut WhatsApp (story 24h) (v0.5 #25)
    // Convention : message = texte (ou légende), title = chemin image optionnel
    $status = $this->getCmd('action', 'post_status');
    if (!is_object($status)) {
      $status = new jeewhatsappCmd();
      $status->setEqLogic_id($this->getId());
      $status->setLogicalId('post_status');
      $status->setType('action');
      $status->setSubType('message');
      $status->setName('Publier un statut');
      $status->setIsVisible(1);
      $status->setOrder($order++);
      $status->save();
    }
    if ($status->getDisplay('title_placeholder') !== 'Chemin image optionnel (statut image)') {
      $status->setDisplay('title_placeholder', 'Chemin image optionnel (statut image)');
      $status->save();
    }
    if ($status->getDisplay('message_placeholder') !== 'Texte du statut (ou légende de l\'image)') {
      $status->setDisplay('message_placeholder', 'Texte du statut (ou légende de l\'image)');
      $status->save();
    }

    // Commande action : Éditer le dernier message envoyé (v0.3 #11)
    // Convention : message = nouveau texte, title inutilisé
    $edit = $this->getCmd('action', 'edit_last');
    if (!is_object($edit)) {
      $edit = new jeewhatsappCmd();
      $edit->setEqLogic_id($this->getId());
      $edit->setLogicalId('edit_last');
      $edit->setType('action');
      $edit->setSubType('message');
      $edit->setName('Éditer le dernier message');
      $edit->setIsVisible(1);
      $edit->setOrder($order++);
      $edit->save();
    }
    if ($edit->getDisplay('title_placeholder') !== 'Inutilisé') {
      $edit->setDisplay('title_placeholder', 'Inutilisé');
      $edit->save();
    }
    if ($edit->getDisplay('message_placeholder') !== 'Nouveau texte du dernier message envoyé') {
      $edit->setDisplay('message_placeholder', 'Nouveau texte du dernier message envoyé');
      $edit->save();
    }

    // Commande action : Supprimer (revoke) le dernier message envoyé (v0.3 #12)
    // Convention : aucun paramètre — subType 'other' (bouton sans champ)
    $revoke = $this->getCmd('action', 'revoke_last');
    if (!is_object($revoke)) {
      $revoke = new jeewhatsappCmd();
      $revoke->setEqLogic_id($this->getId());
      $revoke->setLogicalId('revoke_last');
      $revoke->setType('action');
      $revoke->setSubType('other');
      $revoke->setName('Supprimer le dernier message');
      $revoke->setIsVisible(1);
      $revoke->setOrder($order++);
      $revoke->save();
    }

    // Commande action : Transférer le dernier message reçu (v0.3 #13)
    // Convention : title = destinataire optionnel (vide = groupe canal)
    $forward = $this->getCmd('action', 'forward_to');
    if (!is_object($forward)) {
      $forward = new jeewhatsappCmd();
      $forward->setEqLogic_id($this->getId());
      $forward->setLogicalId('forward_to');
      $forward->setType('action');
      $forward->setSubType('message');
      $forward->setName('Transférer le dernier message reçu');
      $forward->setIsVisible(1);
      $forward->setOrder($order++);
      $forward->save();
    }
    if ($forward->getDisplay('title_placeholder') !== 'Destinataire (optionnel — vide = groupe canal)') {
      $forward->setDisplay('title_placeholder', 'Destinataire (optionnel — vide = groupe canal)');
      $forward->save();
    }
    if ($forward->getDisplay('message_placeholder') !== 'Inutilisé (ignoré)') {
      $forward->setDisplay('message_placeholder', 'Inutilisé (ignoré)');
      $forward->save();
    }

    // Commande action : Envoyer dans un groupe additionnel (v0.3 #16)
    // Convention : title = tag du groupe (cf config « Groupes additionnels »), message = texte
    $sgroup = $this->getCmd('action', 'send_group');
    if (!is_object($sgroup)) {
      $sgroup = new jeewhatsappCmd();
      $sgroup->setEqLogic_id($this->getId());
      $sgroup->setLogicalId('send_group');
      $sgroup->setType('action');
      $sgroup->setSubType('message');
      $sgroup->setName('Envoyer dans un groupe additionnel');
      $sgroup->setIsVisible(1);
      $sgroup->setOrder($order++);
      $sgroup->save();
    }
    if ($sgroup->getDisplay('title_placeholder') !== 'Tag du groupe (cf Groupes additionnels)') {
      $sgroup->setDisplay('title_placeholder', 'Tag du groupe (cf Groupes additionnels)');
      $sgroup->save();
    }
    if ($sgroup->getDisplay('message_placeholder') !== 'Texte du message') {
      $sgroup->setDisplay('message_placeholder', 'Texte du message');
      $sgroup->save();
    }

    // Commande action : Envoyer un template (#29)
    // Convention : message = clé du template (ex: bienvenue), title = destinataire optionnel (vide = groupe canal)
    $tmpl = $this->getCmd('action', 'send_template');
    if (!is_object($tmpl)) {
      $tmpl = new jeewhatsappCmd();
      $tmpl->setEqLogic_id($this->getId());
      $tmpl->setLogicalId('send_template');
      $tmpl->setType('action');
      $tmpl->setSubType('message');
      $tmpl->setName('Envoyer un template');
      $tmpl->setIsVisible(1);
      $tmpl->setOrder($order++);
      $tmpl->save();
    }
    if ($tmpl->getDisplay('title_placeholder') !== 'Destinataire optionnel (vide = groupe canal)') {
      $tmpl->setDisplay('title_placeholder', 'Destinataire optionnel (vide = groupe canal)');
      $tmpl->save();
    }
    if ($tmpl->getDisplay('message_placeholder') !== 'Clé du template (ex: bienvenue)') {
      $tmpl->setDisplay('message_placeholder', 'Clé du template (ex: bienvenue)');
      $tmpl->save();
    }

    // Commandes info cachées : statistiques 30 jours (#30)
    // Non visibles sur le dashboard, utilisables dans les scénarios.
    $statCmds = [
      ['logicalId' => 'stats_sent_30d',     'name' => 'Envoyés (30 jours)',   'unite' => 'msg'],
      ['logicalId' => 'stats_received_30d', 'name' => 'Reçus (30 jours)',    'unite' => 'msg'],
    ];
    foreach ($statCmds as $def) {
      $c = $this->getCmd('info', $def['logicalId']);
      if (!is_object($c)) {
        $c = new jeewhatsappCmd();
        $c->setEqLogic_id($this->getId());
        $c->setLogicalId($def['logicalId']);
        $c->setType('info');
        $c->setSubType('numeric');
        $c->setName($def['name']);
        $c->setUnite($def['unite']);
        $c->setIsVisible(0);   // caché sur le dashboard
        $c->setIsHistorized(1);
        $c->setOrder($order++);
        $c->save();
      }
    }

    // Commande info cachée : nombre de messages en file d'attente (outbox, P2).
    // Mise à jour par getConnectionStatus() depuis le statut du daemon. Non
    // historisée (jauge instantanée), invisible sur le dashboard.
    $outboxCmd = $this->getCmd('info', 'outbox_pending');
    if (!is_object($outboxCmd)) {
      $outboxCmd = new jeewhatsappCmd();
      $outboxCmd->setEqLogic_id($this->getId());
      $outboxCmd->setLogicalId('outbox_pending');
      $outboxCmd->setType('info');
      $outboxCmd->setSubType('numeric');
      $outboxCmd->setName('Messages en file');
      $outboxCmd->setUnite('msg');
      $outboxCmd->setIsVisible(0);
      $outboxCmd->setIsHistorized(0);
      $outboxCmd->setOrder($order++);
      $outboxCmd->save();
    }

    // Si le daemon tourne déjà, on le redémarre pour qu'il prenne en compte
    // le nouvel équipement (ou les changements de config comme group_name, extra_groups…).
    // Fait après la sauvegarde des commandes pour avoir un état cohérent.
    if ($this->getIsEnable() && self::deamon_info()['state'] === 'ok') {
      try {
        self::deamon_start();
      } catch (Exception $e) {
        log::add('jeewhatsapp', 'warning',
          'jeewhatsapp.class.php::postSave() l.' . __LINE__
          . ' — Redémarrage daemon impossible : ' . $e->getMessage());
      }
    }
  }

  // -------------------------------------------------------------------------
  // Statistiques (#30)
  // Stockage : plugins/jeewhatsapp/data/{eqId}/stats.json
  // Structure : {"days":[{"d":"YYYY-MM-DD","s":N,"r":N},...], "contacts":{"33...":{"n":N,"l":"Nom"}}}
  // Conserve les 30 derniers jours et le top 20 contacts.
  // -------------------------------------------------------------------------

  private function statsFile() {
    $dir = dirname(__FILE__) . '/../../data/' . $this->getId();
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    return $dir . '/stats.json';
  }

  private function loadStats() {
    $file = $this->statsFile();
    if (!is_file($file)) { return ['days' => [], 'contacts' => []]; }
    $raw = @file_get_contents($file);
    if (!$raw) { return ['days' => [], 'contacts' => []]; }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ['days' => [], 'contacts' => []];
  }

  private function saveStats($data) {
    @file_put_contents($this->statsFile(), json_encode($data, JSON_UNESCAPED_UNICODE));
  }

  /**
   * Incrémente le compteur journalier.
   * $_dir : 's' (sent) ou 'r' (received)
   * $_sender : numéro expéditeur (optionnel, pour les stats contacts)
   */
  public function appendStats($_dir, $_sender = '', $_senderName = '') {
    $data  = $this->loadStats();
    $today = date('Y-m-d');

    // Mise à jour du jour courant
    $days  = $data['days'] ?? [];
    $found = false;
    foreach ($days as &$day) {
      if ($day['d'] === $today) {
        $day[$_dir] = ($day[$_dir] ?? 0) + 1;
        $found = true;
        break;
      }
    }
    unset($day);
    if (!$found) {
      $days[] = ['d' => $today, 's' => 0, 'r' => 0, [$_dir] => 1];
      // Correction : initialiser correctement
      $last = &$days[count($days) - 1];
      $last['s'] = $_dir === 's' ? 1 : 0;
      $last['r'] = $_dir === 'r' ? 1 : 0;
      unset($last);
    }

    // Garder les 30 derniers jours (tri par date croissante)
    usort($days, function ($a, $b) { return strcmp($a['d'], $b['d']); });
    if (count($days) > 30) { $days = array_slice($days, -30); }
    $data['days'] = $days;

    // Stats contacts (uniquement pour les messages reçus)
    if ($_dir === 'r' && $_sender !== '') {
      $sender = preg_replace('/\D/', '', $_sender);
      if ($sender !== '') {
        $contacts = $data['contacts'] ?? [];
        if (!isset($contacts[$sender])) {
          $contacts[$sender] = ['n' => 0, 'l' => ''];
        }
        $contacts[$sender]['n'] = ($contacts[$sender]['n'] ?? 0) + 1;
        if ($_senderName !== '') { $contacts[$sender]['l'] = $_senderName; }
        // Top 20 contacts uniquement
        arsort($contacts);
        if (count($contacts) > 20) {
          $contacts = array_slice($contacts, 0, 20, true);
        }
        $data['contacts'] = $contacts;
      }
    }

    $this->saveStats($data);

    // Mettre à jour les commandes info cachées (scénarios)
    $total30s = 0;
    $total30r = 0;
    foreach ($data['days'] as $day) {
      $total30s += ($day['s'] ?? 0);
      $total30r += ($day['r'] ?? 0);
    }
    $this->checkAndUpdateCmd('stats_sent_30d',     $total30s);
    $this->checkAndUpdateCmd('stats_received_30d', $total30r);
  }

  /**
   * Retourne les statistiques pour l'UI : 30 derniers jours + top contacts + totaux.
   */
  public function getStats() {
    $data  = $this->loadStats();
    $days  = $data['days'] ?? [];

    // Remplir les jours manquants sur les 30 derniers jours avec des zéros
    $filled = [];
    for ($i = 29; $i >= 0; $i--) {
      $d = date('Y-m-d', strtotime("-{$i} days"));
      $entry = ['d' => $d, 's' => 0, 'r' => 0];
      foreach ($days as $day) {
        if ($day['d'] === $d) { $entry = $day; break; }
      }
      $filled[] = $entry;
    }

    $totalSent     = array_sum(array_column($filled, 's'));
    $totalReceived = array_sum(array_column($filled, 'r'));

    // Top 5 contacts pour l'affichage
    $contacts = $data['contacts'] ?? [];
    arsort($contacts);
    $topContacts = array_slice($contacts, 0, 5, true);

    return [
      'days'          => $filled,
      'top_contacts'  => $topContacts,
      'total_sent'    => $totalSent,
      'total_received'=> $totalReceived,
      'period'        => '30 jours',
    ];
  }

  // -------------------------------------------------------------------------
  // Templates messages (#29)
  // Pool de messages réutilisables, configurés par équipement.
  //
  // Format de stockage (configuration eqLogic 'message_templates') :
  //   une ligne par template : cle=Texte du message avec des #tags# ou du texte libre
  //   Les lignes vides ou sans '=' sont ignorées.
  //   La clé est insensible à la casse lors de la recherche.
  // -------------------------------------------------------------------------

  /**
   * Retourne tous les templates de cet équipement sous forme de tableau associatif
   * key (minuscule) => texte.
   */
  public function getTemplates() {
    $raw       = $this->getConfiguration('message_templates', '');
    $templates = [];
    if (trim($raw) === '') { return $templates; }
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
      $line = trim($line);
      if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) { continue; }
      [$key, $text] = explode('=', $line, 2);
      $key  = strtolower(trim($key));
      $text = trim($text);
      if ($key !== '' && $text !== '') {
        $templates[$key] = $text;
      }
    }
    return $templates;
  }

  /**
   * Retourne le texte d'un template par sa clé (insensible à la casse), ou null si introuvable.
   */
  public function getTemplate($_key) {
    return $this->getTemplates()[strtolower(trim($_key))] ?? null;
  }

  // -------------------------------------------------------------------------
  // Widget dashboard — tuile style WhatsApp (v0.6 #28)
  // Affiche : statut de connexion, dernier message reçu (bulle de chat),
  // compteurs, zone d'envoi rapide, bouton sourdine.
  // -------------------------------------------------------------------------

  public function toHtml($_version = 'dashboard') {
    $replace = $this->preToHtml($_version);
    if (!is_array($replace)) {
      return $replace;
    }
    $version = jeedom::versionAlias($_version);

    $replace['#eqLogic_id#']  = $this->getId();
    $replace['#device_name#'] = htmlspecialchars($this->getName());

    // Statut de connexion : lecture du fichier status.txt du daemon (local, pas
    // d'appel réseau). Valeurs : connecting/qr_pending/connected/reconnecting/…
    $statusFile = __DIR__ . '/../../resources/jeewhatsappd/auth/' . $this->getId() . '/status.txt';
    $status = (is_file($statusFile)) ? trim((string) @file_get_contents($statusFile)) : 'unknown';
    $statusMap = [
      'connected'    => ['connecté',        'ok'],
      'connecting'   => ['connexion…',      'warn'],
      'reconnecting' => ['reconnexion…',    'warn'],
      'qr_pending'   => ['scan QR requis',  'warn'],
      'logged_out'   => ['déconnecté',      'ko'],
    ];
    $st = $statusMap[$status] ?? ['hors ligne', 'ko'];
    $replace['#status_label#'] = $st[0];
    $replace['#status_class#'] = $st[1];

    // Helper local pour lire une cmd info
    $info = function ($logicalId) {
      $c = $this->getCmd('info', $logicalId);
      return is_object($c) ? (string) $c->execCmd() : '';
    };

    $lastMessage = $info('last_message');
    $lastSender  = $info('last_sender_profile');
    if ($lastSender === '') { $lastSender = $info('last_sender_name'); }
    if ($lastSender === '') { $lastSender = $info('last_sender'); }
    $lastAt      = $info('last_received_at');

    // Heure courte (HH:MM) pour la bulle
    $lastTime = '';
    if ($lastAt !== '' && ($tsv = strtotime($lastAt)) !== false) {
      $lastTime = date('H:i', $tsv);
    }

    $replace['#has_message#']  = ($lastMessage !== '') ? '1' : '0';
    $replace['#msg_js#']       = json_encode($lastMessage);
    $replace['#sender_js#']    = json_encode($lastSender !== '' ? $lastSender : 'Inconnu');
    $replace['#time_js#']      = json_encode($lastTime);

    // Historique de conversation (50 messages max, fichier local)
    $historyFile = $this->historyFile();
    $history     = [];
    if (is_file($historyFile)) {
      $raw = @file_get_contents($historyFile);
      if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) { $history = $decoded; }
      }
    }
    $replace['#history_js#'] = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Pièce jointe courante (pour le rendu média initial) + transcription STT
    $replace['#att_path_js#']  = json_encode($info('last_attachment_path'));
    $replace['#att_type_js#']  = json_encode($info('last_attachment_type'));
    $replace['#voice_js#']     = json_encode($info('last_voice_text'));

    $replace['#messages_today#'] = htmlspecialchars($info('messages_today') ?: '0');
    $replace['#sent_hour#']      = htmlspecialchars($info('sent_hour') ?: '0');

    // IDs des commandes action utilisées par le widget
    $actionIds = [
      'send_message' => 'cmd_send_id',
      'send_voice'   => 'cmd_voice_id',
      'mute_chat'    => 'cmd_mute_id',
      'mark_read'    => 'cmd_markread_id',
    ];
    foreach ($actionIds as $logicalId => $key) {
      $c = $this->getCmd('action', $logicalId);
      $replace['#' . $key . '#'] = is_object($c) ? $c->getId() : 0;
    }

    // IDs des commandes info (pour la mise à jour live via jeedom.cmd.addUpdateFunction)
    $infoIds = [
      'last_message'         => 'info_msg_id',
      'last_sender_profile'  => 'info_profile_id',
      'last_sender_name'     => 'info_sender_id',
      'last_received_at'     => 'info_time_id',
      'last_voice_text'      => 'info_voice_id',
      'last_attachment_path' => 'info_att_path_id',
      'last_attachment_type' => 'info_att_type_id',
    ];
    foreach ($infoIds as $logicalId => $key) {
      $c = $this->getCmd('info', $logicalId);
      $replace['#' . $key . '#'] = is_object($c) ? $c->getId() : 0;
    }

    // Icône du plugin comme avatar
    $replace['#avatar_url#'] = 'plugins/jeewhatsapp/plugin_info/jeewhatsapp_icon.png';

    return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'jeewhatsapp', 'jeewhatsapp')));
  }
}

// =============================================================================

class jeewhatsappCmd extends cmd {

  public function execute($_options = []) {
    $eqLogic = $this->getEqLogic();

    switch ($this->getLogicalId()) {

      case 'send_message':
        $message = $_options['message'] ?? '';
        // Le champ "Titre" = override optionnel (numéro direct ou JID)
        // Vide = envoyer dans le groupe canal
        $phone = (isset($_options['title']) && trim($_options['title']) !== '')
          ? trim($_options['title'])
          : null;
        $eqLogic->sendMessage($message, $phone);
        break;

      case 'send_group':
        // title = tag du groupe additionnel ; message = texte
        $tag = (isset($_options['title'])) ? trim($_options['title']) : '';
        $message = $_options['message'] ?? '';
        if ($tag === '') {
          throw new Exception(__('Tag du groupe (champ Titre) obligatoire', __FILE__));
        }
        $eqLogic->sendGroup($tag, $message);
        break;

      case 'reply':
        $message = $_options['message'] ?? '';
        // Réponse "quoted" au dernier message reçu dans le groupe canal
        $eqLogic->replyToLast($message);
        break;

      case 'send_media':
        // title = chemin absolu du fichier ; message = légende optionnelle
        $path    = (isset($_options['title'])) ? trim($_options['title']) : '';
        $caption = $_options['message'] ?? '';
        if ($path === '') {
          throw new Exception(__('Chemin du fichier (champ Titre) obligatoire', __FILE__));
        }
        $eqLogic->sendMediaFile($path, $caption);
        break;

      case 'send_location':
        // title = "lat|long" ou "lat|long|nom"
        $raw = (isset($_options['title'])) ? trim($_options['title']) : '';
        $parts = array_map('trim', explode('|', $raw, 3));
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
          throw new Exception(__('Format attendu : lat|long ou lat|long|nom', __FILE__));
        }
        $name = isset($parts[2]) ? $parts[2] : '';
        $eqLogic->sendLocation((float)$parts[0], (float)$parts[1], $name);
        break;

      case 'send_contact':
        // title = numéro ; message = nom affiché optionnel
        $cphone = (isset($_options['title'])) ? trim($_options['title']) : '';
        $cname  = $_options['message'] ?? '';
        if ($cphone === '') {
          throw new Exception(__('Numéro du contact (champ Titre) obligatoire', __FILE__));
        }
        $eqLogic->sendContactCard($cphone, $cname);
        break;

      case 'react_last':
        // message = emoji ; title inutilisé
        $emoji = $_options['message'] ?? '';
        $eqLogic->reactToLast($emoji);
        break;

      case 'send_poll':
        // title = question ; message = "opt1|opt2|opt3"
        $pQuestion = (isset($_options['title'])) ? trim($_options['title']) : '';
        $pOptions  = $_options['message'] ?? '';
        if ($pQuestion === '') {
          throw new Exception(__('Question du sondage (champ Titre) obligatoire', __FILE__));
        }
        $eqLogic->sendPoll($pQuestion, $pOptions, 1);
        break;

      case 'send_sticker':
        // title = chemin absolu du fichier (.webp ou image à convertir)
        $spath = (isset($_options['title'])) ? trim($_options['title']) : '';
        if ($spath === '') {
          throw new Exception(__('Chemin du fichier (champ Titre) obligatoire', __FILE__));
        }
        $eqLogic->sendSticker($spath);
        break;

      case 'send_voice':
        // message = texte à synthétiser ; title = destinataire optionnel (vide = groupe canal)
        $vtext = $_options['message'] ?? '';
        if (trim((string) $vtext) === '') {
          throw new Exception(__('Texte à synthétiser (champ Message) obligatoire', __FILE__));
        }
        $vphone = (isset($_options['title']) && trim($_options['title']) !== '')
          ? trim($_options['title'])
          : null;
        $eqLogic->speak($vtext, $vphone);
        break;

      case 'edit_last':
        // message = nouveau texte ; title inutilisé
        $eqLogic->editLast($_options['message'] ?? '');
        break;

      case 'revoke_last':
        // aucun paramètre — supprime le dernier message envoyé
        $eqLogic->revokeLast();
        break;

      case 'mark_read':
        // aucun paramètre — marque le dernier message reçu comme lu (coches bleues)
        $eqLogic->markRead();
        break;

      case 'archive_chat':
        // title : '0' = désarchiver, sinon archiver
        $off = (isset($_options['title']) && trim($_options['title']) === '0');
        $eqLogic->chatModify($off ? 'unarchive' : 'archive');
        break;

      case 'pin_chat':
        // title : '0' = désépingler, sinon épingler
        $off = (isset($_options['title']) && trim($_options['title']) === '0');
        $eqLogic->chatModify($off ? 'unpin' : 'pin');
        break;

      case 'mute_chat':
        // title : durée en heures ('0' = réactiver, vide = 8h)
        $h = isset($_options['title']) ? trim($_options['title']) : '';
        if ($h === '0') {
          $eqLogic->chatModify('unmute');
        } else {
          $eqLogic->chatModify('mute', $h !== '' ? $h : null);
        }
        break;

      case 'post_status':
        // message = texte/légende ; title = chemin image optionnel
        $img = (isset($_options['title']) && trim($_options['title']) !== '')
          ? trim($_options['title'])
          : null;
        $eqLogic->postStatus($_options['message'] ?? '', $img);
        break;

      case 'forward_to':
        // title = destinataire optionnel (vide = groupe canal)
        $dest = (isset($_options['title']) && trim($_options['title']) !== '')
          ? trim($_options['title'])
          : null;
        $eqLogic->forwardLastTo($dest);
        break;

      case 'send_template':
        // message = clé du template ; title = destinataire optionnel (vide = groupe canal)
        $key   = trim($_options['message'] ?? '');
        $phone = (isset($_options['title']) && trim($_options['title']) !== '')
               ? trim($_options['title'])
               : null;
        if ($key === '') {
          throw new Exception(__('Clé de template manquante (champ Message)', __FILE__));
        }
        $text = $eqLogic->getTemplate($key);
        if ($text === null) {
          throw new Exception(__('Template introuvable : ', __FILE__) . $key);
        }
        // Résolution des tags Jeedom dans le texte du template.
        // Contrairement à send_message (dont le moteur de scénario résout déjà
        // $_options['message'] avant execute()), le texte du template est chargé
        // depuis la configuration APRÈS cette résolution — il faut donc le faire ici :
        //   1. fromHumanReadable : #[Objet][Équipement][Commande]# → #1234#
        //   2. cmdToValue        : #1234# → valeur courante de la commande
        try {
          $text = cmd::cmdToValue(jeedom::fromHumanReadable($text));
        } catch (Exception $e) {
          log::add('jeewhatsapp', 'warning',
            'jeewhatsapp.class.php::execute(send_template) l.' . __LINE__
            . ' — résolution des tags impossible : ' . $e->getMessage());
        }
        $eqLogic->sendMessage($text, $phone);
        break;
    }
  }
}
