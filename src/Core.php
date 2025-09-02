<?php

namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) exit;

class Core
{
    private static $inst = null;

    // Slugs das páginas
    public string $slug_lista     = 'lista-de-protocolos';
    public string $slug_cadastro  = 'cadastrar-protocolo';
    public string $slug_movimentar= 'movimentar-protocolo';
    public string $slug_consulta  = 'consultar-protocolo';

    private function __construct() {}

    public static function instance(): self
    {
        if (!self::$inst) {
            self::$inst = new self();
        }
        return self::$inst;
    }

    // ======= Helpers usados pelos módulos =======

    // Exige login – retorna HTML de aviso ou string vazia se ok
    public function require_login(): string
    {
        if (\is_user_logged_in()) return '';
        $login = \wp_login_url(\home_url());
        return '<div class="mn-form">Você precisa estar logado. <a href="' . \esc_url($login) . '">Entrar</a></div>';
    }

    // Exige permissão – retorna HTML de aviso ou string vazia se ok
    public function require_permission(): string
    {
        // Aceita papel/cap personalizado ou caps do CPT
        if (\current_user_can('protocolo') || \current_user_can('edit_protocolos') || \current_user_can('edit_others_protocolos')) {
            return '';
        }
        return '<div class="mn-form">Sem permissão para acessar esta área.</div>';
    }

    // Barra com status de login
    public function barra_login_status(): string
    {
        if (!\is_user_logged_in()) {
            $login = \wp_login_url(\home_url());
            return '<div style="margin:8px 0 14px 0;">Não logado. <a href="' . \esc_url($login) . '">Entrar</a></div>';
        }
        $u   = \wp_get_current_user();
        $out = '<div style="margin:8px 0 14px 0;">Olá, <strong>' . \esc_html($u->display_name ?: $u->user_login) . '</strong> ';
        $out .= '– <a href="' . \esc_url(\wp_logout_url(\home_url())) . '">Sair</a></div>';
        return $out;
    }

    // CSS inline antigo – agora vazio (CSS está em assets)
    public function css_responsivo(): string
    {
        return '';
    }

    // Botões de navegação principais
    public function nav_buttons(string $ativo = ''): string
    {
        $links = [
            'lista'      => $this->link_by_slug($this->slug_lista,      '📄 Lista'),
            'cadastro'   => $this->link_by_slug($this->slug_cadastro,   '➕ Cadastrar'),
            'movimentar' => $this->link_by_slug($this->slug_movimentar, '🔁 Movimentar'),
            'consulta'   => $this->link_by_slug($this->slug_consulta,   '🔎 Consultar'),
        ];
        $html = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 14px 0">';
        foreach ($links as $key => $a) {
            if (!$a) continue;
            $style = 'display:inline-block;background:#eef5ff;border:1px solid #e3eaf9;color:#1757b6;padding:8px 12px;border-radius:8px;text-decoration:none;font-weight:600';
            if ($ativo === $key) $style .= ';outline:2px solid #0854ba';
            $html .= '<a class="mn-btn-nav" style="' . $style . '" href="' . \esc_url($a['url']) . '">' . $a['label'] . '</a>';
        }
        $html .= '</div>';
        return $html;
    }

    // Botão/Toggle de tema (id usado pelo JS em assets/js/protocolo.js)
    public function render_theme_toggle_btn(): void
    {
        echo '<button id="mn-theme-toggle" class="mn-theme-toggle" type="button" aria-label="Alternar tema" style="display:none;">🌓</button>';
    }

    // Sugestões (stub para compatibilidade)
    public function mn_sugestoes_usuario_primeiro(): string
    {
        return '';
    }

    // URL de detalhe do protocolo (stub – ajuste se tiver página específica)
    public function get_protocolo_detalhe_url(int $post_id): string
    {
        $pg = \get_page_by_path('visualizacao-detalhada-do-protocolo');
        if ($pg) return \add_query_arg(['id' => $post_id], \get_permalink($pg));
        return \get_permalink($post_id);
    }

    // ======= Util =======
    private function link_by_slug(string $slug, string $label): ?array
    {
        if (!$slug) return null;
        $page = \get_page_by_path($slug);
        if (!$page) return null;
        return ['url' => \get_permalink($page), 'label' => $label];
    }
}
