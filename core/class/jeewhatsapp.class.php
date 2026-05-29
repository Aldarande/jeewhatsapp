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
        'prefix'     => $eqLogic->getConfiguration('interaction_prefix', '🏠 '),
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
    $cmd  = 'JEEDOM_APIKEY=' . escapeshellarg($api_key) . ' ';
    $cmd .= 'JEEDOM_DAEMON_SECRET=' . escapeshellarg($daemon_secret) . ' ';
    $cmd .= 'node ' . escapeshellarg(dirname(__FILE__) . '/../../resources/jeewhatsappd/jeewhatsappd.js');
    $cmd .= ' --instances '  . escapeshellarg(json_encode($instances));
    $cmd .= ' --port '       . self::getPort();
    $cmd .= ' --callback '   . escapeshellarg($callback);
    $cmd .= ' --pid-file '   . escapeshellarg($pid_file);
    $cmd .= ' --log-file '   . escapeshellarg($log_file);
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
      if ($pid > 0) { system::kill($pid); }
      system::fuserk(self::getPort());
      unlink($pid_file);
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
    $prefix  = $this->getConfiguration('interaction_prefix', '🏠 ');
    $message = (!$_skipPrefix && $prefix !== '') ? $prefix . $_message : $_message;

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
    $this->incrementSentCounters();
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
      $prefix  = $this->getConfiguration('interaction_prefix', '🏠 ');
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
    $out = $tmpDir . '/tts_' . $this->getId() . '_' . uniqid() . '.ogg';

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
    $prefix  = $this->getConfiguration('interaction_prefix', '🏠 ');
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
      throw new Exception(__('Emoji obligatoire (ex: ❤️ 👍 🎉)', __FILE__));
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
  // Édition du dernier message envoyé par Jeedom (v0.3 #11)
  // -------------------------------------------------------------------------

  public function editLast($_newText) {
    $text = trim((string) $_newText);
    if ($text === '') {
      throw new Exception(__('Nouveau texte obligatoire', __FILE__));
    }
    // Réapplique le préfixe Jeedom comme pour un envoi normal
    $prefix = $this->getConfiguration('interaction_prefix', '🏠 ');
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
    return $st;
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
    if ($send->getDisplay('title_placeholder') !== 'Destinataire (optionnel — vide = groupe canal)') {
      $send->setDisplay('title_placeholder', 'Destinataire (optionnel — vide = groupe canal)');
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
    if ($react->getDisplay('message_placeholder') !== 'Emoji (ex: ❤️ 👍 🎉 — chaîne vide pour retirer la réaction)') {
      $react->setDisplay('message_placeholder', 'Emoji (ex: ❤️ 👍 🎉 — chaîne vide pour retirer la réaction)');
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

      case 'forward_to':
        // title = destinataire optionnel (vide = groupe canal)
        $dest = (isset($_options['title']) && trim($_options['title']) !== '')
          ? trim($_options['title'])
          : null;
        $eqLogic->forwardLastTo($dest);
        break;
    }
  }
}
