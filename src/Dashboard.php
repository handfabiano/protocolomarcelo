<?php
namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) exit;

/**
 * Dashboard Moderno - Sistema Protocolo Municipal
 * Implementa métricas, gráficos, timeline e interface responsiva
 */
class Dashboard
{
    private static $instance = null;

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function boot(): void
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_dashboard_assets']);
        add_action('wp_ajax_pmn_dashboard_stats', [__CLASS__, 'ajax_get_stats']);
        add_action('wp_ajax_pmn_dashboard_timeline', [__CLASS__, 'ajax_get_timeline']);
        
        // Shortcode para o dashboard
        add_shortcode('protocolo_dashboard', [__CLASS__, 'render_dashboard']);
    }

    /**
     * Enfileira assets específicos do dashboard
     */
    public static function enqueue_dashboard_assets(): void
    {
        if (!self::is_dashboard_page()) return;

        // Chart.js para gráficos
        wp_enqueue_script('chartjs', 'https://cdnjs.cloudflare.com/ajax/libs/chart.js/4.4.0/chart.umd.js', [], '4.4.0', true);
        
        // CSS do dashboard
        wp_enqueue_style('pmn-dashboard', PMN_ASSETS_URL . 'css/dashboard.css', ['pmn-protocolo'], PMN_VERSION);
        
        // JS do dashboard
        wp_enqueue_script('pmn-dashboard', PMN_ASSETS_URL . 'js/dashboard.js', ['jquery', 'chartjs'], PMN_VERSION, true);
        
        // Localização para AJAX
        wp_localize_script('pmn-dashboard', 'pmnDashboard', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pmn_dashboard_nonce'),
            'autoRefresh' => apply_filters('pmn_dashboard_auto_refresh', 300000) // 5 min
        ]);
    }

    /**
     * Renderiza o dashboard completo
     */
    public static function render_dashboard($atts = []): string
    {
        $atts = shortcode_atts([
            'show_charts' => 'true',
            'show_timeline' => 'true',
            'show_actions' => 'true',
            'auto_refresh' => 'true'
        ], $atts);

        $core = Core::instance();
        
        // Verificação de permissões
        $login_check = $core->require_login();
        if ($login_check) return $login_check;
        
        $perm_check = $core->require_permission();
        if ($perm_check) return $perm_check;

        ob_start();
        ?>
        <div class="pmn-dashboard" data-auto-refresh="<?php echo esc_attr($atts['auto_refresh']); ?>">
            <!-- Header do Dashboard -->
            <div class="pmn-dash-header">
                <div class="pmn-dash-title">
                    <h1>📊 Dashboard - Protocolos</h1>
                    <p>Visão geral do sistema de protocolos municipais</p>
                </div>
                <div class="pmn-dash-controls">
                    <button class="pmn-btn pmn-btn-refresh" id="pmn-refresh-data" type="button">
                        <span class="pmn-icon">🔄</span> Atualizar
                    </button>
                    <?php echo $core->nav_buttons('dashboard'); ?>
                </div>
            </div>

            <!-- Cards de Métricas -->
            <div class="pmn-metrics-grid" id="pmn-metrics">
                <div class="pmn-metric-card pmn-loading">
                    <div class="pmn-metric-icon">📄</div>
                    <div class="pmn-metric-content">
                        <div class="pmn-metric-value" data-metric="total">-</div>
                        <div class="pmn-metric-label">Total de Protocolos</div>
                        <div class="pmn-metric-change" data-change="total">-</div>
                    </div>
                </div>

                <div class="pmn-metric-card pmn-loading">
                    <div class="pmn-metric-icon">🔄</div>
                    <div class="pmn-metric-content">
                        <div class="pmn-metric-value" data-metric="tramitacao">-</div>
                        <div class="pmn-metric-label">Em Tramitação</div>
                        <div class="pmn-metric-change" data-change="tramitacao">-</div>
                    </div>
                </div>

                <div class="pmn-metric-card pmn-loading">
                    <div class="pmn-metric-icon">✅</div>
                    <div class="pmn-metric-content">
                        <div class="pmn-metric-value" data-metric="concluidos">-</div>
                        <div class="pmn-metric-label">Concluídos</div>
                        <div class="pmn-metric-change" data-change="concluidos">-</div>
                    </div>
                </div>

                <div class="pmn-metric-card pmn-loading">
                    <div class="pmn-metric-icon">⚠️</div>
                    <div class="pmn-metric-content">
                        <div class="pmn-metric-value" data-metric="atrasados">-</div>
                        <div class="pmn-metric-label">Atrasados</div>
                        <div class="pmn-metric-change" data-change="atrasados">-</div>
                    </div>
                </div>
            </div>

            <?php if ($atts['show_charts'] === 'true'): ?>
            <!-- Seção de Gráficos -->
            <div class="pmn-charts-section">
                <div class="pmn-charts-grid">
                    <div class="pmn-chart-card">
                        <div class="pmn-chart-header">
                            <h3>📈 Status dos Protocolos</h3>
                        </div>
                        <div class="pmn-chart-container">
                            <canvas id="pmn-status-chart"></canvas>
                        </div>
                    </div>

                    <div class="pmn-chart-card">
                        <div class="pmn-chart-header">
                            <h3>📊 Por Tipo de Documento</h3>
                        </div>
                        <div class="pmn-chart-container">
                            <canvas id="pmn-tipo-chart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="pmn-chart-card pmn-chart-wide">
                    <div class="pmn-chart-header">
                        <h3>📅 Protocolos nos Últimos 30 Dias</h3>
                    </div>
                    <div class="pmn-chart-container">
                        <canvas id="pmn-timeline-chart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="pmn-dash-content">
                <?php if ($atts['show_timeline'] === 'true'): ?>
                <!-- Timeline de Atividades -->
                <div class="pmn-dash-section">
                    <div class="pmn-section-header">
                        <h2>🕒 Atividades Recentes</h2>
                        <button class="pmn-btn pmn-btn-ghost" id="pmn-load-more-timeline">Ver mais</button>
                    </div>
                    <div class="pmn-timeline-container" id="pmn-recent-timeline">
                        <div class="pmn-loading-spinner">Carregando atividades...</div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($atts['show_actions'] === 'true'): ?>
                <!-- Ações Rápidas -->
                <div class="pmn-dash-section">
                    <div class="pmn-section-header">
                        <h2>⚡ Ações Rápidas</h2>
                    </div>
                    <div class="pmn-quick-actions">
                        <?php echo self::render_quick_actions(); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Alertas e Notificações -->
            <div class="pmn-alerts-section" id="pmn-alerts">
                <!-- Preenchido via JavaScript -->
            </div>
        </div>

        <!-- Loading Overlay -->
        <div class="pmn-loading-overlay" id="pmn-loading-overlay" style="display: none;">
            <div class="pmn-loading-content">
                <div class="pmn-spinner"></div>
                <p>Atualizando dados...</p>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Renderiza ações rápidas
     */
    private static function render_quick_actions(): string
    {
        $core = Core::instance();
        
        $actions = [
            [
                'icon' => '➕',
                'title' => 'Novo Protocolo',
                'desc' => 'Cadastrar um novo protocolo',
                'url' => self::get_page_url($core->slug_cadastro),
                'class' => 'primary'
            ],
            [
                'icon' => '🔍',
                'title' => 'Buscar Protocolo',
                'desc' => 'Consultar protocolo existente',
                'url' => self::get_page_url($core->slug_consulta),
                'class' => 'secondary'
            ],
            [
                'icon' => '🔁',
                'title' => 'Movimentar',
                'desc' => 'Movimentar protocolo',
                'url' => self::get_page_url($core->slug_movimentar),
                'class' => 'secondary'
            ],
            [
                'icon' => '📊',
                'title' => 'Relatórios',
                'desc' => 'Gerar relatórios detalhados',
                'url' => self::get_page_url('relatorios'),
                'class' => 'secondary'
            ]
        ];

        $html = '';
        foreach ($actions as $action) {
            if (!$action['url']) continue;
            
            $html .= sprintf(
                '<a href="%s" class="pmn-quick-action pmn-action-%s">
                    <div class="pmn-action-icon">%s</div>
                    <div class="pmn-action-content">
                        <h4>%s</h4>
                        <p>%s</p>
                    </div>
                </a>',
                esc_url($action['url']),
                esc_attr($action['class']),
                esc_html($action['icon']),
                esc_html($action['title']),
                esc_html($action['desc'])
            );
        }

        return $html;
    }

    /**
     * AJAX: Retorna estatísticas do dashboard
     */
    public static function ajax_get_stats(): void
    {
        check_ajax_referer('pmn_dashboard_nonce', 'nonce');
        
        if (!current_user_can('edit_protocolos')) {
            wp_die('Sem permissão', '', 403);
        }

        $stats = self::get_dashboard_stats();
        wp_send_json_success($stats);
    }

    /**
     * AJAX: Retorna timeline de atividades
     */
    public static function ajax_get_timeline(): void
    {
        check_ajax_referer('pmn_dashboard_nonce', 'nonce');
        
        if (!current_user_can('edit_protocolos')) {
            wp_die('Sem permissão', '', 403);
        }

        $limit = (int) ($_POST['limit'] ?? 10);
        $offset = (int) ($_POST['offset'] ?? 0);
        
        $timeline = self::get_recent_activities($limit, $offset);
        wp_send_json_success($timeline);
    }

    /**
     * Calcula estatísticas do dashboard
     */
    private static function get_dashboard_stats(): array
    {
        global $wpdb;
        
        $now = current_time('Y-m-d');
        $last_month = date('Y-m-d', strtotime('-30 days'));
        
        // Query base para protocolos
        $base_query = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN pm1.meta_value = 'Em tramitação' THEN 1 ELSE 0 END) as tramitacao,
                SUM(CASE WHEN pm1.meta_value = 'Concluído' THEN 1 ELSE 0 END) as concluidos,
                SUM(CASE WHEN pm1.meta_value = 'Pendente' THEN 1 ELSE 0 END) as pendentes,
                SUM(CASE WHEN pm1.meta_value = 'Arquivado' THEN 1 ELSE 0 END) as arquivados
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON (p.ID = pm1.post_id AND pm1.meta_key = 'status')
            WHERE p.post_type = 'protocolo' 
            AND p.post_status = 'publish'
        ";

        // Stats atuais
        $current = $wpdb->get_row($base_query);
        
        // Stats do mês passado para comparação
        $last_month_query = $base_query . " AND p.post_date >= '{$last_month}'";
        $last_month_stats = $wpdb->get_row($last_month_query);
        
        // Protocolos atrasados (prazo vencido e não concluídos)
        $atrasados_query = "
            SELECT COUNT(*) as atrasados
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON (p.ID = pm1.post_id AND pm1.meta_key = 'status')
            LEFT JOIN {$wpdb->postmeta} pm2 ON (p.ID = pm2.post_id AND pm2.meta_key = 'data')
            LEFT JOIN {$wpdb->postmeta} pm3 ON (p.ID = pm3.post_id AND pm3.meta_key = 'prazo')
            WHERE p.post_type = 'protocolo' 
            AND p.post_status = 'publish'
            AND pm1.meta_value != 'Concluído'
            AND pm3.meta_value > 0
            AND DATE_ADD(pm2.meta_value, INTERVAL CAST(pm3.meta_value AS SIGNED) DAY) < '{$now}'
        ";
        
        $atrasados = $wpdb->get_var($atrasados_query);
        
        // Por tipo de documento
        $tipos_query = "
            SELECT 
                pm.meta_value as tipo,
                COUNT(*) as count
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = 'tipo_documento')
            WHERE p.post_type = 'protocolo' 
            AND p.post_status = 'publish'
            AND pm.meta_value IS NOT NULL
            GROUP BY pm.meta_value
            ORDER BY count DESC
        ";
        
        $tipos = $wpdb->get_results($tipos_query);
        
        // Timeline dos últimos 30 dias
        $timeline_query = "
            SELECT 
                DATE(p.post_date) as data,
                COUNT(*) as count
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'protocolo' 
            AND p.post_status = 'publish'
            AND p.post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(p.post_date)
            ORDER BY data ASC
        ";
        
        $timeline = $wpdb->get_results($timeline_query);

        return [
            'metrics' => [
                'total' => (int) $current->total,
                'tramitacao' => (int) $current->tramitacao,
                'concluidos' => (int) $current->concluidos,
                'pendentes' => (int) $current->pendentes,
                'arquivados' => (int) $current->arquivados,
                'atrasados' => (int) $atrasados
            ],
            'changes' => [
                'total' => self::calculate_change($current->total, $last_month_stats->total ?? 0),
                'tramitacao' => self::calculate_change($current->tramitacao, $last_month_stats->tramitacao ?? 0),
                'concluidos' => self::calculate_change($current->concluidos, $last_month_stats->concluidos ?? 0),
                'atrasados' => (int) $atrasados // Sempre atual
            ],
            'charts' => [
                'status' => [
                    'labels' => ['Em Tramitação', 'Concluído', 'Pendente', 'Arquivado'],
                    'data' => [$current->tramitacao, $current->concluidos, $current->pendentes, $current->arquivados],
                    'colors' => ['#3B82F6', '#10B981', '#F59E0B', '#6B7280']
                ],
                'tipos' => [
                    'labels' => array_column($tipos, 'tipo'),
                    'data' => array_column($tipos, 'count'),
                    'colors' => ['#8B5CF6', '#06B6D4', '#84CC16', '#F97316', '#EF4444']
                ],
                'timeline' => [
                    'labels' => array_column($timeline, 'data'),
                    'data' => array_column($timeline, 'count')
                ]
            ],
            'timestamp' => current_time('c')
        ];
    }

    /**
     * Busca atividades recentes
     */
    private static function get_recent_activities(int $limit = 10, int $offset = 0): array
    {
        global $wpdb;
        
        // Busca protocolos recentes com suas movimentações
        $query = "
            SELECT 
                p.ID,
                p.post_title as numero,
                p.post_date,
                pm1.meta_value as status,
                pm2.meta_value as tipo_documento,
                pm3.meta_value as assunto
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON (p.ID = pm1.post_id AND pm1.meta_key = 'status')
            LEFT JOIN {$wpdb->postmeta} pm2 ON (p.ID = pm2.post_id AND pm2.meta_key = 'tipo_documento')
            LEFT JOIN {$wpdb->postmeta} pm3 ON (p.ID = pm3.post_id AND pm3.meta_key = 'assunto')
            WHERE p.post_type = 'protocolo' 
            AND p.post_status = 'publish'
            ORDER BY p.post_modified DESC
            LIMIT %d OFFSET %d
        ";
        
        $results = $wpdb->get_results($wpdb->prepare($query, $limit, $offset));
        
        $activities = [];
        foreach ($results as $row) {
            $activities[] = [
                'id' => $row->ID,
                'numero' => $row->numero,
                'assunto' => $row->assunto,
                'status' => $row->status,
                'tipo_documento' => $row->tipo_documento,
                'data' => $row->post_date,
                'data_formatada' => date_i18n('d/m/Y H:i', strtotime($row->post_date)),
                'url' => get_permalink($row->ID)
            ];
        }
        
        return $activities;
    }

    /**
     * Calcula mudança percentual
     */
    private static function calculate_change(int $current, int $previous): array
    {
        if ($previous == 0) {
            return ['percent' => $current > 0 ? 100 : 0, 'direction' => 'up'];
        }
        
        $change = (($current - $previous) / $previous) * 100;
        
        return [
            'percent' => abs(round($change)),
            'direction' => $change >= 0 ? 'up' : 'down'
        ];
    }

    /**
     * Obtém URL da página por slug
     */
    private static function get_page_url(string $slug): ?string
    {
        $page = get_page_by_path($slug);
        return $page ? get_permalink($page) : null;
    }

    /**
     * Verifica se é página do dashboard
     */
    private static function is_dashboard_page(): bool
    {
        global $post;
        
        if (!$post) return false;
        
        return (
            has_shortcode($post->post_content, 'protocolo_dashboard') ||
            strpos($post->post_content, 'protocolo_dashboard') !== false
        );
    }
}