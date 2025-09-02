<?php
namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) { exit; }

/**
 * Handlers – versão unificada/compatível
 * - Aceita nomes de campos/nonce antigos e novos (mn_nonce / mn_*_nonce)
 * - Mantém metadados espelhados para compatibilidade (prazo & prazo_dias, drive_link & link_drive)
 * - Movimentações salvas em mn_mov (single), mn_movs (array) e movimentacoes (array)
 * - Upload: PDF/JPG/PNG/HEIC/WEBP (10MB)
 */
class Handlers
{
    private const ALLOWED_FILE_TYPES = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'heic' => 'image/heic',
        'webp' => 'image/webp',
    ];

    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    private const DEFAULT_STATUS   = 'Em tramitação';
    private const DEFAULT_PRIORITY = 'Média';

    private const REDIRECT_PAGES = ['visualizar-protocolo', 'visualizar'];

    public static function boot(): void
    {
        // Apenas logados
        add_action('admin_post_mn_save_protocolo',    [__CLASS__, 'handle_save_protocolo']);
        add_action('admin_post_mn_save_editar',       [__CLASS__, 'handle_save_editar']);
        add_action('admin_post_mn_save_movimentacao', [__CLASS__, 'handle_save_movimentacao']);
        // Bloqueia público
        add_action('admin_post_nopriv_mn_save_protocolo',    [__CLASS__, 'deny_public']);
        add_action('admin_post_nopriv_mn_save_editar',       [__CLASS__, 'deny_public']);
        add_action('admin_post_nopriv_mn_save_movimentacao', [__CLASS__, 'deny_public']);

        // Tipos de arquivo extras
        add_filter('upload_mimes', [__CLASS__, 'add_allowed_mimes']);
        add_filter('wp_check_filetype_and_ext', [__CLASS__, 'validate_filetype'], 10, 4);
    }

    public static function deny_public(): void
    {
        status_header(403);
        wp_die(__('Acesso negado. É necessário estar logado.', 'protocolo-municipal'));
    }

    /* ------------------------------------------------------------- */
    /* CREATE                                                         */
    /* ------------------------------------------------------------- */
    public static function handle_save_protocolo(): void
    {
        try {
            self::check_maintenance();
            self::check_ratelimit();
            self::verify_nonce_multi('protocolo');
            self::require_cap('edit_posts');

            $data = self::sanitize_protocol_data($_POST);
            $data = self::apply_business_rules($data, 'create');
            self::validate_protocol_data($data, 'create');

            $post_id = self::create_protocol($data);
            self::maybe_handle_upload($post_id, 'anexo'); // campo principal do cadastro

            self::notify_stakeholders($post_id, 'create');
            self::redirect_to_view($post_id);
        } catch (\Exception $e) {
            self::handle_error($e, 'Erro ao salvar protocolo');
        }
    }

    /* ------------------------------------------------------------- */
    /* UPDATE (EDITAR)                                               */
    /* ------------------------------------------------------------- */
    public static function handle_save_editar(): void
    {
        try {
            self::check_maintenance();
            self::check_ratelimit();
            self::verify_nonce_multi('editar');
            self::require_cap('edit_posts');

            $post_id = self::get_protocol_id();
            $data    = self::sanitize_protocol_data($_POST);
            $data    = self::apply_business_rules($data, 'edit');
            self::validate_protocol_data($data, 'edit');

            self::backup_protocol($post_id);
            self::update_protocol($post_id, $data);
            self::maybe_handle_upload($post_id, 'anexo'); // opcional no editar

            self::notify_stakeholders($post_id, 'edit');
            self::redirect_to_view($post_id);
        } catch (\Exception $e) {
            self::handle_error($e, 'Erro ao editar protocolo');
        }
    }

    /* ------------------------------------------------------------- */
    /* MOVIMENTAÇÃO                                                  */
    /* ------------------------------------------------------------- */
    public static function handle_save_movimentacao(): void
    {
        try {
            self::check_maintenance();
            self::check_ratelimit();
            self::verify_nonce_multi('movimentacao');
            self::require_cap('edit_posts');

            $post_id = self::get_protocol_id();
            $mov     = self::sanitize_movement_data($_POST, $post_id);
            self::validate_movement_data($mov);

            // Upload opcional para a movimentação
            $anexo_id = self::maybe_handle_upload($post_id, 'mov_anexo'); // também aceita mov_anexo
            if (!$anexo_id) {
                $anexo_id = self::maybe_handle_upload($post_id, 'anexo_mov');
            }

            $mov['anexo_id'] = (int) $anexo_id;
            $mov['created_at'] = current_time('mysql', true);

            // 1) item individual
            add_post_meta($post_id, 'mn_mov', $mov);

            // 2) agregado mn_movs
            $agg = get_post_meta($post_id, 'mn_movs', true);
            $agg = is_array($agg) ? $agg : [];
            $agg[] = $mov;
            update_post_meta($post_id, 'mn_movs', $agg);

            // 3) agregado compat "movimentacoes"
            $agg2 = get_post_meta($post_id, 'movimentacoes', true);
            $agg2 = is_array($agg2) ? $agg2 : [];
            $agg2[] = $mov;
            update_post_meta($post_id, 'movimentacoes', $agg2);

            // Atualiza campos do protocolo conforme a movimentação
            if (!empty($mov['status']))      { update_post_meta($post_id, 'status', $mov['status']); }
            if (!empty($mov['responsavel'])) { update_post_meta($post_id, 'responsavel', $mov['responsavel']); }
            if (!empty($mov['destino']))     { update_post_meta($post_id, 'destino', $mov['destino']); }

            update_post_meta($post_id, 'mn_last_update', time());
            self::notify_stakeholders($post_id, 'movement');
            self::redirect_to_view($post_id);
        } catch (\Exception $e) {
            self::handle_error($e, 'Erro ao salvar movimentação');
        }
    }

    /* ------------------------------------------------------------- */
    /* VALIDATIONS & SANITIZATION                                    */
    /* ------------------------------------------------------------- */
    private static function verify_nonce_multi(string $kind): void
    {
        $ok = false;
        // Padrão novo (recomendado)
        if ($kind === 'protocolo')   { $ok = $ok || (isset($_POST['mn_nonce']) && check_admin_referer('mn_save_protocolo', 'mn_nonce')); }
        if ($kind === 'editar')      { $ok = $ok || (isset($_POST['mn_nonce']) && check_admin_referer('mn_save_editar', 'mn_nonce')); }
        if ($kind === 'movimentacao'){ $ok = $ok || (isset($_POST['mn_nonce']) && check_admin_referer('mn_save_movimentacao', 'mn_nonce')); }
        // Compat (código enviado pelo usuário)
        $map = [
            'protocolo'    => ['mn_protocolo_nonce',   'mn_save_protocolo_action'],
            'editar'       => ['mn_editar_nonce',      'mn_save_editar_action'],
            'movimentacao' => ['mn_movimentacao_nonce','mn_save_movimentacao_action'],
        ];
        if (!$ok && isset($map[$kind])) {
            [$field, $action] = $map[$kind];
            $nonce = isset($_POST[$field]) ? (string) $_POST[$field] : '';
            if ($nonce && wp_verify_nonce($nonce, $action)) {
                $ok = true;
            }
        }
        if (!$ok) {
            throw new \Exception('Falha de segurança (nonce).', 403);
        }
    }

    private static function require_cap(string $cap): void
    {
        if (!is_user_logged_in() || !current_user_can($cap)) {
            throw new \Exception('Permissão insuficiente.', 403);
        }
    }

    private static function get_protocol_id(): int
    {
        $id = isset($_POST['protocolo_id']) ? (int) $_POST['protocolo_id'] : 0;
        if (!$id) { $id = isset($_POST['id']) ? (int) $_POST['id'] : 0; }
        if ($id <= 0) {
            throw new \Exception('ID do protocolo é inválido.', 400);
        }
        $p = get_post($id);
        if (!$p || $p->post_type !== 'protocolo') {
            throw new \Exception('Protocolo não encontrado.', 404);
        }
        return $id;
    }

    private static function sanitize_protocol_data(array $src): array
    {
        $val = function($k, $type='text') use ($src) {
            if (!isset($src[$k])) return null;
            $v = wp_unslash($src[$k]);
            switch ($type) {
                case 'email': return sanitize_email($v);
                case 'url':   return esc_url_raw($v);
                case 'int':   return max(0, (int) sanitize_text_field($v));
                case 'textarea': return sanitize_textarea_field($v);
                default:      return sanitize_text_field($v);
            }
        };

        $data = [
            'numero'              => (string) ($val('numero') ?? ''),
            'data'                => self::normalize_date((string) ($val('data') ?? '')),
            'prioridade'          => (string) ($val('prioridade') ?? self::DEFAULT_PRIORITY),
            'tipo'                => (string) ($val('tipo') ?? ''),
            'tipo_documento'      => (string) ($val('tipo_documento') ?? ''),
            'tipo_documento_outro'=> (string) ($val('tipo_documento_outro') ?? ''),
            'origem'              => (string) ($val('origem') ?? ''),
            'destino'             => (string) ($val('destino') ?? ''),
            'assunto'             => (string) ($val('assunto') ?? ''),
            'descricao'           => (string) ($val('descricao','textarea') ?? ''),
            'prazo'               => (int)    ($val('prazo','int') ?? 0),
            'status'              => (string) ($val('status') ?? self::DEFAULT_STATUS),
            'responsavel_email'   => (string) ($val('responsavel_email','email') ?? ''),
            'drive_link'          => (string) ($val('drive_link','url') ?? ''),
            // compat com nomes alternativos
            'link_drive'          => (string) ($val('link_drive','url') ?? ''),
        ];

        if ($data['tipo_documento'] === 'Outro' && !empty($data['tipo_documento_outro'])) {
            $data['tipo_documento'] = $data['tipo_documento_outro'];
        }

        // Se só veio link_drive, espelha em drive_link e vice-versa
        if (!$data['drive_link'] && $data['link_drive']) { $data['drive_link'] = $data['link_drive']; }
        if (!$data['link_drive'] && $data['drive_link']) { $data['link_drive'] = $data['drive_link']; }

        return $data;
    }

    private static function sanitize_movement_data(array $src, int $post_id): array
    {
        $val = function($keys, $type='text') use ($src) {
            foreach ((array)$keys as $k) {
                if (isset($src[$k])) {
                    $v = wp_unslash($src[$k]);
                    switch ($type) {
                        case 'url': return esc_url_raw($v);
                        case 'textarea': return sanitize_textarea_field($v);
                        default: return sanitize_text_field($v);
                    }
                }
            }
            return '';
        };

        $data = [
            'data'        => self::normalize_datetime($val(['data_mov','data'])),
            'status'      => $val(['status_mov','status']),
            'responsavel' => $val(['responsavel_mov','responsavel']) ?: self::current_user_display(),
            'destino'     => $val(['destino_mov','destino']),
            'descricao'   => $val(['descricao_mov','descricao'],'textarea'),
            'drive_link'  => $val(['mov_drive','drive_link'],'url'),
        ];
        $data['origem'] = self::determine_origin($post_id);
        return $data;
    }

    private static function validate_protocol_data(array $d, string $op): void
    {
        $err = [];
        if ($d['numero'] === '') { $err[] = 'Número é obrigatório'; }
        if ($d['data'] === '')   { $err[] = 'Data é obrigatória'; }
        if ($d['tipo'] === '' || !in_array($d['tipo'], ['Entrada','Saída'], true)) { $err[] = 'Tipo inválido'; }
        if ($d['assunto'] === ''){ $err[] = 'Assunto é obrigatório'; }
        if ($d['responsavel_email'] && !is_email($d['responsavel_email'])) { $err[] = 'E-mail inválido'; }
        if ($d['drive_link'] && !filter_var($d['drive_link'], FILTER_VALIDATE_URL)) { $err[] = 'Link do Drive inválido'; }
        if ($op === 'create' && self::protocol_number_exists($d['numero'])) { $err[] = 'Número de protocolo já existe'; }
        // Integridade extra
        $extra = self::validate_integrity($d);
        $err = array_merge($err, $extra);
        if ($err) { throw new \Exception('Dados inválidos: ' . implode(', ', $err), 400); }
    }

    private static function validate_movement_data(array $d): void
    {
        $err = [];
        if ($d['data'] === '') { $err[] = 'Data da movimentação é obrigatória'; }
        if ($d['destino'] === '') { $err[] = 'Destino é obrigatório'; }
        if ($d['drive_link'] && !filter_var($d['drive_link'], FILTER_VALIDATE_URL)) { $err[] = 'Link do Drive inválido'; }
        if ($err) { throw new \Exception('Dados da movimentação inválidos: ' . implode(', ', $err), 400); }
    }

    /* ------------------------------------------------------------- */
    /* PERSISTENCE                                                   */
    /* ------------------------------------------------------------- */
    private static function create_protocol(array $d): int
    {
        $post_id = wp_insert_post([
            'post_type'   => 'protocolo',
            'post_status' => 'publish',
            'post_title'  => $d['numero'] ?: 'Protocolo',
        ], true);
        if (is_wp_error($post_id)) {
            throw new \Exception('Erro ao criar protocolo: ' . $post_id->get_error_message(), 500);
        }
        self::save_protocol_meta($post_id, $d);
        update_post_meta($post_id, 'mn_last_update', time());
        return $post_id;
    }

    private static function update_protocol(int $post_id, array $d): void
    {
        if (!empty($d['numero'])) {
            $r = wp_update_post(['ID' => $post_id, 'post_title' => $d['numero']], true);
            if (is_wp_error($r)) { throw new \Exception('Erro ao atualizar título: ' . $r->get_error_message(), 500); }
        }
        self::save_protocol_meta($post_id, $d);
        update_post_meta($post_id, 'mn_last_update', time());
    }

    private static function save_protocol_meta(int $post_id, array $d): void
    {
        $fields = ['data','prioridade','tipo','tipo_documento','origem','destino','assunto','descricao','status','responsavel_email','drive_link','link_drive'];
        foreach ($fields as $k) { if (array_key_exists($k, $d)) { update_post_meta($post_id, $k, $d[$k]); } }
        // compat: espelha prazo
        if (array_key_exists('prazo', $d)) {
            update_post_meta($post_id, 'prazo', (int)$d['prazo']);
            update_post_meta($post_id, 'prazo_dias', (int)$d['prazo']);
        }
    }

    /* ------------------------------------------------------------- */
    /* HELPERS                                                       */
    /* ------------------------------------------------------------- */
    private static function normalize_date(string $in): string
    {
        $in = trim($in);
        if ($in === '') return '';
        // d/m/Y
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $in, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        // Y-m-d
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $in)) {
            return $in;
        }
        // Tenta strtotime
        $t = strtotime($in);
        return $t ? date('Y-m-d', $t) : '';
    }

    private static function normalize_datetime(string $in): string
    {
        $in = trim($in);
        if ($in === '') return '';
        // d/m/Y H:i[:s]
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?$#', $in, $m)) {
            $sec = isset($m[6]) ? (int)$m[6] : 0;
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int)$m[3], (int)$m[2], (int)$m[1], (int)($m[4]??0), (int)($m[5]??0), $sec);
        }
        // Y-m-d[ H:i[:s]]
        $t = strtotime($in);
        return $t ? date('Y-m-d H:i:s', $t) : '';
    }

    private static function determine_origin(int $post_id): string
    {
        $tipo = (string) get_post_meta($post_id, 'tipo', true);
        $orig = (string) get_post_meta($post_id, 'origem', true);
        $gab  = (string) apply_filters('pmn/gabinete_label', 'Gab. Ver. Marcelo Nunes');
        if ($tipo === 'Saída') return $gab;
        return $orig ?: $gab;
    }

    private static function protocol_number_exists(string $numero): bool
    {
        if ($numero === '') return false;
        $post = get_page_by_title($numero, OBJECT, 'protocolo'); // busca por título exato
        return (bool) $post;
    }

    private static function validate_integrity(array $d): array
    {
        $err = [];
        if ($d['data']) {
            $ts = strtotime($d['data']);
            if ($ts && $ts > strtotime('+1 year')) { $err[] = 'Data não pode ultrapassar 1 ano no futuro'; }
        }
        if (isset($d['prazo']) && (int)$d['prazo'] > 365) { $err[] = 'Prazo não pode ser maior que 365 dias'; }
        $limits = [ 'numero'=>50, 'origem'=>200, 'destino'=>200, 'assunto'=>500, 'descricao'=>2000 ];
        foreach ($limits as $k=>$max) { if (!empty($d[$k]) && mb_strlen((string)$d[$k]) > $max) { $err[] = "Campo '{$k}' excede {$max} caracteres"; } }
        return $err;
    }

    private static function current_user_display(): string
    {
        $u = wp_get_current_user();
        return $u && $u->exists() ? $u->display_name : '';
    }

    private static function maybe_handle_upload(int $post_id, string $field): int
    {
        if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) { return 0; }
        if ((int)($_FILES[$field]['size'] ?? 0) > self::MAX_FILE_SIZE) {
            throw new \Exception('Arquivo muito grande. Máximo 10MB.', 400);
        }
        if (!function_exists('media_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $attachment_id = media_handle_upload($field, $post_id);
        if (is_wp_error($attachment_id)) {
            throw new \Exception('Erro no upload: ' . $attachment_id->get_error_message(), 400);
        }
        // metas padrão do anexo principal (se for esse o campo)
        if (in_array($field, ['anexo','anexo_principal'], true)) {
            update_post_meta($post_id, 'anexo_id', $attachment_id);
            update_post_meta($post_id, 'anexo_principal_id', $attachment_id);
            $url = wp_get_attachment_url($attachment_id);
            if ($url) { update_post_meta($post_id, 'anexo_principal_url', $url); }
        }
        return (int) $attachment_id;
    }

    public static function add_allowed_mimes(array $m): array
    {
        return array_merge($m, self::ALLOWED_FILE_TYPES);
    }

    public static function validate_filetype($ft, $file, $filename, $mimes)
    {
        if (!empty($ft['ext']) && !empty($ft['type'])) { return $ft; }
        $chk = wp_check_filetype($filename, self::ALLOWED_FILE_TYPES);
        if ($chk['ext'] && $chk['type']) { $ft['ext'] = $chk['ext']; $ft['type'] = $chk['type']; }
        return $ft;
    }

    private static function notify_stakeholders(int $post_id, string $op): void
    {
        $email = (string) get_post_meta($post_id, 'responsavel_email', true);
        if (!$email || !is_email($email)) { return; }
        $num  = get_the_title($post_id);
        $url  = self::get_view_url($post_id);
        $verb = ($op==='create'?'criado':($op==='edit'?'atualizado':'movimentado'));
        $subject = sprintf('Protocolo %s - %s', $num, ucfirst($verb));
        $body = "Olá,\n\nO protocolo {$num} foi {$verb}.\n\nVisualize: {$url}\n\n— Sistema de Protocolos";
        $payload = apply_filters('pmn_protocol_notification', [
            'to' => $email,
            'subject' => $subject,
            'message' => $body,
            'headers' => ['Content-Type: text/plain; charset=UTF-8'],
        ], $post_id, $op);
        if ($payload && !empty($payload['to'])) {
            wp_mail($payload['to'], $payload['subject'], $payload['message'], $payload['headers'] ?? []);
        }
    }

    private static function redirect_to_view(int $post_id): void
    {
        $url = self::get_view_url($post_id);
        $url = add_query_arg(['success'=>'1'], $url);
        wp_safe_redirect($url);
        exit;
    }

    private static function get_view_url(int $post_id): string
    {
        foreach (self::REDIRECT_PAGES as $slug) {
            $page = get_page_by_path($slug);
            if ($page) { return add_query_arg(['id'=>$post_id], get_permalink($page)); }
        }
        // Fallback para o próprio post
        $p = get_permalink($post_id);
        if ($p) return $p;
        $ref = wp_get_referer();
        return $ref ?: home_url('/');
    }

    private static function handle_error(\Exception $e, string $ctx): void
    {
        error_log(sprintf('%s - %s @ %s:%d', $ctx, $e->getMessage(), $e->getFile(), $e->getLine()));
        $code = (int) $e->getCode();
        if ($code < 100) { $code = 500; }
        $map = [
            400=>'Dados fornecidos são inválidos.',
            401=>'Você precisa estar logado.',
            403=>'Você não tem permissão.',
            404=>'Protocolo não encontrado.',
            429=>'Muitas tentativas. Tente novamente mais tarde.',
            500=>'Erro interno. Tente novamente.',
        ];
        $msg = $map[$code] ?? $map[500];
        if (defined('WP_DEBUG') && WP_DEBUG) { $msg .= ' (Debug: ' . $e->getMessage() . ')'; }
        wp_die($msg, $ctx, ['response'=>$code]);
    }

    private static function check_maintenance(): void
    {
        if (defined('PMN_MAINTENANCE_MODE') && PMN_MAINTENANCE_MODE) {
            throw new \Exception('Sistema em manutenção. Tente mais tarde.', 503);
        }
    }

    private static function check_ratelimit(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $key = 'pmn_rl_' . md5($ip);
        $att = (int) get_transient($key);
        $max = (int) apply_filters('pmn_rate_limit_max_attempts', 10);
        $win = (int) apply_filters('pmn_rate_limit_time_window', 300);
        if ($att >= $max) { throw new \Exception('Limite de tentativas excedido.', 429); }
        set_transient($key, $att+1, $win);
    }

    private static function backup_protocol(int $post_id): void
    {
        $p = get_post($post_id);
        if (!$p) return;
        $backup = [
            'post_data' => $p->to_array(),
            'meta_data' => get_post_meta($post_id),
            'timestamp' => current_time('mysql', true),
            'user_id'   => get_current_user_id(),
        ];
        set_transient('pmn_backup_' . $post_id, $backup, HOUR_IN_SECONDS);
    }
}
