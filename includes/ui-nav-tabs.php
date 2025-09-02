<?php
// Barra de abas para as páginas públicas do Protocolo
if (!defined('ABSPATH')) exit;

function pmn_nav_tabs_html() {
    // Resolve slugs conhecidos (ajuste se seus slugs forem diferentes)
    $pLista = get_page_by_path('lista-de-protocolos'); if (!$pLista) $pLista = get_page_by_path('lista');
    $pNovo  = get_page_by_path('cadastrar');           if (!$pNovo)  $pNovo  = get_page_by_path('novo');
    $pCons  = get_page_by_path('visualizar-protocolo');if (!$pCons)  $pCons  = get_page_by_path('consultar');

    $url_lista = $pLista ? get_permalink($pLista) : '';
    $url_novo  = $pNovo  ? get_permalink($pNovo)  : '';
    $url_cons  = $pCons  ? get_permalink($pCons)  : '';

    // Página atual para marcar "ativa"
    $current = '';
    if (is_page()) {
        $obj = get_queried_object();
        $current = $obj && !empty($obj->post_name) ? $obj->post_name : '';
    }

    ob_start(); ?>
    <div class="mn-tabs">
        <?php if ($url_lista): ?>
            <a class="mn-tab <?php echo (in_array($current, ['lista-de-protocolos','lista']) ? 'is-active':''); ?>" href="<?php echo esc_url($url_lista); ?>">📄 Lista</a>
        <?php endif; ?>
        <?php if ($url_novo): ?>
            <a class="mn-tab" href="<?php echo esc_url($url_novo); ?>">➕ Novo (Wizard)</a>
        <?php endif; ?>
        <?php if ($url_cons): ?>
            <a class="mn-tab" href="<?php echo esc_url($url_cons); ?>">🔎 Consultar</a>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// Shortcode para usar no editor da página
add_shortcode('mn_nav_tabs', 'pmn_nav_tabs_html');

// CSS básico das abas (carregado inline para facilitar)
add_action('wp_head', function () {
    if (!is_page()) return;
    echo '<style>
    .mn-tabs{display:flex;gap:12px;margin:10px 0 14px}
    .mn-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border:1.5px solid #dfe3ea;border-radius:12px;text-decoration:none;font-weight:700;color:#1d3557;background:#f8fafc}
    .mn-tab:hover{filter:brightness(1.02)}
    .mn-tab.is-active{background:#e7f0ff;border-color:#b9d0ff}
    </style>';
});
