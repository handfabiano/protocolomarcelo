<?php
/*
Plugin Name: Protocolo Gabinete Parlamentar Marcelo Nunes
Plugin URI:  https://protocolo.marcelonunesrr.com.br/
Description: v2.5.1 – Sistema para cadastro, movimentação, listagem e relatórios de protocolos, com hooks unificados, autoload PSR-4 e CSV streaming.
Version:     2.5.1
Author:      Fabiano Vieira
Author URI:  https://seusite.com/
Text Domain: protocolo.marcelonunesrr.com.br
*/

if (!defined('ABSPATH')) { exit; }

// === Constantes do plugin ===
if (!defined('PMN_FILE'))        define('PMN_FILE', __FILE__);
if (!defined('PMN_DIR'))         define('PMN_DIR', plugin_dir_path(__FILE__));
if (!defined('PMN_URL'))         define('PMN_URL', plugin_dir_url(__FILE__));
if (!defined('PMN_ASSETS_URL'))  define('PMN_ASSETS_URL', PMN_URL . 'assets/');
if (!defined('PMN_VERSION'))     define('PMN_VERSION', '2.5.1');

// === Autoloader PSR-4 ===
require_once __DIR__ . '/autoloader.php';

// === Fallback opcional para Forms (evita quebrar se o autoloader não achar) ===
if (!class_exists('ProtocoloMunicipal\\Forms') && file_exists(PMN_DIR . 'includes/class-forms.php')) {
    require_once PMN_DIR . 'includes/class-forms.php';
}

// === Shortcode de abas (compat legada) ===
if (file_exists(PMN_DIR . 'includes/ui-nav-tabs.php')) {
    require_once PMN_DIR . 'includes/ui-nav-tabs.php'; // registra [mn_nav_tabs]
}

// === I18n ===
add_action('plugins_loaded', function(){
    load_plugin_textdomain('protocolo.marcelonunesrr.com.br', false, dirname(plugin_basename(PMN_FILE)) . '/languages');
});

// === Boot centralizado das classes (com SAFE BOOT) ===
add_action('plugins_loaded', function(){
    $boot = function(string $fqcn){
        if (!class_exists($fqcn)) { return; }
        if (method_exists($fqcn, 'boot'))     { $fqcn::boot();     return; }
        if (method_exists($fqcn, 'init'))     { $fqcn::init();     return; }
        if (method_exists($fqcn, 'register')) { $fqcn::register(); return; }
    };
    // Ordem: tipos → init/assets/shortcodes → ajax → handlers → export → settings
    $boot('ProtocoloMunicipal\\CPT');
    $boot('ProtocoloMunicipal\\Init');
    $boot('ProtocoloMunicipal\\Actions');
    $boot('ProtocoloMunicipal\\Handlers');
    $boot('ProtocoloMunicipal\\Exports');
    $boot('ProtocoloMunicipal\\Settings');
});

// === Shortcodes legados (mn_*) – mantidos para compatibilidade, sem mudar layout ===
add_shortcode('mn_form_protocolo',         ['\\ProtocoloMunicipal\\Forms', 'render_protocolo_form']);
add_shortcode('mn_form_movimentar',        ['\\ProtocoloMunicipal\\Forms', 'render_movimentar_form']);
add_shortcode('mn_form_editar',            ['\\ProtocoloMunicipal\\Forms', 'render_editar_form']);
add_shortcode('mn_visualizar_protocolo',   ['\\ProtocoloMunicipal\\Forms', 'render_visualizar_protocolo']);

// Utilitários legados (se existirem na sua base)
if (method_exists('ProtocoloMunicipal\\Forms', 'shortcode_current_id')) {
    add_shortcode('mn_current_id', ['\\ProtocoloMunicipal\\Forms', 'shortcode_current_id']);
}
if (method_exists('ProtocoloMunicipal\\Forms', 'shortcode_list_page_ids')) {
    add_shortcode('mn_list_page_ids', ['\\ProtocoloMunicipal\\Forms', 'shortcode_list_page_ids']);
}

// === Ativação / Desativação ===
register_activation_hook(__FILE__, function(){
    // Garante registro do CPT e flush de permalinks
    if (class_exists('ProtocoloMunicipal\\CPT')) {
        if (method_exists('ProtocoloMunicipal\\CPT', 'boot')) {
            \ProtocoloMunicipal\CPT::boot();
        } elseif (method_exists('ProtocoloMunicipal\\CPT', 'register')) {
            \ProtocoloMunicipal\CPT::register();
        }
    }
    flush_rewrite_rules(false);
});

register_deactivation_hook(__FILE__, function(){
    flush_rewrite_rules(false);
});
