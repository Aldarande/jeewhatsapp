<?php
/* This file is part of Jeedom.
 * Plugin JeeWhatsApp - Aldarande
 * Licence AGPL v3 — https://www.gnu.org/licenses/agpl-3.0.html
 */
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function jeewhatsapp_install() {
  // Désactive l'installation automatique des dépendances au premier lancement
  // (l'utilisateur doit cliquer explicitement sur "Installer les dépendances")
  config::save('dependancyAutoMode', 0, 'jeewhatsapp');

  // Cron horaire : envoi mensuel d'un message de soutien aléatoire.
  // Le cron tourne pour tous les eqLogic mais ne fait rien tant que l'utilisateur
  // n'a pas coché "Envoyer un message de soutien mensuel" sur l'équipement.
  // Minute 11 pour éviter la minute 0 (généralement chargée).
  $cron = cron::byClassAndFunction('jeewhatsapp', 'cronDonation');
  if (!is_object($cron)) {
    $cron = new cron();
    $cron->setClass('jeewhatsapp');
    $cron->setFunction('cronDonation');
    $cron->setEnable(1);
    $cron->setDeamon(0);
    $cron->setSchedule('11 * * * *');
    $cron->setTimeout(2);
    $cron->save();
  }
}

function jeewhatsapp_update() {
  jeewhatsapp_install();
}

function jeewhatsapp_remove() {
  // Arrêt propre du daemon avant suppression du plugin
  try {
    jeewhatsapp::deamon_stop();
  } catch (Exception $e) {
    log::add('jeewhatsapp', 'warning', 'install.php::jeewhatsapp_remove() — Arrêt daemon : ' . $e->getMessage());
  }
  // Suppression du cron donation
  $cron = cron::byClassAndFunction('jeewhatsapp', 'cronDonation');
  if (is_object($cron)) {
    $cron->remove();
  }
  // Suppression du dossier d'authentification (sessions WhatsApp)
  $authDir = dirname(__FILE__) . '/../resources/jeewhatsappd/auth';
  if (is_dir($authDir)) {
    shell_exec('rm -rf ' . escapeshellarg($authDir));
  }
}
