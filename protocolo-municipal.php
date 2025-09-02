<?php
/*
Plugin Name: Protocolo Gabinete Parlamentar Marcelo Nunes
Plugin URI:  https://protocolo.marcelonunesrr.com.br/
Description: v2.5.4 – Bootstrap blindado (CPT no 'init'), roles/caps, modo impressão (?print=1), autoload PSR-4 e constantes de assets (PMN_ASSETS_URL/PMN_ASSETS_DIR).
Version:     2.5.4
Author:      Fabiano Vieira
Author URI:  https://seusite.com/
Text Domain: protocolo.marcelonunesrr.com.br
*/

if (!defined('ABSPATH')) { exit; }

// === Constantes do plugin ===
if (!defined('PMN_FILE'))        define('PMN_FILE', __FILE__);
if (!defined('PMN_DIR'))         define('PMN_DIR', plugin_dir_path(__FILE__));
if (!defined('PMN_URL'))         define('PMN_URL', plugin_dir_url(__FILE__));
if (!defined('PMN_VERSION'))     define('PMN_VERSION', '2.5.4');
if (!defined('PMN_USE_MIN'))     define('PMN_USE_MIN', true); // preferir .min se existir

// *** NOVO: caminhos/URLs de assets (para compat com Init.php atual) ***
if (!defined('PMN_ASSETS_DIR'))  define('PMN_ASSETS_DIR', PMN_DIR . 'assets/');
if (!defined('PMN_ASSETS_URL'))  define('PMN_ASSETS_URL', PMN_URL . 'assets/');

// Autoloader PSR-4
$__autoloader = PMN_DIR . 'autoloader.php';
if (is_file($__autoloader)) { require_once $__autoloader; }

// Carrega textdomain se a pasta languages existir
add_action('plugins_loaded', function () {
    $lang_dir = PMN_DIR . 'languages/';
    if (is_dir($lang_dir)) {
        load_plugin_textdomain('protocolo.marcelonunesrr.com.br', false, dirname(plugin_basename(PMN_FILE)) . '/languages');
    }
}, 1);

// Helper para URL de assets (.min quando existir)
if (!function_exists('pmn_asset')) {
    function pmn_asset(string $relative): string {
        $relative = ltrim($relative, '/');
        $url  = PMN_URL . $relative;
        if (defined('PMN_USE_MIN') && PMN_USE_MIN && preg_match('#^(.*)\.(css|js)$#', $relative, $m)) {
            $maybe = $m[1] . '.min.' . $m[2];
            if (is_file(PMN_DIR . $maybe)) { return PMN_URL . $maybe; }
        }
        return $url;
    }
}

// === Modo impressão (?print=1) – página limpa ===
add_action('template_redirect', function () {
    if (!isset($_GET['print']) || $_GET['print'] != '1') return;
    if (is_admin() || defined('REST_REQUEST')) return;

    $post_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
    $content = '';
    if ($post_id > 0) {
        $post = get_post($post_id);
        if ($post) $content = apply_filters('the_content', $post->post_content);
    }

    nocache_headers();
    ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html(get_the_title($post_id)); ?> — Impressão</title>
<style>
    @media print { @page { margin: 10mm; } html, body { background:#fff!important; } a[href]:after{content:"";} .no-print{display:none!important;} }
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 16px; }
    .pmn-print-wrap { max-width: 1024px; margin: 0 auto; }
</style>
</head>
<body onload="window.print()">
<div class="pmn-print-wrap"><?php echo $content; ?></div>
</body>
</html><?php
    exit;
}, 1);

// === Roles/Capabilities ===
register_activation_hook(__FILE__, function () {
    $caps_protocolo = [
        'read' => true,
        'upload_files' => true,
        'read_protocolo'                   => true,
        'read_private_protocolos'          => true,
        'edit_protocolo'                   => true,
        'edit_protocolos'                  => true,
        'edit_others_protocolos'           => true,
        'edit_published_protocolos'        => true,
        'publish_protocolos'               => true,
        'delete_protocolo'                 => true,
        'delete_protocolos'                => true,
        'delete_others_protocolos'         => true,
        'delete_private_protocolos'        => true,
        'delete_published_protocolos'      => true,
    ];

    $caps_leitor = [
        'read' => true,
        'read_protocolo'          => true,
        'read_private_protocolos' => true,
    ];

    if (null === get_role('protocolo')) { add_role('protocolo', 'Protocolista', $caps_protocolo); }
    else { $role = get_role('protocolo'); if ($role) foreach ($caps_protocolo as $cap => $grant) $role->add_cap($cap, $grant); }

    if (null === get_role('leitor')) { add_role('leitor', 'Leitor de Protocolos', $caps_leitor); }
    else { $role = get_role('leitor'); if ($role) foreach ($caps_leitor as $cap => $grant) $role->add_cap($cap, $grant); }

    $admin = get_role('administrator');
    if ($admin) foreach ($caps_protocolo as $cap => $grant) $admin->add_cap($cap, true);

    update_option('pmn_needs_flush', '1'); // flush depois do CPT registrar
});

register_deactivation_hook(__FILE__, function(){ flush_rewrite_rules(false); });

// === Boot seguro das classes ===
add_action('plugins_loaded', function () {
    $candidates = [
        'ProtocoloMunicipal\\Init',
        'ProtocoloMunicipal\\Core',
        // CPT em 'init' para evitar add_rewrite_tag fatal
        'ProtocoloMunicipal\\Handlers',
        'ProtocoloMunicipal\\Actions',
        'ProtocoloMunicipal\\Lista',
        'ProtocoloMunicipal\\Report',
        'ProtocoloMunicipal\\Exports',
        'ProtocoloMunicipal\\Timeline',
        'ProtocoloMunicipal\\Settings',
        'ProtocoloMunicipal\\Usuario',
        'ProtocoloMunicipal\\Dashboard',
    ];

    foreach ($candidates as $fqcn) {
        if (!class_exists($fqcn)) continue;
        if (method_exists($fqcn, 'boot'))         { $fqcn::boot(); }
        elseif (method_exists($fqcn, 'init'))     { $fqcn::init(); }
        elseif (method_exists($fqcn, 'register')) { $fqcn::register(); }
    }

    if (class_exists('ProtocoloMunicipal\\CPT')) {
        add_action('init', function () {
            if (method_exists('ProtocoloMunicipal\\CPT', 'boot'))      \ProtocoloMunicipal\CPT::boot();
            elseif (method_exists('ProtocoloMunicipal\\CPT', 'register')) \ProtocoloMunicipal\CPT::register();
        }, 0);
    }
}, 5);

// Flush após o init (CPT já registrado)
add_action('init', function () {
    if (get_option('pmn_needs_flush') === '1') {
        flush_rewrite_rules(false);
        delete_option('pmn_needs_flush');
    }
}, 99);

// Helper legado
if (!function_exists('pmn_require_login')) {
    function pmn_require_login() {
        if (is_user_logged_in()) return '';
        return '<div class="notice notice-warning"><p>É necessário estar logado para acessar esta página.</p></div>';
    }
}
