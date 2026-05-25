<?php
/* This file is part of Jeedom.
 * Plugin JeeWhatsApp - Aldarande
 * Licence AGPL v3 — https://www.gnu.org/licenses/agpl-3.0.html
 */
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) { throw new Exception('{{401 - Accès non autorisé}}'); }
?>

<form class="form-horizontal">
  <fieldset>
    <div class="form-group">
      <label class="col-lg-4 control-label">{{Port du daemon}}</label>
      <div class="col-lg-4">
        <input class="configKey form-control" data-l1key="socketport"
               type="number" min="1024" max="65535"
               placeholder="<?php echo jeewhatsapp::DAEMON_PORT_DEFAULT; ?>" />
      </div>
    </div>
  </fieldset>
</form>
