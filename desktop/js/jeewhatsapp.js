/* jeewhatsapp — desktop JS */

$(document).ready(function () {

  // ── Surcharge addEqLogic : demande le nom avant de créer l'équipement ────────
  // Jeedom appelle jeeFrontEnd.pluginTemplate.addEqLogic() quand l'utilisateur
  // clique sur "Ajouter". On intercepte pour afficher un modal de saisie du nom.
  if (typeof jeeFrontEnd !== 'undefined' && jeeFrontEnd.pluginTemplate) {
    jeeFrontEnd.pluginTemplate.addEqLogic = function () {
      $('#in_newJeeWhatsAppName').val('');
      $('#modal_newJeeWhatsApp').modal('show');
      setTimeout(function () { $('#in_newJeeWhatsAppName').trigger('focus'); }, 400);
    };
  }

  // Validation avec Entrée dans le champ nom
  $('#in_newJeeWhatsAppName').on('keydown', function (e) {
    if (e.key === 'Enter') { $('#btn_confirmNewJeeWhatsApp').trigger('click'); }
    $(this).removeClass('jwa-input-error');
  });

  // Bouton Créer → sauvegarde et redirection vers la page de l'équipement
  $('#btn_confirmNewJeeWhatsApp').on('click', function () {
    var name = $('#in_newJeeWhatsAppName').val().trim();
    if (!name) {
      $('#in_newJeeWhatsAppName').addClass('jwa-input-error').trigger('focus');
      return;
    }
    var $btn = $(this).prop('disabled', true)
                      .html('<i class="fas fa-spinner fa-spin"></i>');

    jeedom.eqLogic.save({
      type: eqType,
      eqLogics: [{ name: name, isEnable: '1', isVisible: '1' }],
      error: function (error) {
        $btn.prop('disabled', false).html('<i class="fas fa-plus-circle"></i> {{Créer}}');
        jeedomUtils.showAlert({ message: error.message, level: 'danger' });
      },
      success: function (_data) {
        $('#modal_newJeeWhatsApp').modal('hide');
        jeeFrontEnd.modifyWithoutSave = false;
        if (typeof modifyWithoutSave !== 'undefined') { modifyWithoutSave = false; }
        var vars = getUrlVars();
        var url  = 'index.php?';
        for (var k in vars) {
          if (k !== 'id' && k !== 'saveSuccessFull' && k !== 'removeSuccessFull') {
            url += k + '=' + vars[k].replace('#', '') + '&';
          }
        }
        url += 'id=' + _data.id + '&saveSuccessFull=1';
        jeedomUtils.loadPage(url);
      }
    });
  });

});

function addCmdToTable(cmdParam) {
    const cmd = (isset(cmdParam) ? cmdParam : {});
    if (!isset(cmd.id)) { cmd.id = ''; }

    const isInfo     = (init(cmd.type) === 'info');
    const isAction   = (init(cmd.type) === 'action');
    const isNumeric  = (init(cmd.subType) === 'numeric');

    let tr = '<tr class="cmd" data-cmd_id="' + init(cmd.id) + '">';

    // ── Colonne # ──────────────────────────────────────────────────────────
    tr += '<td style="text-align:center;vertical-align:middle;">';
    tr += '<span class="cmdAttr" data-l1key="id">' + init(cmd.id) + '</span>';
    tr += '</td>';

    // ── Colonne Nom ────────────────────────────────────────────────────────
    tr += '<td style="vertical-align:middle;">';
    tr += '<div class="input-group">';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="name" placeholder="{{Nom}}">';
    tr += '<span class="input-group-btn">';
    tr += '<a class="btn btn-default btn-sm cmdAction" data-action="selectIcon" title="{{Icône}}">';
    tr += '<i class="fas fa-icons"></i></a>';
    tr += '<span class="cmdAttr" data-l1key="display" data-l2key="icon" style="margin-left:4px;line-height:28px;"></span>';
    tr += '</span>';
    tr += '</div>';
    tr += '<input class="cmdAttr" data-l1key="logicalId" style="display:none;">';
    tr += '<input class="cmdAttr" data-l1key="type" style="display:none;">';
    tr += '<input class="cmdAttr" data-l1key="subType" style="display:none;">';
    tr += '</td>';

    // ── Colonne Type ───────────────────────────────────────────────────────
    tr += '<td style="vertical-align:middle;text-align:center;">';
    if (isInfo) {
        tr += '<span class="label label-info">{{Info}}</span>';
    } else if (isAction) {
        tr += '<span class="label label-warning">{{Action}}</span>';
    }
    tr += '</td>';

    // ── Colonne Valeur actuelle ────────────────────────────────────────────
    tr += '<td style="vertical-align:middle;">';
    if (isInfo) {
        let curVal = init(cmd.currentValue) || init(cmd.state) || '—';
        if (curVal.length > 80) { curVal = curVal.substring(0, 80) + '…'; }
        tr += '<span class="label label-default"'
            + ' style="display:inline-block;max-width:100%;word-break:break-all;font-size:11px;font-weight:normal;padding:3px 6px;white-space:normal;">'
            + $('<span>').text(curVal).html()
            + '</span>';
    }
    tr += '</td>';

    // ── Colonne Options ────────────────────────────────────────────────────
    tr += '<td style="vertical-align:middle;">';
    tr += '<label class="checkbox-inline" style="font-size:11px;">';
    tr += '<input type="checkbox" class="cmdAttr" data-l1key="isVisible"> {{Afficher}}';
    tr += '</label>';
    if (isInfo && isNumeric) {
        tr += '<br><label class="checkbox-inline" style="font-size:11px;">';
        tr += '<input type="checkbox" class="cmdAttr" data-l1key="isHistorized"> {{Historiser}}';
        tr += '</label>';
    }
    tr += '</td>';

    // ── Colonne Actions ────────────────────────────────────────────────────
    tr += '<td style="vertical-align:middle;white-space:nowrap;">';
    if (is_numeric(cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure" title="{{Configuration avancée}}">';
        tr += '<i class="fas fa-cogs"></i></a> ';
        if (isAction) {
            tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test" title="{{Tester}}">';
            tr += '<i class="fas fa-rss"></i></a> ';
        }
    }
    tr += '<a class="btn btn-danger btn-xs cmdAction" data-action="remove" title="{{Supprimer}}">';
    tr += '<i class="fas fa-minus-circle"></i></a>';
    tr += '</td>';

    tr += '</tr>';

    const $tr = $(tr);
    $('#table_cmd tbody').append($tr);
    $tr.setValues(cmd, '.cmdAttr');
}
