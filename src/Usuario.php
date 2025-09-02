<?php
namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) exit;

/**
 * Formulário simples para criação de usuários (opcional).
 * Mantém compatibilidade ampla (sem "..." variadics, sem features de PHP 7.4+).
 * Shortcode: [protocolo_usuario]
 */
class Usuario
{
    public static function boot()
    {
        add_shortcode('protocolo_usuario', array(__CLASS__, 'render_form'));
        add_action('admin_post_pmn_save_usuario', array(__CLASS__, 'handle_submit'));
        add_action('admin_post_nopriv_pmn_save_usuario', array(__CLASS__, 'require_login_redirect'));
    }

    public static function require_login_redirect()
    {
        wp_safe_redirect(wp_login_url());
        exit;
    }

    public static function render_form()
    {
        if (!is_user_logged_in()) {
            return '<div class="notice notice-warning"><p>É necessário estar logado para acessar esta página.</p></div>';
        }
        if (!current_user_can('create_users') && !current_user_can('promote_users')) {
            return '<div class="notice notice-error"><p>Você não tem permissão para criar usuários.</p></div>';
        }

        $action = esc_url(admin_url('admin-post.php'));
        $nonce  = wp_nonce_field('pmn_save_usuario', '_wpnonce', true, false);

        // Papéis sugeridos (ajuste conforme seu fluxo)
        $roles = array(
            'protocolo' => 'Protocolista',
            'leitor'    => 'Leitor de Protocolos',
            'subscriber'=> 'Assinante'
        );

        ob_start();
        ?>
        <div class="wrap">
            <h2>Criar novo usuário</h2>
            <form method="post" action="<?php echo $action; ?>" class="mn-form">
                <input type="hidden" name="action" value="pmn_save_usuario" />
                <?php echo $nonce; ?>

                <p>
                    <label>Nome completo<br>
                        <input type="text" name="display_name" required />
                    </label>
                </p>

                <p>
                    <label>Usuário (login, sem espaços)<br>
                        <input type="text" name="user_login" required />
                    </label>
                </p>

                <p>
                    <label>E-mail<br>
                        <input type="email" name="user_email" required />
                    </label>
                </p>

                <p>
                    <label>Senha (opcional; em branco para gerar automaticamente)<br>
                        <input type="password" name="user_pass" autocomplete="new-password" />
                    </label>
                </p>

                <p>
                    <label>Papel<br>
                        <select name="role">
                            <?php foreach ($roles as $slug => $label): ?>
                                <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>

                <p>
                    <button type="submit" class="button button-primary">Criar usuário</button>
                </p>
            </form>

            <?php
            // Mensagens simples via query string (?mn_msg=...)
            if (isset($_GET['mn_msg'])) {
                $msg = sanitize_text_field($_GET['mn_msg']);
                if ($msg === 'ok') {
                    echo '<div class="updated"><p>Usuário criado com sucesso.</p></div>';
                } elseif ($msg === 'usuario_ja_existe') {
                    echo '<div class="notice notice-error"><p>Usuário ou e-mail já existem.</p></div>';
                } elseif ($msg === 'faltando_campos') {
                    echo '<div class="notice notice-error"><p>Preencha todos os campos obrigatórios.</p></div>';
                } elseif ($msg === 'erro') {
                    echo '<div class="notice notice-error"><p>Ocorreu um erro ao criar o usuário.</p></div>';
                }
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function handle_submit()
    {
        if (!is_user_logged_in()) {
            wp_die('É necessário estar logado.');
        }
        if (!check_admin_referer('pmn_save_usuario')) {
            wp_die('Nonce inválido.');
        }
        if (!current_user_can('create_users') && !current_user_can('promote_users')) {
            wp_die('Sem permissão.');
        }

        $user_login   = isset($_POST['user_login']) ? sanitize_user($_POST['user_login']) : '';
        $user_email   = isset($_POST['user_email']) ? sanitize_email($_POST['user_email']) : '';
        $display_name = isset($_POST['display_name']) ? sanitize_text_field($_POST['display_name']) : '';
        $role         = isset($_POST['role']) ? sanitize_text_field($_POST['role']) : 'subscriber';
        $user_pass    = isset($_POST['user_pass']) ? (string) $_POST['user_pass'] : '';

        if ($user_login === '' || $user_email === '') {
            wp_safe_redirect(add_query_arg('mn_msg', 'faltando_campos', wp_get_referer()));
            exit;
        }

        if (username_exists($user_login) || email_exists($user_email)) {
            wp_safe_redirect(add_query_arg('mn_msg', 'usuario_ja_existe', wp_get_referer()));
            exit;
        }

        if ($user_pass === '') {
            $user_pass = wp_generate_password(12, true);
        }

        $userdata = array(
            'user_login'   => $user_login,
            'user_email'   => $user_email,
            'display_name' => $display_name,
            'user_pass'    => $user_pass,
            'role'         => $role,
        );

        $user_id = wp_insert_user($userdata);

        if (is_wp_error($user_id)) {
            wp_safe_redirect(add_query_arg('mn_msg', 'erro', wp_get_referer()));
            exit;
        }

        // Notificação por e-mail (funciona no WP 5.7+ com segundo parâmetro null)
        if (function_exists('wp_new_user_notification')) {
            @wp_new_user_notification($user_id, null, 'both');
        }

        wp_safe_redirect(add_query_arg('mn_msg', 'ok', wp_get_referer()));
        exit;
    }
}
