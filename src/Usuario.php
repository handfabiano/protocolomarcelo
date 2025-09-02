<?php

namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) exit;


if (!defined('ABSPATH')) exit;
class Usuario {
  public static function render_cadastro_usuario_form() {
    $core = Core::instance();
    ...
  }
}


    $msg = '';
    if (isset($_GET['user_created'])) {
        $msg = '<div style="color:green;">Usuário cadastrado com sucesso! Você já pode fazer login.</div>';
    } elseif (isset($_GET['cadastro_erro'])) {
        $erro = sanitize_text_field($_GET['cadastro_erro']);
        $msg = '<div style="color:#b00;">Erro: ' . esc_html($erro) . '</div>';
    }

    ob_start();
    echo Core::instance()->css_responsivo();
    echo $msg;
    ?>
   <div class="mn-card" style="max-width:440px;margin:30px auto 0;">
  <form method="post" class="mn-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="cadastro_usuario_front">
    <?php wp_nonce_field('cadastro_usuario_front_action', 'cadastro_usuario_nonce'); ?>

    <div class="mn-form-group">
        <label for="user_login">Nome de Usuário</label>
        <input type="text" id="user_login" name="user_login" required>
    </div>
    <div class="mn-form-group">
        <label for="user_email">E-mail</label>
        <input type="email" id="user_email" name="user_email" required>
    </div>
    <div class="mn-form-group">
        <label for="user_pass">Senha</label>
        <input type="password" id="user_pass" name="user_pass" required>
    </div>
    <div class="mn-form-group">
        <label for="user_pass2">Confirmar Senha</label>
        <input type="password" id="user_pass2" name="user_pass2" required>
    </div>
    <div class="mn-form-group">
        <label for="first_name">Primeiro Nome</label>
        <input type="text" id="first_name" name="first_name">
    </div>
    <div class="mn-form-group">
        <label for="last_name">Sobrenome</label>
        <input type="text" id="last_name" name="last_name">
    </div>
    <div class="mn-form-group">
        <label for="display_name">Nome de Exibição</label>
        <input type="text" id="display_name" name="display_name">
    </div>
    <div class="mn-form-group">
        <label for="role">Perfil</label>
        <select name="role" id="role" required>
            <option value="subscriber">Leitor</option>
            <option value="protocolo">Protocolo</option>
            <option value="editor">Editor</option>
            <option value="administrator">Administrador</option>
        </select>
    </div>
    <div class="mn-form-group" style="margin-top:16px;">
        <button type="submit" class="mn-btn-main">Cadastrar</button>
    </div>
  </form>
</div>

    <style>
    .mn-btn-main {
        background: #0854ba !important;
        color: #fff !important;
        border: none !important;
        font-weight: bold !important;
        border-radius: 8px !important;
        font-size: 1.1em !important;
        padding: 14px 0 !important;
        width: 100% !important;
        margin-top: 8px;
        box-shadow: 0 2px 8px #0854ba26;
        transition: background .22s;
        letter-spacing: 0.02em;
    }
    .mn-btn-main:hover { background: #003b87 !important; }
    .mn-form-group { margin-bottom: 16px !important; }
    .mn-form-group label { font-weight:600;margin-bottom:5px;display:block;}
    @media (max-width: 600px) {
      .mn-form { padding: 14px 6px 10px 6px; }
      .mn-form-group { margin-bottom: 12px !important;}
      .mn-btn-main { font-size: 1em !important; padding: 13px 0 !important;}
    }
    </style>
    <?php
    return ob_get_clean();

  }
  public static function handle_cadastro_usuario_front()){
    $core = Core::instance();

    if (!isset($_POST['cadastro_usuario_nonce']) || !wp_verify_nonce($_POST['cadastro_usuario_nonce'], 'cadastro_usuario_front_action')) {
        wp_redirect(add_query_arg('cadastro_erro', 'Acesso inválido', wp_get_referer()));
        exit;
    }

    $user_login = sanitize_user($_POST['user_login']);
    $user_email = sanitize_email($_POST['user_email']);
    $user_pass = $_POST['user_pass'];
    $user_pass2 = $_POST['user_pass2'];
    $first_name = sanitize_text_field($_POST['first_name']);
    $last_name = sanitize_text_field($_POST['last_name']);
    $display_name = sanitize_text_field($_POST['display_name']);
    $role = sanitize_text_field($_POST['role']);

    // Validações
    if ($user_pass !== $user_pass2) {
        wp_redirect(add_query_arg('cadastro_erro', 'As senhas não coincidem', wp_get_referer()));
        exit;
    }
    if (username_exists($user_login)) {
        wp_redirect(add_query_arg('cadastro_erro', 'Nome de usuário já cadastrado', wp_get_referer()));
        exit;
    }
    if (email_exists($user_email)) {
        wp_redirect(add_query_arg('cadastro_erro', 'E-mail já cadastrado', wp_get_referer()));
        exit;
    }

    $user_id = wp_create_user($user_login, $user_pass, $user_email);
    if (is_wp_error($user_id)) {
        wp_redirect(add_query_arg('cadastro_erro', 'Erro ao criar usuário', wp_get_referer()));
        exit;
    }

    // Atualiza campos do WordPress
    wp_update_user([
        'ID' => $user_id,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'display_name' => $display_name ?: ($first_name . ' ' . $last_name),
    ]);
    $user = new WP_User($user_id);
    $user->set_role($role);

    wp_redirect(add_query_arg('user_created', '1', wp_get_referer()));
    exit;

  }
}

