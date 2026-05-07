<?php
function jeewhatsapp_install() {
  config::save('dependancyAutoMode', 0, 'jeewhatsapp');
}

function jeewhatsapp_update() {
  jeewhatsapp_install();
}

function jeewhatsapp_remove() {
  jeewhatsapp::deamon_stop();
}
