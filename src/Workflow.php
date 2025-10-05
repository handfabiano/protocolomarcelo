<?php
namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) exit;

/**
 * Sistema de Workflow e Aprovações
 * 
 * Funcionalidades:
 * - Fluxos de aprovação multi-nível
 * - Aprovadores por tipo de documento
 * - Aprovação paralela ou sequencial
 * - Rejeição com justificativa
 * - Histórico completo
 * 
 * @version 1.0
 */
class Workflow
{
    /**
     * Status de aprovação
     */
    private const STATUS_APROVACAO = [
        'pendente'   => 'Aguardando Aprovação',
        'aprovado'   => 'Aprovado',
        'rejeitado'  => 'Rejeitado',
        'cancelado'  => 'Cancelado',
    ];

    /**
     * Tipos de fluxo
     */
    private const TIPOS_FLUXO = [
        'sequencial' => 'Sequencial (um por vez)',
        'paralelo'   => 'Paralelo (todos ao mesmo tempo)',
        'maioria'    => 'Maioria (50%+1 aprovam)',
        'unanime'    => 'Unânime (todos devem aprovar)',
    ];

    /**
     * Inicializa hooks
     */
    public static function boot(): void
    {
        // Cria tabela
        register_activation_hook(PMN_FILE, [__CLASS__, 'create_table']);
        
        // Hooks
        add_action('save_post_protocolo', [__CLASS__, 'check_workflow_trigger'], 30);
        add_action('pmn_protocolo_criado', [__CLASS__, 'on_protocolo_criado'], 10, 2);
        
        // AJAX
        add_action('wp_ajax_pmn_aprovar_protocolo', [__CLASS__, 'ajax_aprovar']);
        add_action('wp_ajax_pmn_rejeitar_protocolo', [__CLASS__, 'ajax_rejeitar']);
        add_action('wp_ajax_pmn_get_pending_approvals', [__CLASS__, 'ajax_get_pending']);
        
        // Shortcode
        add_shortcode('protocolo_minhas_aprovacoes', [__CLASS__, 'render_my_approvals']);
        
        // REST API
        add_action('rest_api_init', [__CLASS__, 'register_api_routes']);
    }

    /**
     * Cria tabelas de workflow
     */
    public static function create_table(): void
    {
        global $wpdb;
        
        // Tabela de workflows
        $table_workflows = $wpdb->prefix . 'pmn_workflows';
        $sql1 = "CREATE TABLE IF NOT EXISTS {$table_workflows} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            protocolo_id BIGINT(20) UNSIGNED NOT NULL,
            tipo_fluxo VARCHAR(20) DEFAULT 'sequencial',
            status VARCHAR(20) DEFAULT 'pendente',
            iniciado_por BIGINT(20) UNSIGNED,
            iniciado_em DATETIME NOT NULL,
            finalizado_em DATETIME DEFAULT NULL,
            observacoes TEXT,
            PRIMARY KEY (id),
            KEY protocolo_id (protocolo_id),
            KEY status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        // Tabela de aprovadores
        $table_aprovadores = $wpdb->prefix . 'pmn_workflow_aprovadores';
        $sql2 = "CREATE TABLE IF NOT EXISTS {$table_aprovadores} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            workflow_id BIGINT(20) UNSIGNED NOT NULL,
            aprovador_id BIGINT(20) UNSIGNED NOT NULL,
            ordem TINYINT(2) DEFAULT 1,
            status VARCHAR(20) DEFAULT 'pendente',
            respondido_em DATETIME DEFAULT NULL,
            observacoes TEXT,
            PRIMARY KEY (id),
            KEY workflow_id (workflow_id),
            KEY aprovador_id (aprovador_id),
            KEY status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql1);
        dbDelta($sql2);
    }

    /**
     * Verifica se protocolo precisa de aprovação
     */
    public static function check_workflow_trigger(int $post_id): void
    {
        if (wp_is_post_revision($post_id)) return;
        
        $tipo_doc = get_post_meta($post_id, 'tipo_documento', true);
        $valor = (float) get_post_meta($post_id, 'valor', true);
        
        // Regras de aprovação configuráveis
        $regras = get_option('pmn_workflow_rules', [
            'Ofício' => [
                'requer_aprovacao' => false,
            ],
            'Relatório' => [
                'requer_aprovacao' => true,
                'aprovadores' => [1], // user_ids
            ],
            'Despacho' => [
                'requer_aprovacao' => true,
                'valor_minimo' => 1000,
                'aprovadores' => [1, 2],
                'tipo_fluxo' => 'sequencial',
            ],
        ]);
        
        $regra = $regras[$tipo_doc] ?? null;
        
        if (!$regra || !$regra['requer_aprovacao']) {
            return;
        }
        
        // Verifica valor mínimo
        if (!empty($regra['valor_minimo']) && $valor < $regra['valor_minimo']) {
            return;
        }
        
        // Cria workflow
        self::create_workflow($post_id, $regra);
    }

    /**
     * Cria workflow de aprovação
     */
    public static function create_workflow(int $protocolo_id, array $config): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_workflows';
        
        $tipo_fluxo = $config['tipo_fluxo'] ?? 'sequencial';
        $aprovadores = $config['aprovadores'] ?? [];
        
        if (empty($aprovadores)) {
            return 0;
        }
        
        // Cria workflow
        $wpdb->insert(
            $table,
            [
                'protocolo_id' => $protocolo_id,
                'tipo_fluxo' => $tipo_fluxo,
                'status' => 'pendente',
                'iniciado_por' => get_current_user_id(),
                'iniciado_em' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s']
        );
        
        $workflow_id = $wpdb->insert_id;
        
        // Adiciona aprovadores
        $ordem = 1;
        $table_aprovadores = $wpdb->prefix . 'pmn_workflow_aprovadores';
        
        foreach ($aprovadores as $user_id) {
            $wpdb->insert(
                $table_aprovadores,
                [
                    'workflow_id' => $workflow_id,
                    'aprovador_id' => $user_id,
                    'ordem' => $ordem++,
                    'status' => 'pendente',
                ],
                ['%d', '%d', '%d', '%s']
            );
        }
        
        // Notifica primeiro aprovador (se sequencial)
        if ($tipo_fluxo === 'sequencial') {
            self::notificar_proximo_aprovador($workflow_id);
        } else {
            // Notifica todos (paralelo)
            foreach ($aprovadores as $user_id) {
                self::notificar_aprovador($protocolo_id, $user_id, $workflow_id);
            }
        }
        
        // Muda status do protocolo
        update_post_meta($protocolo_id, 'status', 'Aguardando Aprovação');
        update_post_meta($protocolo_id, 'workflow_id', $workflow_id);
        
        // Log
        Audit::log($protocolo_id, 'aprovacao_solicitada', [
            'description' => 'Aprovação solicitada',
            'severity' => 'info',
            'metadata' => [
                'workflow_id' => $workflow_id,
                'tipo_fluxo' => $tipo_fluxo,
                'aprovadores' => $aprovadores,
            ],
        ]);
        
        return $workflow_id;
    }

    /**
     * Aprova protocolo
     */
    public static function aprovar(int $workflow_id, int $aprovador_id, string $observacoes = ''): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_workflow_aprovadores';
        
        // Atualiza aprovador
        $updated = $wpdb->update(
            $table,
            [
                'status' => 'aprovado',
                'respondido_em' => current_time('mysql'),
                'observacoes' => $observacoes,
            ],
            [
                'workflow_id' => $workflow_id,
                'aprovador_id' => $aprovador_id,
            ],
            ['%s', '%s', '%s'],
            ['%d', '%d']
        );
        
        if (!$updated) {
            return false;
        }
        
        // Verifica se workflow está completo
        $workflow = self::get_workflow($workflow_id);
        $protocolo_id = $workflow->protocolo_id;
        
        // Log
        Audit::log($protocolo_id, 'protocolo_aprovado', [
            'description' => 'Aprovação concedida',
            'severity' => 'info',
            'metadata' => [
                'workflow_id' => $workflow_id,
                'aprovador_id' => $aprovador_id,
                'observacoes' => $observacoes,
            ],
        ]);
        
        // Verifica conclusão
        self::check_workflow_completion($workflow_id);
        
        return true;
    }

    /**
     * Rejeita protocolo
     */
    public static function rejeitar(int $workflow_id, int $aprovador_id, string $motivo): bool
    {
        global $wpdb;
        $table_aprovadores = $wpdb->prefix . 'pmn_workflow_aprovadores';
        $table_workflows = $wpdb->prefix . 'pmn_workflows';
        
        // Atualiza aprovador
        $wpdb->update(
            $table_aprovadores,
            [
                'status' => 'rejeitado',
                'respondido_em' => current_time('mysql'),
                'observacoes' => $motivo,
            ],
            [
                'workflow_id' => $workflow_id,
                'aprovador_id' => $aprovador_id,
            ]
        );
        
        // Finaliza workflow
        $wpdb->update(
            $table_workflows,
            [
                'status' => 'rejeitado',
                'finalizado_em' => current_time('mysql'),
                'observacoes' => $motivo,
            ],
            ['id' => $workflow_id]
        );
        
        // Atualiza protocolo
        $workflow = self::get_workflow($workflow_id);
        $protocolo_id = $workflow->protocolo_id;
        
        update_post_meta($protocolo_id, 'status', 'Rejeitado');
        update_post_meta($protocolo_id, 'motivo_rejeicao', $motivo);
        
        // Notifica criador
        $criador_email = get_post_meta($protocolo_id, 'responsavel_email', true);
        if ($criador_email) {
            Notifications::send($protocolo_id, [
                'tipo' => 'rejeitado',
                'titulo' => 'Protocolo Rejeitado',
                'mensagem' => "Motivo: {$motivo}",
                'prioridade' => 'alta',
                'destinatarios' => [$criador_email],
            ]);
        }
        
        // Log
        Audit::log($protocolo_id, 'protocolo_rejeitado', [
            'description' => 'Protocolo rejeitado',
            'severity' => 'warning',
            'metadata' => [
                'workflow_id' => $workflow_id,
                'aprovador_id' => $aprovador_id,
                'motivo' => $motivo,
            ],
        ]);
        
        return true;
    }

    /**
     * Verifica conclusão do workflow
     */
    private static function check_workflow_completion(int $workflow_id): void
    {
        global $wpdb;
        
        $workflow = self::get_workflow($workflow_id);
        $tipo_fluxo = $workflow->tipo_fluxo;
        
        $table = $wpdb->prefix . 'pmn_workflow_aprovadores';
        
        $sql = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'aprovado' THEN 1 ELSE 0 END) as aprovados,
            SUM(CASE WHEN status = 'rejeitado' THEN 1 ELSE 0 END) as rejeitados
        FROM {$table}
        WHERE workflow_id = %d
        ";
        
        $stats = $wpdb->get_row($wpdb->prepare($sql, $workflow_id));
        
        $concluido = false;
        $status_final = '';
        
        switch ($tipo_fluxo) {
            case 'sequencial':
            case 'unanime':
                // Todos devem aprovar
                if ($stats->aprovados == $stats->total) {
                    $concluido = true;
                    $status_final = 'aprovado';
                }
                break;
            
            case 'maioria':
                $maioria = ceil($stats->total / 2);
                if ($stats->aprovados >= $maioria) {
                    $concluido = true;
                    $status_final = 'aprovado';
                }
                break;
            
            case 'paralelo':
                // Todos devem responder
                if (($stats->aprovados + $stats->rejeitados) == $stats->total) {
                    $status_final = $stats->aprovados > $stats->rejeitados ? 'aprovado' : 'rejeitado';
                    $concluido = true;
                }
                break;
        }
        
        if ($concluido) {
            self::finalizar_workflow($workflow_id, $status_final);
        } elseif ($tipo_fluxo === 'sequencial') {
            // Notifica próximo aprovador
            self::notificar_proximo_aprovador($workflow_id);
        }
    }

    /**
     * Finaliza workflow
     */
    private static function finalizar_workflow(int $workflow_id, string $status): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_workflows';
        
        $wpdb->update(
            $table,
            [
                'status' => $status,
                'finalizado_em' => current_time('mysql'),
            ],
            ['id' => $workflow_id]
        );
        
        $workflow = self::get_workflow($workflow_id);
        $protocolo_id = $workflow->protocolo_id;
        
        // Atualiza protocolo
        $novo_status = $status === 'aprovado' ? 'Em tramitação' : 'Rejeitado';
        update_post_meta($protocolo_id, 'status', $novo_status);
        
        // Notifica criador
        $criador_email = get_post_meta($protocolo_id, 'responsavel_email', true);
        if ($criador_email) {
            Notifications::send($protocolo_id, [
                'tipo' => $status,
                'titulo' => $status === 'aprovado' ? 'Protocolo Aprovado' : 'Protocolo Rejeitado',
                'mensagem' => sprintf(
                    'O workflow de aprovação foi concluído com status: %s',
                    $status
                ),
                'prioridade' => 'alta',
                'destinatarios' => [$criador_email],
            ]);
        }
    }

    /**
     * Notifica próximo aprovador (sequencial)
     */
    private static function notificar_proximo_aprovador(int $workflow_id): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_workflow_aprovadores';
        
        // Busca próximo pendente
        $sql = "
        SELECT * FROM {$table}
        WHERE workflow_id = %d
        AND status = 'pendente'
        ORDER BY ordem ASC
        LIMIT 1
        ";
        
        $proximo = $wpdb->get_row($wpdb->prepare($sql, $workflow_id));
        
        if ($proximo) {
            $workflow = self::get_workflow($workflow_id);
            self::notificar_aprovador($workflow->protocolo_id, $proximo->aprovador_id, $workflow_id);
        }
    }

    /**
     * Notifica aprovador
     */
    private static function notificar_aprovador(int $protocolo_id, int $user_id, int $workflow_id): void
    {
        $user = get_userdata($user_id);
        if (!$user) return;
        
        Notifications::send($protocolo_id, [
            'tipo' => 'aprovacao_pendente',
            'titulo' => 'Aprovação Pendente',
            'mensagem' => sprintf(
                'Você precisa aprovar o protocolo: %s',
                get_the_title($protocolo_id)
            ),
            'prioridade' => 'alta',
            'destinatarios' => [$user->user_email],
            'dados_extra' => ['workflow_id' => $workflow_id],
        ]);
    }

    /**
     * Busca workflow
     */
    public static function get_workflow(int $workflow_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_workflows';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $workflow_id
        ));
    }

    /**
     * Busca aprovações pendentes do usuário
     */
    public static function get_pending_approvals(int $user_id): array
    {
        global $wpdb;
        $table_aprovadores = $wpdb->prefix . 'pmn_workflow_aprovadores';
        $table_workflows = $wpdb->prefix . 'pmn_workflows';
        
        $sql = "
        SELECT 
            a.*,
            w.protocolo_id,
            p.post_title as protocolo_numero
        FROM {$table_aprovadores} a
        INNER JOIN {$table_workflows} w ON a.workflow_id = w.id
        INNER JOIN {$wpdb->posts} p ON w.protocolo_id = p.ID
        WHERE a.aprovador_id = %d
        AND a.status = 'pendente'
        ORDER BY a.id DESC
        ";
        
        return $wpdb->get_results($wpdb->prepare($sql, $user_id));
    }

    /**
     * AJAX: Aprovar
     */
    public static function ajax_aprovar(): void
    {
        check_ajax_referer('pmn_workflow_nonce', 'nonce');
        
        $workflow_id = (int) ($_POST['workflow_id'] ?? 0);
        $aprovador_id = get_current_user_id();
        $observacoes = sanitize_textarea_field($_POST['observacoes'] ?? '');
        
        if (!$workflow_id || !$aprovador_id) {
            wp_send_json_error(['message' => 'Dados inválidos']);
        }
        
        $success = self::aprovar($workflow_id, $aprovador_id, $observacoes);
        
        if ($success) {
            wp_send_json_success(['message' => 'Aprovado com sucesso']);
        } else {
            wp_send_json_error(['message' => 'Erro ao aprovar']);
        }
    }

    /**
     * AJAX: Rejeitar
     */
    public static function ajax_rejeitar(): void
    {
        check_ajax_referer('pmn_workflow_nonce', 'nonce');
        
        $workflow_id = (int) ($_POST['workflow_id'] ?? 0);
        $aprovador_id = get_current_user_id();
        $motivo = sanitize_textarea_field($_POST['motivo'] ?? '');
        
        if (!$workflow_id || !$aprovador_id || !$motivo) {
            wp_send_json_error(['message' => 'Dados inválidos']);
        }
        
        $success = self::rejeitar($workflow_id, $aprovador_id, $motivo);
        
        if ($success) {
            wp_send_json_success(['message' => 'Rejeitado com sucesso']);
        } else {
            wp_send_json_error(['message' => 'Erro ao rejeitar']);
        }
    }

    /**
     * AJAX: Buscar pendentes
     */
    public static function ajax_get_pending(): void
    {
        check_ajax_referer('pmn_workflow_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        $pending = self::get_pending_approvals($user_id);
        
        wp_send_json_success(['approvals' => $pending]);
    }

    /**
     * Shortcode: Minhas aprovações
     */
    public static function render_my_approvals(): string
    {
        if (!is_user_logged_in()) {
            return '<p>Faça login para ver suas aprovações pendentes.</p>';
        }
        
        $user_id = get_current_user_id();
        $pending = self::get_pending_approvals($user_id);
        
        ob_start();
        ?>
        <div class="pmn-my-approvals">
            <h2>Minhas Aprovações Pendentes (<?php echo count($pending); ?>)</h2>
            
            <?php if (empty($pending)): ?>
                <p>Você não tem aprovações pendentes no momento.</p>
            <?php else: ?>
                <div class="pmn-approvals-list">
                    <?php foreach ($pending as $approval): ?>
                        <div class="pmn-approval-card" data-workflow="<?php echo esc_attr($approval->workflow_id); ?>">
                            <h3>
                                <a href="<?php echo esc_url(get_permalink($approval->protocolo_id)); ?>">
                                    Protocolo <?php echo esc_html($approval->protocolo_numero); ?>
                                </a>
                            </h3>
                            
                            <div class="pmn-approval-actions">
                                <button class="pmn-btn pmn-btn-approve" data-workflow="<?php echo esc_attr($approval->workflow_id); ?>">
                                    ✅ Aprovar
                                </button>
                                <button class="pmn-btn pmn-btn-reject" data-workflow="<?php echo esc_attr($approval->workflow_id); ?>">
                                    ❌ Rejeitar
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.pmn-btn-approve').on('click', function() {
                const workflowId = $(this).data('workflow');
                const observacoes = prompt('Observações (opcional):');
                
                $.post(ajaxurl, {
                    action: 'pmn_aprovar_protocolo',
                    nonce: '<?php echo wp_create_nonce('pmn_workflow_nonce'); ?>',
                    workflow_id: workflowId,
                    observacoes: observacoes || ''
                }, function(response) {
                    if (response.success) {
                        alert('Aprovado com sucesso!');
                        location.reload();
                    } else {
                        alert('Erro: ' + response.data.message);
                    }
                });
            });
            
            $('.pmn-btn-reject').on('click', function() {
                const workflowId = $(this).data('workflow');
                const motivo = prompt('Motivo da rejeição (obrigatório):');
                
                if (!motivo) {
                    alert('O motivo é obrigatório!');
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'pmn_rejeitar_protocolo',
                    nonce: '<?php echo wp_create_nonce('pmn_workflow_nonce'); ?>',
                    workflow_id: workflowId,
                    motivo: motivo
                }, function(response) {
                    if (response.success) {
                        alert('Rejeitado com sucesso!');
                        location.reload();
                    } else {
                        alert('Erro: ' + response.data.message);
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Registra rotas da API REST
     */
    public static function register_api_routes(): void
    {
        register_rest_route('pmn/v1', '/workflow/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'api_get_workflow'],
            'permission_callback' => function() {
                return current_user_can('edit_protocolos');
            },
        ]);
    }

    /**
     * API: Dados do workflow
     */
    public static function api_get_workflow(\WP_REST_Request $request): \WP_REST_Response
    {
        $workflow_id = (int) $request['id'];
        $workflow = self::get_workflow($workflow_id);
        
        if (!$workflow) {
            return new \WP_REST_Response(['error' => 'Workflow não encontrado'], 404);
        }
        
        return new \WP_REST_Response($workflow, 200);
    }
}
