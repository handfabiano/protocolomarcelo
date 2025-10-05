<?php
namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) exit;

/**
 * Sistema de Notificações Multi-Canal
 * 
 * Canais suportados:
 * - Email
 * - Notificações in-app
 * - Webhook (Slack, Teams, etc)
 * - SMS (com integração externa)
 * 
 * @version 1.0
 */
class Notifications
{
    /**
     * Tipos de notificação
     */
    private const TIPOS = [
        'novo_protocolo'     => 'Novo Protocolo Criado',
        'protocolo_movido'   => 'Protocolo Movimentado',
        'protocolo_editado'  => 'Protocolo Editado',
        'atribuido_a_mim'    => 'Protocolo Atribuído a Você',
        'prazo_amarelo'      => 'Prazo em 50%',
        'prazo_laranja'      => 'Prazo em 80%',
        'prazo_vermelho'     => 'Protocolo Atrasado',
        'comentario'         => 'Novo Comentário',
        'aprovacao_pendente' => 'Aguardando Aprovação',
        'aprovado'           => 'Protocolo Aprovado',
        'rejeitado'          => 'Protocolo Rejeitado',
    ];

    /**
     * Prioridades
     */
    private const PRIORIDADES = [
        'baixa'   => 1,
        'media'   => 2,
        'alta'    => 3,
        'urgente' => 4,
    ];

    /**
     * Inicializa hooks
     */
    public static function boot(): void
    {
        // Cria tabela de notificações
        register_activation_hook(PMN_FILE, [__CLASS__, 'create_table']);
        
        // Hooks de eventos
        add_action('pmn_protocolo_criado', [__CLASS__, 'on_protocolo_criado'], 10, 2);
        add_action('pmn_protocolo_movimentado', [__CLASS__, 'on_protocolo_movimentado'], 10, 2);
        add_action('pmn_protocolo_editado', [__CLASS__, 'on_protocolo_editado'], 10, 2);
        
        // AJAX
        add_action('wp_ajax_pmn_get_notifications', [__CLASS__, 'ajax_get_notifications']);
        add_action('wp_ajax_pmn_mark_read', [__CLASS__, 'ajax_mark_read']);
        add_action('wp_ajax_pmn_mark_all_read', [__CLASS__, 'ajax_mark_all_read']);
        
        // Badge de notificações na admin bar
        add_action('admin_bar_menu', [__CLASS__, 'add_admin_bar_badge'], 100);
        
        // Assets
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Cria tabela de notificações
     */
    public static function create_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_notifications';
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            protocolo_id BIGINT(20) UNSIGNED NOT NULL,
            tipo VARCHAR(50) NOT NULL,
            titulo VARCHAR(255) NOT NULL,
            mensagem TEXT,
            prioridade TINYINT(1) DEFAULT 2,
            lida TINYINT(1) DEFAULT 0,
            lida_em DATETIME DEFAULT NULL,
            criada_em DATETIME NOT NULL,
            dados_extra LONGTEXT,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY protocolo_id (protocolo_id),
            KEY lida (lida),
            KEY criada_em (criada_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Envia notificação
     * 
     * @param int $protocolo_id ID do protocolo
     * @param array $args Argumentos
     * @return bool Sucesso
     */
    public static function send(int $protocolo_id, array $args): bool
    {
        $defaults = [
            'tipo' => 'geral',
            'titulo' => '',
            'mensagem' => '',
            'prioridade' => 'media',
            'destinatarios' => [], // array de user_ids ou emails
            'canais' => ['inapp', 'email'], // quais canais usar
            'dados_extra' => [],
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Resolve destinatários
        $user_ids = self::resolve_recipients($args['destinatarios']);
        
        if (empty($user_ids)) {
            return false;
        }
        
        $success = true;
        
        foreach ($user_ids as $user_id) {
            // Verifica preferências do usuário
            $preferences = self::get_user_preferences($user_id);
            
            // In-app
            if (in_array('inapp', $args['canais'], true) && $preferences['inapp']) {
                self::send_inapp($user_id, $protocolo_id, $args);
            }
            
            // Email
            if (in_array('email', $args['canais'], true) && $preferences['email']) {
                $sent = self::send_email($user_id, $protocolo_id, $args);
                if (!$sent) $success = false;
            }
            
            // Webhook
            if (in_array('webhook', $args['canais'], true) && $preferences['webhook']) {
                self::send_webhook($user_id, $protocolo_id, $args);
            }
        }
        
        return $success;
    }

    /**
     * Resolve destinatários (emails/IDs para user_ids)
     */
    private static function resolve_recipients(array $recipients): array
    {
        $user_ids = [];
        
        foreach ($recipients as $recipient) {
            if (is_numeric($recipient)) {
                // Já é user_id
                $user_ids[] = (int) $recipient;
            } elseif (is_email($recipient)) {
                // É email, busca user
                $user = get_user_by('email', $recipient);
                if ($user) {
                    $user_ids[] = $user->ID;
                }
            }
        }
        
        return array_unique($user_ids);
    }

    /**
     * Preferências de notificação do usuário
     */
    private static function get_user_preferences(int $user_id): array
    {
        $defaults = [
            'inapp' => true,
            'email' => true,
            'webhook' => false,
        ];
        
        $saved = get_user_meta($user_id, 'pmn_notification_prefs', true);
        
        return is_array($saved) ? array_merge($defaults, $saved) : $defaults;
    }

    /**
     * Envia notificação in-app
     */
    private static function send_inapp(int $user_id, int $protocolo_id, array $args): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_notifications';
        
        $result = $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'protocolo_id' => $protocolo_id,
                'tipo' => $args['tipo'],
                'titulo' => $args['titulo'],
                'mensagem' => $args['mensagem'],
                'prioridade' => self::PRIORIDADES[$args['prioridade']] ?? 2,
                'criada_em' => current_time('mysql'),
                'dados_extra' => maybe_serialize($args['dados_extra']),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s']
        );
        
        return $result !== false;
    }

    /**
     * Envia notificação por email
     */
    private static function send_email(int $user_id, int $protocolo_id, array $args): bool
    {
        $user = get_userdata($user_id);
        if (!$user || !$user->user_email) {
            return false;
        }
        
        $protocolo_numero = get_the_title($protocolo_id);
        $protocolo_url = get_permalink($protocolo_id);
        
        // Template do email
        $subject = sprintf(
            '[Protocolo %s] %s',
            $protocolo_numero,
            $args['titulo']
        );
        
        $message = self::get_email_template([
            'user_name' => $user->display_name,
            'titulo' => $args['titulo'],
            'mensagem' => $args['mensagem'],
            'protocolo_numero' => $protocolo_numero,
            'protocolo_url' => $protocolo_url,
            'prioridade' => $args['prioridade'],
        ]);
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: Sistema de Protocolos <' . get_option('admin_email') . '>',
        ];
        
        // Adiciona prioridade ao header se urgente
        if ($args['prioridade'] === 'urgente') {
            $headers[] = 'X-Priority: 1 (Highest)';
            $headers[] = 'X-MSMail-Priority: High';
            $headers[] = 'Importance: High';
        }
        
        return wp_mail($user->user_email, $subject, $message, $headers);
    }

    /**
     * Template HTML do email
     */
    private static function get_email_template(array $data): string
    {
        $cor_prioridade = [
            'baixa' => '#10b981',
            'media' => '#f59e0b',
            'alta' => '#f97316',
            'urgente' => '#ef4444',
        ];
        
        $cor = $cor_prioridade[$data['prioridade']] ?? '#6b7280';
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f3f4f6">
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:40px 20px">
                <tr>
                    <td align="center">
                        <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 6px rgba(0,0,0,0.1)">
                            <!-- Header com cor de prioridade -->
                            <tr>
                                <td style="background:<?php echo esc_attr($cor); ?>;padding:24px;text-align:center">
                                    <h1 style="margin:0;color:#fff;font-size:24px;font-weight:700">
                                        🔔 Sistema de Protocolos
                                    </h1>
                                </td>
                            </tr>
                            
                            <!-- Conteúdo -->
                            <tr>
                                <td style="padding:32px">
                                    <p style="margin:0 0 16px;color:#374151;font-size:16px">
                                        Olá, <strong><?php echo esc_html($data['user_name']); ?></strong>
                                    </p>
                                    
                                    <div style="background:#f9fafb;border-left:4px solid <?php echo esc_attr($cor); ?>;padding:16px;margin:24px 0">
                                        <h2 style="margin:0 0 12px;color:#1f2937;font-size:18px">
                                            <?php echo esc_html($data['titulo']); ?>
                                        </h2>
                                        <p style="margin:0;color:#4b5563;line-height:1.6">
                                            <?php echo nl2br(esc_html($data['mensagem'])); ?>
                                        </p>
                                    </div>
                                    
                                    <table width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0">
                                        <tr>
                                            <td style="color:#6b7280;font-size:14px;padding:8px 0">
                                                <strong>Protocolo:</strong>
                                            </td>
                                            <td style="color:#1f2937;font-size:14px;padding:8px 0;text-align:right">
                                                <?php echo esc_html($data['protocolo_numero']); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="color:#6b7280;font-size:14px;padding:8px 0">
                                                <strong>Prioridade:</strong>
                                            </td>
                                            <td style="color:<?php echo esc_attr($cor); ?>;font-size:14px;padding:8px 0;text-align:right;font-weight:700;text-transform:uppercase">
                                                <?php echo esc_html($data['prioridade']); ?>
                                            </td>
                                        </tr>
                                    </table>
                                    
                                    <div style="text-align:center;margin:32px 0">
                                        <a href="<?php echo esc_url($data['protocolo_url']); ?>" 
                                           style="display:inline-block;background:<?php echo esc_attr($cor); ?>;color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:600;font-size:16px">
                                            Ver Protocolo Completo
                                        </a>
                                    </div>
                                    
                                    <p style="margin:24px 0 0;color:#9ca3af;font-size:13px;text-align:center">
                                        Esta é uma notificação automática do Sistema de Protocolos.<br>
                                        Para alterar suas preferências, acesse as configurações da sua conta.
                                    </p>
                                </td>
                            </tr>
                            
                            <!-- Footer -->
                            <tr>
                                <td style="background:#f9fafb;padding:20px;text-align:center;border-top:1px solid #e5e7eb">
                                    <p style="margin:0;color:#6b7280;font-size:12px">
                                        © <?php echo date('Y'); ?> Sistema de Protocolos Municipal
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Envia para webhook (Slack, Teams, etc)
     */
    private static function send_webhook(int $user_id, int $protocolo_id, array $args): bool
    {
        $webhook_url = get_user_meta($user_id, 'pmn_webhook_url', true);
        
        if (!$webhook_url) {
            return false;
        }
        
        $protocolo_numero = get_the_title($protocolo_id);
        $protocolo_url = get_permalink($protocolo_id);
        
        // Formato Slack
        $payload = [
            'text' => $args['titulo'],
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => '🔔 ' . $args['titulo'],
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $args['mensagem'],
                    ],
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => '*Protocolo:*\n' . $protocolo_numero,
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => '*Prioridade:*\n' . ucfirst($args['prioridade']),
                        ],
                    ],
                ],
                [
                    'type' => 'actions',
                    'elements' => [
                        [
                            'type' => 'button',
                            'text' => [
                                'type' => 'plain_text',
                                'text' => 'Ver Protocolo',
                            ],
                            'url' => $protocolo_url,
                            'style' => 'primary',
                        ],
                    ],
                ],
            ],
        ];
        
        $response = wp_remote_post($webhook_url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
            'timeout' => 10,
        ]);
        
        return !is_wp_error($response);
    }

    /**
     * Event: Novo protocolo criado
     */
    public static function on_protocolo_criado(int $post_id, array $data): void
    {
        $responsavel_email = $data['responsavel_email'] ?? '';
        
        if (!$responsavel_email) {
            return;
        }
        
        self::send($post_id, [
            'tipo' => 'novo_protocolo',
            'titulo' => 'Novo protocolo criado',
            'mensagem' => sprintf(
                'Um novo protocolo foi criado: %s',
                get_the_title($post_id)
            ),
            'prioridade' => 'media',
            'destinatarios' => [$responsavel_email],
        ]);
    }

    /**
     * Event: Protocolo movimentado
     */
    public static function on_protocolo_movimentado(int $post_id, array $data): void
    {
        $responsavel_email = get_post_meta($post_id, 'responsavel_email', true);
        
        if (!$responsavel_email) {
            return;
        }
        
        self::send($post_id, [
            'tipo' => 'protocolo_movido',
            'titulo' => 'Protocolo movimentado',
            'mensagem' => sprintf(
                'Protocolo %s foi movimentado para: %s',
                get_the_title($post_id),
                $data['destino'] ?? 'não informado'
            ),
            'prioridade' => 'media',
            'destinatarios' => [$responsavel_email],
        ]);
    }

    /**
     * Event: Protocolo editado
     */
    public static function on_protocolo_editado(int $post_id, array $data): void
    {
        // Implementar conforme necessidade
    }

    /**
     * AJAX: Busca notificações do usuário
     */
    public static function ajax_get_notifications(): void
    {
        check_ajax_referer('pmn_notifications_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => 'Não autenticado']);
        }
        
        $limit = (int) ($_POST['limit'] ?? 20);
        $only_unread = !empty($_POST['only_unread']);
        
        $notifications = self::get_user_notifications($user_id, $limit, $only_unread);
        
        wp_send_json_success([
            'notifications' => $notifications,
            'unread_count' => self::get_unread_count($user_id),
        ]);
    }

    /**
     * Busca notificações do usuário
     */
    public static function get_user_notifications(int $user_id, int $limit = 20, bool $only_unread = false): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_notifications';
        
        $where = $wpdb->prepare("user_id = %d", $user_id);
        
        if ($only_unread) {
            $where .= " AND lida = 0";
        }
        
        $sql = "
        SELECT * FROM {$table}
        WHERE {$where}
        ORDER BY prioridade DESC, criada_em DESC
        LIMIT %d
        ";
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $limit));
        
        return array_map(function($row) {
            $row->dados_extra = maybe_unserialize($row->dados_extra);
            return $row;
        }, $results);
    }

    /**
     * Conta notificações não lidas
     */
    public static function get_unread_count(int $user_id): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_notifications';
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND lida = 0",
            $user_id
        ));
    }

    /**
     * AJAX: Marca como lida
     */
    public static function ajax_mark_read(): void
    {
        check_ajax_referer('pmn_notifications_nonce', 'nonce');
        
        $notification_id = (int) ($_POST['notification_id'] ?? 0);
        $user_id = get_current_user_id();
        
        if (!$notification_id || !$user_id) {
            wp_send_json_error();
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_notifications';
        
        $result = $wpdb->update(
            $table,
            [
                'lida' => 1,
                'lida_em' => current_time('mysql'),
            ],
            [
                'id' => $notification_id,
                'user_id' => $user_id,
            ],
            ['%d', '%s'],
            ['%d', '%d']
        );
        
        wp_send_json_success([
            'unread_count' => self::get_unread_count($user_id),
        ]);
    }

    /**
     * AJAX: Marca todas como lidas
     */
    public static function ajax_mark_all_read(): void
    {
        check_ajax_referer('pmn_notifications_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error();
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'pmn_notifications';
        
        $wpdb->update(
            $table,
            [
                'lida' => 1,
                'lida_em' => current_time('mysql'),
            ],
            ['user_id' => $user_id],
            ['%d', '%s'],
            ['%d']
        );
        
        wp_send_json_success([
            'unread_count' => 0,
        ]);
    }

    /**
     * Badge de notificações na admin bar
     */
    public static function add_admin_bar_badge(\WP_Admin_Bar $wp_admin_bar): void
    {
        if (!is_user_logged_in()) return;
        
        $user_id = get_current_user_id();
        $count = self::get_unread_count($user_id);
        
        if ($count == 0) return;
        
        $wp_admin_bar->add_node([
            'id' => 'pmn-notifications',
            'title' => sprintf(
                '<span class="pmn-notif-badge">🔔 <span class="pmn-notif-count">%d</span></span>',
                $count
            ),
            'href' => '#',
            'meta' => [
                'class' => 'pmn-notifications-trigger',
            ],
        ]);
    }

    /**
     * Enfileira assets
     */
    public static function enqueue_assets(): void
    {
        if (!is_user_logged_in()) return;
        
        wp_enqueue_style(
            'pmn-notifications',
            PMN_ASSETS_URL . 'css/notifications.css',
            [],
            PMN_VERSION
        );
        
        wp_enqueue_script(
            'pmn-notifications',
            PMN_ASSETS_URL . 'js/notifications.js',
            ['jquery'],
            PMN_VERSION,
            true
        );
        
        wp_localize_script('pmn-notifications', 'pmnNotifications', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pmn_notifications_nonce'),
            'userId' => get_current_user_id(),
        ]);
    }
}
