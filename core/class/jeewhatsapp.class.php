<?php

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
      if ($pid > 0 && posix_kill($pid, 0)) {
        $return['state'] = 'ok';
      }
    }
    if (self::dependancy_info()['state'] !== 'ok') {
      $return['launchable']         = 'nok';
      $return['launchable_message'] = '{{Dépendances non installées}}';
    }
    return $return;
  }

  public static function deamon_start($_automatic = false) {
    self::deamon_stop();
    $daemon_info = self::deamon_info();
    if ($daemon_info['launchable'] !== 'ok') {
      throw new Exception('{{Impossible de lancer le daemon}} : ' . $daemon_info['launchable_message']);
    }

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
    $callback    = 'http://127.0.0.1:' . $jeedom_port . $jeedom_comp
                 . '/plugins/jeewhatsapp/core/php/callback.php?apikey=' . urlencode($api_key);

    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/daemon.pid';
    $log_file = log::getPathToLog(__CLASS__);

    $cmd  = 'node ' . escapeshellarg(dirname(__FILE__) . '/../../resources/jeewhatsappd/jeewhatsappd.js');
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
      throw new Exception('{{Impossible de démarrer le daemon après ' . $timeout . ' secondes}}');
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
    $eqLogic = eqLogic::byId($_data['instance_id'] ?? 0);
    if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== __CLASS__) { return; }
    $eqLogic->updateFromMessage($_data);
  }

  // -------------------------------------------------------------------------
  // Mise à jour des commandes depuis un message entrant
  // -------------------------------------------------------------------------

  public function updateFromMessage($_data) {
    $this->checkAndUpdateCmd('last_message',     $_data['message']      ?? '');
    $this->checkAndUpdateCmd('last_sender',      $_data['sender']       ?? '');
    $this->checkAndUpdateCmd('last_sender_name', $_data['sender_name']  ?? '');
    $this->checkAndUpdateCmd('last_received_at', $_data['received_at']  ?? date('Y-m-d H:i:s'));

    if ($this->getConfiguration('interactions_enabled', 0) != 1) {
      log::add('jeewhatsapp', 'debug', 'Interactions désactivées — équipement #' . $this->getId());
      return;
    }
    $message = $_data['message'] ?? '';
    $sender  = $_data['sender']  ?? '';
    if ($message === '' || $sender === '') { return; }

    log::add('jeewhatsapp', 'debug', 'Interaction — de ' . $sender . ' : ' . $message);

    $reply = interactQuery::tryToReply($message, [
      'plugin'     => 'jeewhatsapp',
      'profile'    => $_data['sender_name'] ?? $sender,
      'emptyReply' => 0,
    ]);

    if (!is_array($reply) || !isset($reply['reply']) || trim($reply['reply']) === '') {
      log::add('jeewhatsapp', 'debug', 'Interaction — aucune réponse pour : ' . $message);
      return;
    }

    log::add('jeewhatsapp', 'info', 'Interaction — réponse dans le groupe : ' . $reply['reply']);

    try {
      // En mode groupe, la réponse va dans le groupe canal (pas au sender direct)
      $this->sendMessage($reply['reply']);
      log::add('jeewhatsapp', 'info', 'Interaction — message transmis au daemon');
    } catch (Exception $e) {
      log::add('jeewhatsapp', 'error', 'jeewhatsapp.class.php::updateFromMessage() l.' . __LINE__ . ' — Erreur envoi réponse interaction : ' . $e->getMessage());
    }
  }

  // -------------------------------------------------------------------------
  // Envoi d'un message via le daemon
  // $_phone = null  → groupe canal configuré
  // $_phone = '...' → destinataire direct (override)
  // -------------------------------------------------------------------------

  public function sendMessage($_message, $_phone = null, $_mention = null) {
    $prefix  = $this->getConfiguration('interaction_prefix', '🏠 ');
    $message = $prefix !== '' ? $prefix . $_message : $_message;

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
  // Compteurs d'envoi — réinitialisés au début de chaque heure
  // -------------------------------------------------------------------------

  public function incrementSentCounters() {
    $now          = time();
    $hour_start   = mktime(date('G', $now), 0, 0, date('n', $now), date('j', $now), date('Y', $now));

    $counters = $this->getConfiguration('sent_counters', []);
    if (!is_array($counters)) { $counters = []; }

    $stored = $counters['hour'] ?? ['count' => 0, 'period_start' => 0];
    if ($stored['period_start'] < $hour_start) {
      $counters['hour'] = ['count' => 1, 'period_start' => $hour_start];
    } else {
      $counters['hour'] = ['count' => $stored['count'] + 1, 'period_start' => $stored['period_start']];
    }

    $this->setConfiguration('sent_counters', $counters);
    $this->save();
    $this->checkAndUpdateCmd('sent_hour', $counters['hour']['count']);
  }

  // -------------------------------------------------------------------------
  // QR code — récupéré depuis le daemon
  // -------------------------------------------------------------------------

  public function getQRData() {
    return $this->sendToDaemon('getQR', ['instance_id' => $this->getId()]);
  }

  public function getConnectionStatus() {
    return $this->sendToDaemon('getStatus', ['instance_id' => $this->getId()]);
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

  private function sendToDaemon($_action, $_params = []) {
    $url     = 'http://127.0.0.1:' . self::getPort() . '/action';
    $payload = json_encode(array_merge(['action' => $_action], $_params));
    log::add('jeewhatsapp', 'debug', 'sendToDaemon — ' . $_action . ' → ' . $payload);
    $opts = ['http' => [
      'method'  => 'POST',
      'header'  => 'Content-Type: application/json',
      'content' => $payload,
      'timeout' => 15,
      'ignore_errors' => true,
    ]];
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    if ($raw === false) {
      throw new Exception('{{Daemon non joignable sur le port ' . self::getPort() . ' — vérifiez qu\'il est démarré}}');
    }
    log::add('jeewhatsapp', 'debug', 'sendToDaemon — réponse daemon : ' . $raw);
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
    $info_cmds = [
      ['logicalId' => 'last_message',     'name' => 'Dernier message',         'subType' => 'string'],
      ['logicalId' => 'last_sender',      'name' => 'Expéditeur',              'subType' => 'string'],
      ['logicalId' => 'last_sender_name', 'name' => 'Nom expéditeur',          'subType' => 'string'],
      ['logicalId' => 'last_received_at', 'name' => 'Reçu le',                 'subType' => 'string'],
      ['logicalId' => 'sent_hour',        'name' => 'Envoyés (heure en cours)', 'subType' => 'numeric', 'isHistorized' => 1],
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
    }
    // Le champ "Titre" sert d'override optionnel (numéro direct ou JID de groupe)
    if ($send->getDisplay('title_placeholder') !== 'Destinataire (optionnel — vide = groupe canal)') {
      $send->setDisplay('title_placeholder', 'Destinataire (optionnel — vide = groupe canal)');
      $send->save();
    }

    // Commande action : Répondre dans le groupe canal
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
        // Répond dans le groupe canal
        $eqLogic->sendMessage($message);
        break;
    }
  }
}
