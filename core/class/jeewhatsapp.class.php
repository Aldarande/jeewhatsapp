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

    // checkAndUpdateCmd met à jour la valeur et déclenche les scénarios/interactions associés
    $this->checkAndUpdateCmd('last_message',     $_data['message']      ?? '');
    $this->checkAndUpdateCmd('last_sender',      $_data['sender']       ?? '');
    $this->checkAndUpdateCmd('last_sender_name', $_data['sender_name']  ?? '');
    $this->checkAndUpdateCmd('last_received_at', $_data['received_at']  ?? date('Y-m-d H:i:s'));

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

    $reply = interactQuery::tryToReply($message, [
      'plugin'     => 'jeewhatsapp',
      'profile'    => $_data['sender_name'] ?? $sender,
      'emptyReply' => 0,
    ]);

    if (!is_array($reply) || !isset($reply['reply']) || trim($reply['reply']) === '') {
      log::add('jeewhatsapp', 'debug', 'jeewhatsapp.class.php::updateFromMessage() — aucune réponse pour : ' . $message);
      return;
    }

    log::add('jeewhatsapp', 'info', 'jeewhatsapp.class.php::updateFromMessage() — réponse dans le groupe : ' . $reply['reply']);

    try {
      // En mode groupe, la réponse va dans le groupe canal (pas au sender direct)
      $this->sendMessage($reply['reply']);
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

  public function sendMessage($_message, $_phone = null, $_mention = null, $_skipPrefix = false) {
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

    $result = $this->sendToDaemon('sendMedia', $params);
    $this->incrementSentCounters();
    return $result;
  }

  // -------------------------------------------------------------------------
  // Réponse "quoted" au dernier message reçu dans le groupe canal
  // -------------------------------------------------------------------------

  public function replyToLast($_message) {
    $prefix  = $this->getConfiguration('interaction_prefix', '🏠 ');
    $message = $prefix !== '' ? $prefix . $_message : $_message;

    $result = $this->sendToDaemon('replyLast', [
      'instance_id' => $this->getId(),
      'message'     => $message,
    ]);
    $this->incrementSentCounters();
    return $result;
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
    }
  }
}
