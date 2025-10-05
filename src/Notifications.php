<?php
namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) exit;

/**
 * Sistema de SLA (Service Level Agreement)
 * 
 * Funcionalidades:
 * - Cálculo automático de prazos
 * - Escalação automática quando atrasa
 * - Alertas em múltiplos níveis
 * - Relatórios de performance
 * 
 * @version 1.0
 */
class SLA
{
    /**
     * Prazos padrão por tipo de documento (dias úteis)
     */
    private const PRAZOS_PADRAO = [
        'Ofício'        => 5,
        'Memorando'     => 3,
        'Requerimento'  => 10,
        'Relatório'     => 15,
        'Despacho'      => 2,
        'Outro'         => 7,
    ];

    /**
     * Níveis de alerta (% do prazo)
     */
    private const NIVEIS_ALERTA = [
        'verde'    => 0,    // 0-50% do prazo
        'amarelo'  => 50,   // 50-80% do prazo
        'laranja'  => 80,   // 80-100% do prazo
        'vermelho' => 100,  // atrasado
    ];

    /**
     * Horário comercial
     */
    private const HORA_INICIO = 8;  // 8h
    private const HORA_FIM = 18;    // 18h

    /**
     * Inicializa hooks
     */
    public static function boot(): void
    {
        // Cálculo automático de prazo ao salvar
        add_action('save_post_protocolo', [__CLASS__, 'calculate_deadline'], 20);
        
        // Cron diário para verificar prazos
        add_action('pmn_check_deadlines', [__CLASS__, 'check_all_deadlines']);
        
        // Registra cron
        if (!wp_next_scheduled('pmn_check_deadlines')) {
            wp_schedule_event(strtotime('08:00:00'), 'daily', 'pmn_check_deadlines');
        }
        
        // API endpoints
        add_action('rest_api_init', [__CLASS__, 'register_api_routes']);
    }

    /**
     * Calcula e salva prazo limite do protocolo
     */
    public static function calculate_deadline(int $post_id): void
    {
        if (wp_is_post_revision($post_id)) return;
        
        $tipo_doc = get_post_meta($post_id, 'tipo_documento', true);
        $prazo_manual = (int) get_post_meta($post_id, 'prazo', true);
        $data_abertura = get_post_meta($post_id, 'data', true);
        
        if (!$data_abertura) {
            $data_abertura = get_post_time('Y-m-d', false, $post_id);
        }
        
        // Prazo: manual > padrão do tipo > 7 dias
        $prazo_dias = $prazo_manual ?: (self::PRAZOS_PADRAO[$tipo_doc] ?? 7);
        
        // Calcula data limite (dias ÚTEIS)
        $data_limite = self::add_business_days($data_abertura, $prazo_dias);
        
        // Salva
        update_post_meta($post_id, 'data_limite', $data_limite);
        update_post_meta($post_id, 'prazo_dias_uteis', $prazo_dias);
        
        // Calcula nível de alerta
        self::update_alert_level($post_id);
    }

    /**
     * Adiciona dias úteis a uma data
     */
    private static function add_business_days(string $start_date, int $days): string
    {
        try {
            $date = new \DateTime($start_date);
            $added = 0;
            
            while ($added < $days) {
                $date->modify('+1 day');
                
                // Pula finais de semana
                if ($date->format('N') < 6) { // 1=segunda, 5=sexta
                    // Verifica se não é feriado
                    if (!self::is_holiday($date->format('Y-m-d'))) {
                        $added++;
                    }
                }
            }
            
            return $date->format('Y-m-d');
            
        } catch (\Exception $e) {
            error_log('Erro ao calcular prazo: ' . $e->getMessage());
            return date('Y-m-d', strtotime("+{$days} days", strtotime($start_date)));
        }
    }

    /**
     * Verifica se é feriado
     */
    private static function is_holiday(string $date): bool
    {
        // Busca feriados cadastrados
        $feriados = get_option('pmn_feriados', []);
        
        if (in_array($date, $feriados, true)) {
            return true;
        }
        
        // Feriados nacionais fixos
        $year = date('Y', strtotime($date));
        $feriados_fixos = [
            "{$year}-01-01", // Ano Novo
            "{$year}-04-21", // Tiradentes
            "{$year}-05-01", // Trabalho
            "{$year}-09-07", // Independência
            "{$year}-10-12", // N. Sra. Aparecida
            "{$year}-11-02", // Finados
            "{$year}-11-15", // Proclamação
            "{$year}-12-25", // Natal
        ];
        
        return in_array($date, $feriados_fixos, true);
    }

    /**
     * Atualiza nível de alerta do protocolo
     */
    public static function update_alert_level(int $post_id): void
    {
        $status = get_post_meta($post_id, 'status', true);
        
        // Se concluído, sem alerta
        if ($status === 'Concluído') {
            update_post_meta($post_id, 'nivel_alerta', 'concluido');
            return;
        }
        
        $data_limite = get_post_meta($post_id, 'data_limite', true);
        if (!$data_limite) {
            update_post_meta($post_id, 'nivel_alerta', 'sem_prazo');
            return;
        }
        
        $hoje = current_time('Y-m-d');
        $percentual = self::get_deadline_percentage($post_id);
        
        // Define nível baseado no percentual
        $nivel = 'verde';
        if ($percentual >= 100) {
            $nivel = 'vermelho';
        } elseif ($percentual >= 80) {
            $nivel = 'laranja';
        } elseif ($percentual >= 50) {
            $nivel = 'amarelo';
        }
        
        update_post_meta($post_id, 'nivel_alerta', $nivel);
        update_post_meta($post_id, 'percentual_prazo', $percentual);
        
        // Dispara notificações se necessário
        self::trigger_alerts($post_id, $nivel, $percentual);
    }

    /**
     * Calcula percentual do prazo decorrido
     */
    public static function get_deadline_percentage(int $post_id): float
    {
        $data_abertura = get_post_meta($post_id, 'data', true);
        $data_limite = get_post_meta($post_id, 'data_limite', true);
        
        if (!$data_abertura || !$data_limite) return 0;
        
        try {
            $dt_abertura = new \DateTime($data_abertura);
            $dt_limite = new \DateTime($data_limite);
            $dt_hoje = new \DateTime(current_time('Y-m-d'));
            
            $total_dias = $dt_abertura->diff($dt_limite)->days;
            $dias_decorridos = $dt_abertura->diff($dt_hoje)->days;
            
            if ($total_dias == 0) return 100;
            
            return min(100, ($dias_decorridos / $total_dias) * 100);
            
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Dispara alertas conforme nível
     */
    private static function trigger_alerts(int $post_id, string $nivel, float $percentual): void
    {
        // Verifica se já alertou hoje
        $ultimo_alerta = get_post_meta($post_id, 'ultimo_alerta_' . $nivel, true);
        if ($ultimo_alerta === current_time('Y-m-d')) {
            return; // já alertou hoje
        }
        
        // Configura alertas
        $alertas = [
            'amarelo' => [
                'titulo' => 'Protocolo próximo do prazo (50%)',
                'prioridade' => 'media',
            ],
            'laranja' => [
                'titulo' => 'Protocolo próximo do vencimento (80%)',
                'prioridade' => 'alta',
            ],
            'vermelho' => [
                'titulo' => 'PROTOCOLO ATRASADO',
                'prioridade' => 'urgente',
            ],
        ];
        
        if (!isset($alertas[$nivel])) return;
        
        $alerta = $alertas[$nivel];
        
        // Envia notificação
        Notifications::send($post_id, [
            'tipo' => 'prazo_' . $nivel,
            'titulo' => $alerta['titulo'],
            'mensagem' => sprintf(
                'Protocolo %s está em %s (%.0f%% do prazo)',
                get_the_title($post_id),
                $nivel,
                $percentual
            ),
            'prioridade' => $alerta['prioridade'],
            'destinatarios' => self::get_alert_recipients($post_id, $nivel),
        ]);
        
        // Registra que alertou
        update_post_meta($post_id, 'ultimo_alerta_' . $nivel, current_time('Y-m-d'));
        
        // Escalação automática se vermelho
        if ($nivel === 'vermelho') {
            self::auto_escalate($post_id);
        }
    }

    /**
     * Destinatários do alerta conforme nível
     */
    private static function get_alert_recipients(int $post_id, string $nivel): array
    {
        $responsavel_email = get_post_meta($post_id, 'responsavel_email', true);
        $recipients = [];
        
        if ($responsavel_email) {
            $recipients[] = $responsavel_email;
        }
        
        // Escalação: amarelo=responsável, laranja=+supervisor, vermelho=+gestor
        if (in_array($nivel, ['laranja', 'vermelho'], true)) {
            $supervisor = get_option('pmn_email_supervisor');
            if ($supervisor) $recipients[] = $supervisor;
        }
        
        if ($nivel === 'vermelho') {
            $gestor = get_option('pmn_email_gestor');
            if ($gestor) $recipients[] = $gestor;
        }
        
        return array_unique($recipients);
    }

    /**
     * Escalação automática quando atrasado
     */
    private static function auto_escalate(int $post_id): void
    {
        $dias_atraso = self::get_days_overdue($post_id);
        
        if ($dias_atraso <= 0) return;
        
        // Adiciona na meta
        update_post_meta($post_id, 'dias_atraso', $dias_atraso);
        update_post_meta($post_id, 'escalado_em', current_time('mysql'));
        
        // Muda prioridade para Alta se não for
        $prioridade = get_post_meta($post_id, 'prioridade', true);
        if ($prioridade !== 'Alta') {
            update_post_meta($post_id, 'prioridade', 'Alta');
            update_post_meta($post_id, 'prioridade_anterior', $prioridade);
        }
        
        // Log de auditoria
        Audit::log($post_id, 'escalacao_automatica', [
            'dias_atraso' => $dias_atraso,
            'motivo' => 'Prazo excedido',
        ]);
    }

    /**
     * Calcula dias de atraso
     */
    public static function get_days_overdue(int $post_id): int
    {
        $data_limite = get_post_meta($post_id, 'data_limite', true);
        if (!$data_limite) return 0;
        
        try {
            $dt_limite = new \DateTime($data_limite);
            $dt_hoje = new \DateTime(current_time('Y-m-d'));
            
            if ($dt_hoje <= $dt_limite) return 0;
            
            return $dt_hoje->diff($dt_limite)->days;
            
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Verifica todos os protocolos (cron diário)
     */
    public static function check_all_deadlines(): void
    {
        $query = new \WP_Query([
            'post_type' => 'protocolo',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'status',
                    'value' => 'Concluído',
                    'compare' => '!=',
                ],
            ],
        ]);
        
        foreach ($query->posts as $post) {
            self::update_alert_level($post->ID);
        }
        
        wp_reset_postdata();
        
        error_log(sprintf(
            'PMN SLA: Verificados %d protocolos às %s',
            $query->found_posts,
            current_time('H:i:s')
        ));
    }

    /**
     * Relatório de SLA
     */
    public static function get_sla_report(): array
    {
        global $wpdb;
        
        // Protocolos por nível de alerta
        $sql = "
        SELECT 
            pm.meta_value as nivel,
            COUNT(*) as total
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = 'nivel_alerta')
        INNER JOIN {$wpdb->postmeta} pm_status ON (p.ID = pm_status.post_id AND pm_status.meta_key = 'status')
        WHERE p.post_type = 'protocolo'
        AND p.post_status = 'publish'
        AND pm_status.meta_value != 'Concluído'
        GROUP BY pm.meta_value
        ";
        
        $por_nivel = $wpdb->get_results($sql);
        
        // Taxa de cumprimento
        $concluidos_no_prazo = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_status ON (p.ID = pm_status.post_id AND pm_status.meta_key = 'status')
            INNER JOIN {$wpdb->postmeta} pm_conclusao ON (p.ID = pm_conclusao.post_id AND pm_conclusao.meta_key = 'data_conclusao')
            INNER JOIN {$wpdb->postmeta} pm_limite ON (p.ID = pm_limite.post_id AND pm_limite.meta_key = 'data_limite')
            WHERE p.post_type = 'protocolo'
            AND pm_status.meta_value = 'Concluído'
            AND pm_conclusao.meta_value <= pm_limite.meta_value
            AND pm_conclusao.meta_value >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        $total_concluidos = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = 'status')
            WHERE p.post_type = 'protocolo'
            AND pm.meta_value = 'Concluído'
            AND p.post_modified >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        $taxa_cumprimento = $total_concluidos > 0 
            ? round(($concluidos_no_prazo / $total_concluidos) * 100, 2)
            : 0;
        
        return [
            'por_nivel' => $por_nivel,
            'taxa_cumprimento' => $taxa_cumprimento,
            'concluidos_no_prazo' => (int) $concluidos_no_prazo,
            'total_concluidos' => (int) $total_concluidos,
            'timestamp' => current_time('c'),
        ];
    }

    /**
     * Registra rotas da API REST
     */
    public static function register_api_routes(): void
    {
        register_rest_route('pmn/v1', '/sla/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'api_get_protocol_sla'],
            'permission_callback' => function() {
                return current_user_can('read');
            },
        ]);
        
        register_rest_route('pmn/v1', '/sla/report', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'api_get_sla_report'],
            'permission_callback' => function() {
                return current_user_can('edit_protocolos');
            },
        ]);
    }

    /**
     * API: Dados de SLA de um protocolo
     */
    public static function api_get_protocol_sla(\WP_REST_Request $request): \WP_REST_Response
    {
        $post_id = (int) $request['id'];
        
        if (!$post_id || get_post_type($post_id) !== 'protocolo') {
            return new \WP_REST_Response(['error' => 'Protocolo não encontrado'], 404);
        }
        
        $data = [
            'id' => $post_id,
            'numero' => get_the_title($post_id),
            'data_abertura' => get_post_meta($post_id, 'data', true),
            'data_limite' => get_post_meta($post_id, 'data_limite', true),
            'prazo_dias' => (int) get_post_meta($post_id, 'prazo_dias_uteis', true),
            'nivel_alerta' => get_post_meta($post_id, 'nivel_alerta', true),
            'percentual_prazo' => (float) get_post_meta($post_id, 'percentual_prazo', true),
            'dias_atraso' => self::get_days_overdue($post_id),
            'status' => get_post_meta($post_id, 'status', true),
        ];
        
        return new \WP_REST_Response($data, 200);
    }

    /**
     * API: Relatório de SLA
     */
    public static function api_get_sla_report(): \WP_REST_Response
    {
        $report = self::get_sla_report();
        return new \WP_REST_Response($report, 200);
    }
}
