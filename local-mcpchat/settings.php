<?php
// This file is part of the local_mcpchat plugin for Moodle.
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_mcpchat', get_string('pluginname', 'local_mcpchat'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configpasswordunmask(
        'local_mcpchat/apikey',
        get_string('apikey', 'local_mcpchat'),
        get_string('apikey_desc', 'local_mcpchat'),
        ''));

    $settings->add(new admin_setting_configtext(
        'local_mcpchat/model',
        get_string('model', 'local_mcpchat'),
        get_string('model_desc', 'local_mcpchat'),
        'deepseek-v4-flash'));

    $settings->add(new admin_setting_configtextarea(
        'local_mcpchat/systemprompt',
        get_string('systemprompt', 'local_mcpchat'),
        get_string('systemprompt_desc', 'local_mcpchat'),
        'Eres el asistente de gestion de Moodle. Respondes en el idioma del usuario. ' .
        'Cuando una peticion corresponde a una herramienta, ejecutala SIEMPRE con ' .
        'la herramienta; no describas en texto lo que puedes hacer con ella. Las ' .
        'herramientas de escritura marcadas como riesgosas no se ejecutan al ' .
        'momento: el sistema pide confirmacion al usuario y se ejecutan solo ' .
        'despues de que confirme; por eso invoca la herramienta directamente y ' .
        'nunca pidas permiso en texto. Si el usuario pide algo fuera de tu ' .
        'alcance, dilo claro. No inventes datos: si una operacion falla, reporta ' .
        'el error que recibas. ' .
        'Respondes SIEMPRE en HTML limpio: parrafos <p>, listas <ul>/<li>, ' .
        'negritas <b>, encabezados <h4> y tablas <table> cuando listes datos; ' .
        'nunca uses markdown (*, #, |, ...) ni bloques de codigo; no muestres ' .
        'ids internos de Moodle: ninguna columna de ID ni numeros de ' .
        'curso/tarea/usuario, usa solo nombres; se breve y directo.',
        PARAM_RAW));

    $settings->add(new admin_setting_configtext(
        'local_mcpchat/maxmsgsday',
        get_string('maxmsgsday', 'local_mcpchat'),
        get_string('maxmsgsday_desc', 'local_mcpchat'),
        '100', PARAM_INT));

    $settings->add(new admin_setting_configtext(
        'local_mcpchat/contextomsgs',
        get_string('contextomsgs', 'local_mcpchat'),
        get_string('contextomsgs_desc', 'local_mcpchat'),
        '20', PARAM_INT));

    $settings->add(new admin_setting_configtext(
        'local_mcpchat/riesgosas',
        get_string('riesgosas', 'local_mcpchat'),
        get_string('riesgosas_desc', 'local_mcpchat'),
        'calificar_entrega,calificar_lote,otorgar_prorroga,desmatricular_usuario,clonar_curso,revertir_a_borrador,crear_usuario',
        PARAM_RAW));
}
