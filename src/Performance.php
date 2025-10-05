<?php
namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) exit;

/**
 * Otimizações de Performance do Sistema de Protocolos
 * 
 * - Cache inteligente
 * - Índices no banco
 * - Queries otimizadas
 * - Lazy loading
 * 
 * @version 1.0
 */
class Performance
{
    private const CACHE_GROUP = 'pmn_protocols';
    private const CACHE_LONG = 3600;     // 1 hora
    private const CACHE_MEDIUM = 900;    // 15 minutos
    private const CACHE_SHORT = 300;     // 5 minutos
    
    /**
     * Inicializa otimizações
     */
    public static function boot(): void
    {
        // Cria índices na ativação
        register_activation_hook(PMN_FILE, [__CLASS__, 'create_indexes']);
        
        // Cache de queries
        add_action('save_post_protocolo', [__CLASS__, 'clear_cache_on_save']);
        add_action('deleted_post', [__CLASS__, 'clear_cache_on_delete']);
        
        // Otimiza admin
        add_filter('posts_clauses', [__CLASS__, 'optimize_meta_queries'], 10, 2);
        
        // Preload critical data
        add_action('wp', [__CLASS__, 'preload_dashboard_data']);
        
        // Lazy load timeline
        add_action('wp_ajax_pmn_load_timeline_lazy', [__CLASS__, 'ajax_lazy_timeline']);
    }
    
    /**
     * Cria índices no banco para otimizar queries
     */
    public static function create_indexes(): void
    {
        global $wpdb;
        
        $indexes = [
            // Índice composto para filtros comuns
            "CREATE INDEX idx_pmn_status_tipo 
             ON {$wpdb->postmeta} (meta_key(20), meta_value(50))
             WHERE meta_key IN ('status', 'tipo', 'tipo_documento')",
            
            // Índice para datas
            "CREATE INDEX idx_pmn_data 
             ON {$wpdb->postmeta} (meta_key(20), meta_value(20))
             WHERE meta_key = 'data'",
            
            // Índice para responsáveis
            "CREATE INDEX idx_pmn_responsavel 
             ON {$wpdb->postmeta} (meta_key(20), meta_value(100))
             WHERE meta_key = 'responsavel'",
        ];
        
        foreach ($indexes as $sql) {
            // Ignora se índice já existe
            $wpdb->query($sql . " IF NOT EXISTS");
        }
        
        error_log('PMN: Índices de performance criados');
    }
    
    /**
     * Query otimizada para dashboard stats
     * Substitui múltiplas queries por uma única com subqueries
     */
    public static function get_dashboard_stats_optimized(): array
    {
        $cached = wp_cache_get('dashboard_stats', self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        
        $now = current_time('Y-m-d');
        $last_30_days = date('Y-m-d', strtotime('-30 days'));
        
        // UMA query ao invés de várias
        $sql = "
        SELECT 
            COUNT(DISTINCT p.ID) as total,
            SUM(CASE WHEN pm_status.meta_value = 'Em tramitação' THEN 1 ELSE 0 END) as tramitacao,
            SUM(CASE WHEN pm_status.meta_value = 'Concluído' THEN 1 ELSE 0 END) as concluidos,
            SUM(CASE WHEN pm_status.meta_value = 'Pendente' THEN 1 ELSE 0 END) as pendentes,
            SUM(CASE WHEN pm_status.meta_value = 'Arquivado' THEN 1 ELSE 0 END) as arquivados,
            SUM(CASE 
                WHEN pm_status.meta_value != 'Concluído' 
                AND pm_prazo.meta_value > 0 
                AND DATE_ADD(pm_data.meta_value, INTERVAL CAST(pm_prazo.meta_value AS SIGNED) DAY) < %s
                THEN 1 ELSE 0 
            END) as atrasados,
            SUM(CASE WHEN p.post_date >= %s THEN 1 ELSE 0 END) as ultimos_30_dias
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm_status ON (p.ID = pm_status.post_id AND pm_status.meta_key = 'status')
        LEFT JOIN {$wpdb->postmeta} pm_data ON (p.ID = pm_data.post_id AND pm_data.meta_key = 'data')
        LEFT JOIN {$wpdb->postmeta} pm_prazo ON (p.ID = pm_prazo.post_id AND pm_prazo.meta_key = 'prazo')
        WHERE p.post_type = 'protocolo' 
        AND p.post_status = 'publish'
        ";
        
        $results = $wpdb->get_row($wpdb->prepare($sql, $now, $last_30_days));
        
        $stats = [
            'total' => (int) $results->total,
            'tramitacao' => (int) $results->tramitacao,
            'concluidos' => (int) $results->concluidos,
            'pendentes' => (int) $results->pendentes,
            'arquivados' => (int) $results->arquivados,
            'atrasados' => (int) $results->atrasados,
            'ultimos_30_dias' => (int) $results->ultimos_30_dias,
        ];
        
        // Cache por 5 minutos
        wp_cache_set('dashboard_stats', $stats, self::CACHE_GROUP, self::CACHE_SHORT);
        
        return $stats;
    }
    
    /**
     * Query otimizada para lista com MENOS JOINs
     */
    public static function get_protocols_optimized(array $filters, int $page = 1, int $per_page = 10): array
    {
        global $wpdb;
        
        $cache_key = 'list_' . md5(serialize($filters) . $page);
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }
        
        $offset = ($page - 1) * $per_page;
        
        // Monta WHERE dinamicamente
        $where = ["p.post_type = 'protocolo'", "p.post_status = 'publish'"];
        $join = '';
        $params = [];
        
        if (!empty($filters['status'])) {
            $join .= " INNER JOIN {$wpdb->postmeta} pm_status ON (p.ID = pm_status.post_id AND pm_status.meta_key = 'status')";
            $where[] = "pm_status.meta_value = %s";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['tipo'])) {
            $join .= " INNER JOIN {$wpdb->postmeta} pm_tipo ON (p.ID = pm_tipo.post_id AND pm_tipo.meta_key = 'tipo')";
            $where[] = "pm_tipo.meta_value = %s";
            $params[] = $filters['tipo'];
        }
        
        if (!empty($filters['busca_num'])) {
            $where[] = "p.post_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($filters['busca_num']) . '%';
        }
        
        $where_sql = implode(' AND ', $where);
        
        // Query principal
        $sql = "
        SELECT p.ID, p.post_title as numero
        FROM {$wpdb->posts} p
        {$join}
        WHERE {$where_sql}
        ORDER BY p.post_date DESC
        LIMIT %d OFFSET %d
        ";
        
        $params[] = $per_page;
        $params[] = $offset;
        
        $protocols = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        
        // UMA query para buscar TODOS os metas necessários
        if ($protocols) {
            $ids = wp_list_pluck($protocols, 'ID');
            $ids_str = implode(',', array_map('intval', $ids));
            
            $meta_sql = "
            SELECT post_id, meta_key, meta_value
            FROM {$wpdb->postmeta}
            WHERE post_id IN ({$ids_str})
            AND meta_key IN ('status', 'tipo', 'tipo_documento', 'data', 'assunto', 'origem', 'destino', 'prazo', 'anexo_id', 'drive_link')
            ";
            
            $all_meta = $wpdb->get_results($meta_sql);
            
            // Organiza metas por post_id
            $meta_by_post = [];
            foreach ($all_meta as $meta) {
                $meta_by_post[$meta->post_id][$meta->meta_key] = $meta->meta_value;
            }
            
            // Adiciona metas aos protocolos
            foreach ($protocols as &$protocol) {
                $protocol->meta = $meta_by_post[$protocol->ID] ?? [];
            }
        }
        
        // Count total
        $count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$join} WHERE {$where_sql}";
        $params_count = array_slice($params, 0, -2); // Remove LIMIT e OFFSET
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params_count));
        
        $result = [
            'protocols' => $protocols,
            'total' => $total,
            'pages' => ceil($total / $per_page),
        ];
        
        // Cache por 5 minutos
        wp_cache_set($cache_key, $result, self::CACHE_GROUP, self::CACHE_SHORT);
        
        return $result;
    }
    
    /**
     * Limpa cache quando protocolo é salvo
     */
    public static function clear_cache_on_save(int $post_id): void
    {
        wp_cache_delete('dashboard_stats', self::CACHE_GROUP);
        wp_cache_flush_group(self::CACHE_GROUP);
    }
    
    /**
     * Limpa cache quando protocolo é deletado
     */
    public static function clear_cache_on_delete(int $post_id): void
    {
        if (get_post_type($post_id) === 'protocolo') {
            self::clear_cache_on_save($post_id);
        }
    }
    
    /**
     * Otimiza meta_query do WordPress (hook)
     */
    public static function optimize_meta_queries($clauses, $query)
    {
        global $wpdb;
        
        if ($query->get('post_type') !== 'protocolo') {
            return $clauses;
        }
        
        // Adiciona FORCE INDEX se tem meta_query
        if (!empty($query->get('meta_query'))) {
            $clauses['join'] = str_replace(
                "INNER JOIN {$wpdb->postmeta}",
                "INNER JOIN {$wpdb->postmeta} FORCE INDEX (meta_key)",
                $clauses['join']
            );
        }
        
        return $clauses;
    }
    
    /**
     * Preload de dados críticos do dashboard
     */
    public static function preload_dashboard_data(): void
    {
        if (!is_page() || !has_shortcode(get_post()->post_content ?? '', 'protocolo_dashboard')) {
            return;
        }
        
        // Carrega stats em background
        wp_schedule_single_event(time(), 'pmn_preload_stats');
    }
    
    /**
     * Timeline com lazy loading
     */
    public static function ajax_lazy_timeline(): void
    {
        check_ajax_referer('pmn_dashboard_nonce', 'nonce');
        
        $protocol_id = (int) ($_POST['protocol_id'] ?? 0);
        $offset = (int) ($_POST['offset'] ?? 0);
        $limit = 10;
        
        if (!$protocol_id) {
            wp_send_json_error(['message' => 'ID inválido']);
        }
        
        // Busca apenas o necessário
        $items = self::get_timeline_items($protocol_id, $offset, $limit);
        
        wp_send_json_success([
            'items' => $items,
            'has_more' => count($items) === $limit,
        ]);
    }
    
    /**
     * Busca itens da timeline paginados
     */
    private static function get_timeline_items(int $protocol_id, int $offset, int $limit): array
    {
        // Implementar lógica de timeline paginada
        // Similar ao Timeline.php mas com LIMIT/OFFSET
        
        return []; // Placeholder
    }
    
    /**
     * Relatório de performance
     */
    public static function get_performance_report(): array
    {
        global $wpdb;
        
        return [
            'cache_hits' => wp_cache_get_stats(),
            'query_count' => $wpdb->num_queries,
            'query_time' => $wpdb->timer_stop(),
            'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB',
            'memory_peak' => round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB',
        ];
    }
}
