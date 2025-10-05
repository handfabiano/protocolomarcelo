<?php
namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) exit;

/**
 * Sistema de Auditoria e Logs
 * 
 * Registra TODAS as ações no sistema para:
 * - Compliance e rastreabilidade
 * - Análise de comportamento
 * - Recuperação de dados
 * - Investigação de problemas
 * 
 * @version 1.0
 */
class Audit
{
    /**
     * Ações rastreadas
     */
    private const ACTIONS = [
        // CRUD
        'protocolo_criado'     => 'Protocolo Criado',
        'protocolo_editado'    => 'Protocolo Editado',
        'protocolo_deletado'   => 'Protocolo Deletado',
        'protocolo_restaurado' => 'Protocolo Restaurado',
        
        // Movimentação
        'protocolo_movimentado' => 'Protocolo Movimentado',
        'status_alterado'       => 'Status Alterado',
        'prioridade_alterada'   => 'Prioridade Alterada',
        
        // Anexos
        'anexo_adicionado' => 'Anexo Adicionado',
        'anexo_removido'   => 'Anexo Removido',
        'anexo_baixado'    => 'Anexo Baixado',
        
        // Aprovação
        'aprovacao_solicitada' => 'Aprovação Solicitada',
        'protocolo_aprovado'   => 'Protocolo Aprovado',
        'protocolo_rejeitado'  => 'Protocolo Rejeitado',
        
        // Delegação
        'responsavel_alterado' => 'Responsável Alterado',
        'delegacao_criada'     => 'Delegação Criada',
        
        // Sistema
        'acesso_visualizacao' => 'Protocolo Visualizado',
        'exportacao'          => 'Dados Exportados',
        'impressao'           => 'Protocolo Impresso',
        'escalacao_automatica'=> 'Escalação Automática',
        
        // Segurança
        'acesso_negado'       => 'Acesso Negado',
        'tentativa_alteracao' => 'Tentativa de Alteração Inválida',
    ];

    /**
     * Níveis de severidade
     */
    private const SEVERITY = [
        'debug'    => 1,
        'info'     => 2,
        'notice'   => 3,
        'warning'  => 4,
        'error'    => 5,
        'critical' => 6,
    ];

    /**
     * Inicializa hooks
     */
    public static function boot(): void
    {
        // Cria tabela
        register_activation_hook(PMN_FILE, [__CLASS__, 'create_table']);
        
        // Hooks automáticos
        add_action('save_post_protocolo', [__CLASS__, 'on_save_post'], 20, 3);
        add_action('before_delete_post', [__CLASS__, 'on_before_delete'], 10, 2);
        add_action('untrashed_post', [__CLASS__, 'on_untrash']);
        add_action('add_attachment', [__CLASS__, 'on_add_attachment']);
        add_action('delete_attachment', [__CLASS__, 'on_delete_attachment']);
        
        // Rastreia visualizações
        add_action('template_redirect', [__CLASS__, 'track_view']);
        
        // Rastreia downloads
        add_filter('wp_get_attachment_url', [__CLASS__, 'track_download'], 10, 2);
        
        // Limpeza automática (após 2 anos)
        add_action('pmn_cleanup_old_logs', [__CLASS__, 'cleanup_old_logs']);
        if (!wp_next_scheduled('pmn_cleanup_old_logs')) {
            wp_schedule_event(time(), 'monthly', 'pmn_cleanup_old_logs');
        }
        
        // REST API
        add_action('rest_api_init', [__CLASS__, 'register_api_routes']);
    }

    /**
     * Cria tabela de auditoria
     */
    public static function create_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_audit_log';
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            protocolo_id BIGINT(20) UNSIGNED DEFAULT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            action VARCHAR(50) NOT NULL,
            description TEXT,
            severity TINYINT(1) DEFAULT 2,
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            before_data LONGTEXT,
            after_data LONGTEXT,
            metadata LONGTEXT,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY protocolo_id (protocolo_id),
            KEY user_id (user_id),
            KEY action (action),
            KEY created_at (created_at),
            KEY severity (severity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        // Índice composto para queries comuns
        $wpdb->query("
            CREATE INDEX idx_audit_lookup 
            ON {$table} (protocolo_id, action, created_at)
        ");
    }

    /**
     * Registra log de auditoria
     * 
     * @param int|null $protocolo_id ID do protocolo
     * @param string $action Ação realizada
     * @param array $data Dados adicionais
     * @return int|false ID do log ou false
     */
    public static function log($protocolo_id, string $action, array $data = [])
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_audit_log';
        
        $user_id = get_current_user_id();
        $ip = self::get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $defaults = [
            'description' => '',
            'severity' => 'info',
            'before' => null,
            'after' => null,
            'metadata' => [],
        ];
        
        $data = wp_parse_args($data, $defaults);
        
        // Descrição automática se não fornecida
        if (empty($data['description'])) {
            $data['description'] = self::ACTIONS[$action] ?? ucwords(str_replace('_', ' ', $action));
        }
        
        $result = $wpdb->insert(
            $table,
            [
                'protocolo_id' => $protocolo_id,
                'user_id' => $user_id ?: null,
                'action' => $action,
                'description' => $data['description'],
                'severity' => self::SEVERITY[$data['severity']] ?? 2,
                'ip_address' => $ip,
                'user_agent' => $user_agent,
                'before_data' => $data['before'] ? wp_json_encode($data['before']) : null,
                'after_data' => $data['after'] ? wp_json_encode($data['after']) : null,
                'metadata' => wp_json_encode($data['metadata']),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Hook: Ao salvar protocolo
     */
    public static function on_save_post(int $post_id, \WP_Post $post, bool $update): void
    {
        // Ignora revisões e auto-save
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        if ($update) {
            // Captura diferenças
            $before = self::get_protocol_snapshot($post_id, 'before_update');
            $after = self::get_protocol_snapshot($post_id, 'after_update');
            
            $changes = self::compare_snapshots($before, $after);
            
            if (!empty($changes)) {
                self::log($post_id, 'protocolo_editado', [
                    'description' => 'Protocolo editado',
                    'severity' => 'info',
                    'before' => $before,
                    'after' => $after,
                    'metadata' => ['changes' => $changes],
                ]);
            }
        } else {
            // Novo protocolo
            self::log($post_id, 'protocolo_criado', [
                'description' => 'Novo protocolo criado',
                'severity' => 'info',
                'after' => self::get_protocol_snapshot($post_id),
            ]);
        }
    }

    /**
     * Captura snapshot do protocolo
     */
    private static function get_protocol_snapshot(int $post_id, string $context = ''): array
    {
        static $cache = [];
        
        // Cache para evitar múltiplas leituras
        $cache_key = "{$post_id}_{$context}";
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }
        
        $post = get_post($post_id);
        if (!$post) return [];
        
        $meta_keys = [
            'data', 'tipo', 'tipo_documento', 'origem', 'destino',
            'assunto', 'descricao', 'prioridade', 'prazo', 'status',
            'responsavel', 'responsavel_email', 'drive_link', 'anexo_id',
        ];
        
        $snapshot = [
            'post_title' => $post->post_title,
            'post_status' => $post->post_status,
        ];
        
        foreach ($meta_keys as $key) {
            $snapshot[$key] = get_post_meta($post_id, $key, true);
        }
        
        $cache[$cache_key] = $snapshot;
        return $snapshot;
    }

    /**
     * Compara snapshots e retorna diferenças
     */
    private static function compare_snapshots(array $before, array $after): array
    {
        $changes = [];
        
        foreach ($after as $key => $new_value) {
            $old_value = $before[$key] ?? '';
            
            if ($old_value != $new_value) {
                $changes[$key] = [
                    'old' => $old_value,
                    'new' => $new_value,
                ];
            }
        }
        
        return $changes;
    }

    /**
     * Hook: Antes de deletar
     */
    public static function on_before_delete(int $post_id, \WP_Post $post): void
    {
        if ($post->post_type !== 'protocolo') return;
        
        self::log($post_id, 'protocolo_deletado', [
            'description' => 'Protocolo deletado',
            'severity' => 'warning',
            'before' => self::get_protocol_snapshot($post_id),
        ]);
    }

    /**
     * Hook: Restaurar da lixeira
     */
    public static function on_untrash(int $post_id): void
    {
        if (get_post_type($post_id) !== 'protocolo') return;
        
        self::log($post_id, 'protocolo_restaurado', [
            'description' => 'Protocolo restaurado da lixeira',
            'severity' => 'notice',
        ]);
    }

    /**
     * Hook: Anexo adicionado
     */
    public static function on_add_attachment(int $attachment_id): void
    {
        $parent_id = wp_get_post_parent_id($attachment_id);
        
        if ($parent_id && get_post_type($parent_id) === 'protocolo') {
            $file_name = basename(get_attached_file($attachment_id));
            
            self::log($parent_id, 'anexo_adicionado', [
                'description' => "Anexo adicionado: {$file_name}",
                'severity' => 'info',
                'metadata' => [
                    'attachment_id' => $attachment_id,
                    'file_name' => $file_name,
                    'file_size' => filesize(get_attached_file($attachment_id)),
                ],
            ]);
        }
    }

    /**
     * Hook: Anexo removido
     */
    public static function on_delete_attachment(int $attachment_id): void
    {
        $parent_id = wp_get_post_parent_id($attachment_id);
        
        if ($parent_id && get_post_type($parent_id) === 'protocolo') {
            $file_name = basename(get_attached_file($attachment_id));
            
            self::log($parent_id, 'anexo_removido', [
                'description' => "Anexo removido: {$file_name}",
                'severity' => 'notice',
                'metadata' => [
                    'attachment_id' => $attachment_id,
                    'file_name' => $file_name,
                ],
            ]);
        }
    }

    /**
     * Rastreia visualizações
     */
    public static function track_view(): void
    {
        if (!is_singular('protocolo')) return;
        
        $post_id = get_queried_object_id();
        
        // Evita rastrear múltiplas views na mesma sessão
        $session_key = 'pmn_viewed_' . $post_id;
        if (!empty($_SESSION[$session_key])) {
            return;
        }
        
        $_SESSION[$session_key] = true;
        
        self::log($post_id, 'acesso_visualizacao', [
            'description' => 'Protocolo visualizado',
            'severity' => 'debug',
        ]);
        
        // Incrementa contador
        $views = (int) get_post_meta($post_id, 'visualizacoes', true);
        update_post_meta($post_id, 'visualizacoes', $views + 1);
    }

    /**
     * Rastreia downloads de anexos
     */
    public static function track_download(string $url, int $attachment_id)
    {
        // Só rastreia em contexto de protocolo
        if (is_admin() || !did_action('template_redirect')) {
            return $url;
        }
        
        $parent_id = wp_get_post_parent_id($attachment_id);
        
        if ($parent_id && get_post_type($parent_id) === 'protocolo') {
            $file_name = basename(get_attached_file($attachment_id));
            
            self::log($parent_id, 'anexo_baixado', [
                'description' => "Anexo baixado: {$file_name}",
                'severity' => 'debug',
                'metadata' => [
                    'attachment_id' => $attachment_id,
                    'file_name' => $file_name,
                ],
            ]);
        }
        
        return $url;
    }

    /**
     * Busca logs de um protocolo
     */
    public static function get_protocol_logs(int $protocolo_id, int $limit = 50): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_audit_log';
        
        $sql = $wpdb->prepare("
            SELECT 
                l.*,
                u.display_name as user_name,
                u.user_email as user_email
            FROM {$table} l
            LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID
            WHERE l.protocolo_id = %d
            ORDER BY l.created_at DESC
            LIMIT %d
        ", $protocolo_id, $limit);
        
        $results = $wpdb->get_results($sql);
        
        // Decodifica JSONs
        foreach ($results as &$row) {
            $row->before_data = $row->before_data ? json_decode($row->before_data, true) : null;
            $row->after_data = $row->after_data ? json_decode($row->after_data, true) : null;
            $row->metadata = $row->metadata ? json_decode($row->metadata, true) : [];
        }
        
        return $results;
    }

    /**
     * Busca logs por usuário
     */
    public static function get_user_logs(int $user_id, int $limit = 100): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_audit_log';
        
        $sql = $wpdb->prepare("
            SELECT 
                l.*,
                p.post_title as protocolo_numero
            FROM {$table} l
            LEFT JOIN {$wpdb->posts} p ON l.protocolo_id = p.ID
            WHERE l.user_id = %d
            ORDER BY l.created_at DESC
            LIMIT %d
        ", $user_id, $limit);
        
        return $wpdb->get_results($sql);
    }

    /**
     * Relatório de auditoria
     */
    public static function get_audit_report(array $filters = []): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_audit_log';
        
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['action'])) {
            $where[] = 'action = %s';
            $params[] = $filters['action'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= %s';
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= %s';
            $params[] = $filters['date_to'];
        }
        
        $where_sql = implode(' AND ', $where);
        
        $sql = "
        SELECT 
            action,
            COUNT(*) as total,
            COUNT(DISTINCT user_id) as usuarios_unicos,
            COUNT(DISTINCT protocolo_id) as protocolos_afetados
        FROM {$table}
        WHERE {$where_sql}
        GROUP BY action
        ORDER BY total DESC
        ";
        
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }
        
        return $wpdb->get_results($sql);
    }

    /**
     * Limpeza de logs antigos (GDPR compliance)
     */
    public static function cleanup_old_logs(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_audit_log';
        
        // Remove logs com mais de 2 anos (exceto críticos)
        $deleted = $wpdb->query("
            DELETE FROM {$table}
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 YEAR)
            AND severity < 5
        ");
        
        error_log(sprintf('PMN Audit: Removidos %d logs antigos', $deleted));
    }

    /**
     * Obtém IP do cliente
     */
    private static function get_client_ip(): string
    {
        $ip_keys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER)) {
                $ip = $_SERVER[$key];
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }

    /**
     * Registra rotas da API REST
     */
    public static function register_api_routes(): void
    {
        register_rest_route('pmn/v1', '/audit/protocol/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'api_get_protocol_logs'],
            'permission_callback' => function() {
                return current_user_can('edit_protocolos');
            },
        ]);
        
        register_rest_route('pmn/v1', '/audit/report', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'api_get_audit_report'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * API: Logs de um protocolo
     */
    public static function api_get_protocol_logs(\WP_REST_Request $request): \WP_REST_Response
    {
        $post_id = (int) $request['id'];
        $limit = (int) ($request['limit'] ?? 50);
        
        $logs = self::get_protocol_logs($post_id, $limit);
        
        return new \WP_REST_Response($logs, 200);
    }

    /**
     * API: Relatório de auditoria
     */
    public static function api_get_audit_report(\WP_REST_Request $request): \WP_REST_Response
    {
        $filters = [
            'user_id' => $request['user_id'] ?? null,
            'action' => $request['action'] ?? null,
            'date_from' => $request['date_from'] ?? null,
            'date_to' => $request['date_to'] ?? null,
        ];
        
        $report = self::get_audit_report($filters);
        
        return new \WP_REST_Response($report, 200);
    }
}
