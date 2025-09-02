<?php
namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) { exit; }

class Init
{
    /** Lista de shortcodes que exigem assets globais */
    private const SHORTCODES_BASE = [
        // novos
        'protocolo_form', 'protocolo_movimentar', 'protocolo_editar', 'protocolo_visualizar',
        'protocolo_lista', 'protocolo_tabela', 'protocolo_relatorio', 'protocolo_consulta',
        // legados (compat)
        'mn_form_protocolo', 'mn_form_movimentar', 'mn_form_editar', 'mn_visualizar_protocolo',
        'mn_nav_tabs',
    ];

    public static function boot(): void
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('init', [__CLASS__, 'register_shortcodes']);
    }

    /** Registra e enfileira assets apenas quando necessários */
    public static function enqueue_assets(): void
    {
        // Registra CSS/JS base do plugin (carregados no rodapé)
        wp_register_style('pmn-protocolo', PMN_ASSETS_URL . 'css/protocolo.css', [], PMN_VERSION);
        wp_register_script('pmn-protocolo', PMN_ASSETS_URL . 'js/protocolo.js', ['jquery'], PMN_VERSION, true);

        // Heurística simples: carrega assets se a página tiver algum shortcode do plugin
        if (self::should_enqueue()) {
            wp_enqueue_style('pmn-protocolo');
            wp_enqueue_script('pmn-protocolo');
        }
    }

    /** Shortcodes principais (novos), sem alterar o layout atual */
    public static function register_shortcodes(): void
    {
        // Formulários
        add_shortcode('protocolo_form',        ['\\ProtocoloMunicipal\\Forms', 'render_protocolo_form']);
        add_shortcode('protocolo_movimentar',  ['\\ProtocoloMunicipal\\Forms', 'render_movimentar_form']);
        add_shortcode('protocolo_editar',      ['\\ProtocoloMunicipal\\Forms', 'render_editar_form']);
        add_shortcode('protocolo_visualizar',  ['\\ProtocoloMunicipal\\Forms', 'render_visualizar_protocolo']);

        // Lista / Tabela
        add_shortcode('protocolo_lista',  [__CLASS__, 'sc_lista']);
        add_shortcode('protocolo_tabela', [__CLASS__, 'sc_lista']); // alias

        // Relatório (página com impressão e export)
        add_shortcode('protocolo_relatorio', [__CLASS__, 'sc_relatorio']);

        // Consulta pública/privada por número (fallback simples)
        add_shortcode('protocolo_consulta', [__CLASS__, 'sc_consulta']);
    }

    // === Implementações dos shortcodes de dados ===

    /** Wrapper para a listagem/tabela, mantendo compatibilidade com sua classe atual */
    public static function sc_lista($atts = [], $content = ''): string
{
    // Tenta carregar explicitamente se o autoloader não pegou
    if (!class_exists('\\ProtocoloMunicipal\\ListTable') && !class_exists('\\ProtocoloMunicipal\\Lista')) {
        $file = PMN_DIR . 'src/Lista.php';
        if (is_file($file)) { require_once $file; }
    }

    $candidates = [
        '\\ProtocoloMunicipal\\ListTable',
        '\\ProtocoloMunicipal\\Lista',
        '\\ListTable', // caso o arquivo esteja sem namespace
    ];
    $methods = ['render', 'output', 'shortcode', 'index'];

    foreach ($candidates as $cls) {
        if (class_exists($cls)) {
            foreach ($methods as $m) {
                if (method_exists($cls, $m)) {
                    return (string) call_user_func([$cls, $m], $atts, $content);
                }
            }
        }
    }

    // Procedural fallbacks
    $funcs = ['pmn_render_lista', 'pmn_lista_shortcode', 'protocolo_lista'];
    foreach ($funcs as $fn) {
        if (function_exists($fn)) {
            return (string) call_user_func($fn, $atts, $content);
        }
    }

    return '<div class="notice notice-error"><p>Lista não disponível no momento.</p></div>';
}

public static function sc_relatorio($atts = [], $content = ''): string
{
    if (!class_exists('\\ProtocoloMunicipal\\Report')) {
        $file = PMN_DIR . 'src/Report.php';
        if (is_file($file)) { require_once $file; }
    }

    $candidates = [
        '\\ProtocoloMunicipal\\Report',
        '\\ProtocoloMunicipal\\Relatorio',
        '\\Report', // sem namespace
    ];
    $methods = ['render', 'output', 'shortcode', 'index'];

    foreach ($candidates as $cls) {
        if (class_exists($cls)) {
            foreach ($methods as $m) {
                if (method_exists($cls, $m)) {
                    return (string) call_user_func([$cls, $m], $atts, $content);
                }
            }
        }
    }

    // Procedural fallbacks
    $funcs = ['pmn_render_relatorio', 'pmn_relatorio_shortcode', 'protocolo_relatorio'];
    foreach ($funcs as $fn) {
        if (function_exists($fn)) {
            return (string) call_user_func($fn, $atts, $content);
        }
    }

    return '<div class="notice notice-error"><p>Relatório indisponível.</p></div>';
}

    /** Wrapper para relatório */
 
    /** Wrapper para consulta por número/token */
    public static function sc_consulta($atts = [], $content = ''): string
    {
        // Se existir um método dedicado
        if (method_exists('ProtocoloMunicipal\\Forms', 'render_consulta_protocolo')) {
            return (string) \ProtocoloMunicipal\Forms::render_consulta_protocolo($atts, $content);
        }
        // caso contrário, reutiliza a visualização padrão
        if (method_exists('ProtocoloMunicipal\\Forms', 'render_visualizar_protocolo')) {
            return (string) \ProtocoloMunicipal\Forms::render_visualizar_protocolo($atts, $content);
        }
        return '<div class="notice notice-error"><p>Consulta indisponível.</p></div>';
    }

    // === Helpers ===

    /** Determina se deve enfileirar os assets base nesta página */
    private static function should_enqueue(): bool
    {
        // Admin: carrega assets apenas em páginas de conteúdo (não sobrecarregar wp-admin desnecessariamente)
        if (is_admin()) {
            return false;
        }

        global $post;
        if (!$post || empty($post->post_content)) {
            return false;
        }

        $content = (string) $post->post_content;
        foreach (self::SHORTCODES_BASE as $tag) {
            if (has_shortcode($content, $tag)) {
                return true;
            }
        }
        return false;
    }
}
