<?php
namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exceção específica do módulo de Protocolo
 */
class ProtocolException extends \Exception {}

/**
 * Gerenciador de ações e formulários do sistema de protocolos
 *
 * Responsável por:
 * - Cadastro de protocolos
 * - Movimentação de protocolos
 * - Edição de protocolos
 * - Validação AJAX de duplicados
 *
 * @version 2.0
 * @author  Sistema Protocolo
 */
class Actions
{
    /**
     * Padrão para validação de número de protocolo
     * Aceita 3 ou 4 dígitos + barra + ano (4 dígitos). Ex.: 123/2025 ou 0123/2025
     */
    private const NUMERO_PATTERN = '/^(?:\d{3}|\d{4})\/\d{4}$/';

    /**
     * Capabilities necessárias para operações
     */
    private const REQUIRED_CAPABILITIES = [
        'protocolo',
        'edit_protocolos',
        'edit_others_protocolos',
    ];

    /**
     * Status padrão para novos protocolos
     */
    private const DEFAULT_STATUS = 'Em tramitação';

    /**
     * Prioridade padrão para novos protocolos
     */
    private const DEFAULT_PRIORITY = 'Média';

    /**
     * Campos permitidos para edição
     */
    private const EDITABLE_FIELDS = [
        'data', 'tipo', 'tipo_documento', 'origem', 'destino',
        'assunto', 'descricao', 'responsavel', 'prioridade',
        'prazo', 'status', 'link_drive',
    ];

    /**
     * Tipos MIME permitidos para upload
     */
    private const ALLOWED_MIME_TYPES = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * Inicializa os hooks necessários
     */
    public static function boot(): void
    {
        // Actions para formulários (apenas usuários logados)
        add_action('wp_ajax_mn_save_protocolo', [__CLASS__, 'handleFormSubmission']);
        add_action('wp_ajax_mn_save_movimentacao', [__CLASS__, 'handleMovimentacaoSubmission']);
        add_action('wp_ajax_mn_save_editar', [__CLASS__, 'handleEditarSubmission']);

        // AJAX para verificação de duplicados (público e logado)
        add_action('wp_ajax_mn_checa_numero', [__CLASS__, 'ajaxChecaNumero']);
        add_action('wp_ajax_nopriv_mn_checa_numero', [__CLASS__, 'ajaxChecaNumero']);

        // Filtro para permitir MIMEs adicionais
        add_filter('upload_mimes', [__CLASS__, 'addCustomMimeTypes']);
    }

    /**
     * Adiciona tipos MIME customizados
     */
    public static function addCustomMimeTypes(array $mimes): array
    {
        return array_merge($mimes, self::ALLOWED_MIME_TYPES);
    }

    /**
     * Handler para submissão do formulário de cadastro
     */
    public static function handleFormSubmission(): void
    {
        try {
            self::validateSecurity('mn_protocolo_nonce', 'mn_save_protocolo_action');
            self::validateCapabilities();

            $data = self::processProtocolFormData($_POST);
            self::validateProtocolData($data);
            self::checkDuplicateProtocol($data);

            $postId = self::createProtocol($data);
            self::handleFileUpload($postId, 'anexo');

            self::logOperation('create_protocol', $postId, $data);
            self::redirectToDetail($postId, 'ok');

        } catch (ProtocolException $e) {
            self::abortWithError($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            self::handleUnexpectedError($e, 'Erro ao salvar protocolo');
        }
    }

    /**
     * Handler para submissão de movimentação
     */
    public static function handleMovimentacaoSubmission(): void
    {
        try {
            self::validateSecurity('mn_mov_nonce', 'mn_save_movimentacao_action');
            self::validateCapabilities();

            $numero = sanitize_text_field(wp_unslash($_POST['numero'] ?? ''));
            if (empty($numero)) {
                throw new ProtocolException('Informe o número do protocolo.');
            }

            $postId       = self::findProtocolByNumber($numero);
            $movementData = self::processMovementData($_POST);

            self::updateProtocolMovement($postId, $movementData);
            self::handleFileUpload($postId, 'anexo');
            self::saveMovementHistory($postId, $movementData);

            self::logOperation('move_protocol', $postId, $movementData);
            self::redirectWithMessage('mov_ok', $postId);

        } catch (ProtocolException $e) {
            self::abortWithError($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            self::handleUnexpectedError($e, 'Erro ao movimentar protocolo');
        }
    }

    /**
     * Handler para edição de protocolo
     */
    public static function handleEditarSubmission(): void
    {
        try {
            self::validateSecurity('mn_edit_nonce', 'mn_save_editar_action');
            self::validateCapabilities();

            $postId = self::resolveProtocolId($_POST);
            $data   = self::processEditFormData($_POST);

            self::validateEditData($data, $postId);
            self::updateProtocol($postId, $data);
            self::handleFileUpload($postId, 'anexo');

            self::logOperation('edit_protocol', $postId, $data);
            self::redirectToDetail($postId, 'atualizado');

        } catch (ProtocolException $e) {
            self::abortWithError($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            self::handleUnexpectedError($e, 'Erro ao editar protocolo');
        }
    }

    /**
     * AJAX para verificação de número duplicado
     */
    public static function ajaxChecaNumero(): void
    {
        try {
            check_ajax_referer('mn_checa_numero_nonce', 'nonce');

            $numero      = sanitize_text_field($_POST['numero'] ?? '');
            $isDuplicate = !empty($numero) ? self::isProtocolNumberDuplicate($numero) : false;

            wp_send_json_success([
                'duplicado' => $isDuplicate,
                'numero'    => $numero,
                'timestamp' => current_time('timestamp'),
            ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message'    => 'Erro ao verificar número',
                'error_code' => 'AJAX_CHECK_ERROR',
            ]);
        }
    }

    /**
     * Valida segurança via nonce
     *
     * @throws ProtocolException
     */
    private static function validateSecurity(string $nonceField, string $nonceAction): void
    {
        $nonce = $_POST[$nonceField] ?? '';
        if (!$nonce || !wp_verify_nonce($nonce, $nonceAction)) {
            throw new ProtocolException('Falha na verificação de segurança', 403);
        }
    }

    /**
     * Valida capabilities do usuário
     *
     * @throws ProtocolException
     */
    private static function validateCapabilities(): void
    {
        $hasCapability = false;
        foreach (self::REQUIRED_CAPABILITIES as $cap) {
            if (current_user_can($cap)) {
                $hasCapability = true;
                break;
            }
        }
        if (!$hasCapability) {
            throw new ProtocolException('Sem permissão para esta operação', 403);
        }
    }

    /**
     * Processa dados do formulário de protocolo
     */
    private static function processProtocolFormData(array $postData): array
    {
        $data = [
            'numero'        => sanitize_text_field(wp_unslash($postData['numero'] ?? '')),
            'data'          => self::processDateField($postData['data'] ?? ''),
            'tipo'          => sanitize_text_field($postData['tipo'] ?? ''),
            'tipo_documento'=> self::processDocumentType($postData),
            'origem'        => sanitize_text_field($postData['origem'] ?? ''),
            'destino'       => sanitize_text_field($postData['destino'] ?? ''),
            'assunto'       => sanitize_text_field($postData['assunto'] ?? ''),
            'descricao'     => sanitize_textarea_field($postData['descricao'] ?? ''),
            'prioridade'    => sanitize_text_field($postData['prioridade'] ?? self::DEFAULT_PRIORITY),
            'prazo'         => max(0, intval($postData['prazo'] ?? 0)),
            'status'        => sanitize_text_field($postData['status'] ?? self::DEFAULT_STATUS),
            'drive_link'    => self::sanitizeUrl($postData['link_drive'] ?? ''),
            'responsavel'   => self::getCurrentUserDisplayName(),
        ];

        return self::applyOriginDestinationRules($data);
    }

    /**
     * Processa campo de data (retorna Y-m-d ou string vazia)
     *
     * @throws ProtocolException
     */
    private static function processDateField(string $rawDate): string
    {
        $rawDate = trim((string)$rawDate);
        if ($rawDate === '') {
            return '';
        }

        // Formato brasileiro DD/MM/AAAA
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $rawDate, $m)) {
            [$all, $d, $mth, $y] = $m;
            $d = (int)$d; $mth = (int)$mth; $y = (int)$y;
            if (checkdate($mth, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mth, $d);
            }
            throw new ProtocolException('Data inválida: formato brasileiro incorreto');
        }

        // Outros formatos aceitos pelo PHP
        $ts = strtotime($rawDate);
        if ($ts !== false) {
            return gmdate('Y-m-d', $ts);
        }

        throw new ProtocolException('Data inválida: formato não reconhecido');
    }

    /**
     * Processa tipo de documento (suporta "Outro – ...")
     */
    private static function processDocumentType(array $postData): string
    {
        $tipoDoc      = sanitize_text_field($postData['tipo_documento'] ?? '');
        $tipoDocOutro = sanitize_text_field($postData['tipo_documento_outro'] ?? '');

        if ($tipoDoc === 'Outro' && $tipoDocOutro !== '') {
            return 'Outro – ' . $tipoDocOutro;
        }
        return $tipoDoc;
    }

    /**
     * Sanitiza URL
     */
    private static function sanitizeUrl(string $url): string
    {
        $url = trim((string)$url);
        return ($url === '') ? '' : esc_url_raw(wp_unslash($url));
    }

    /**
     * Obtém nome de exibição do usuário atual
     */
    private static function getCurrentUserDisplayName(): string
    {
        $user = wp_get_current_user();
        return ($user && $user->exists()) ? (string)$user->display_name : '';
    }

    /**
     * Aplica regras de origem/destino baseadas no tipo
     *
     * @throws ProtocolException
     */
    private static function applyOriginDestinationRules(array $data): array
    {
        if ($data['tipo'] === 'Entrada') {
            if (empty($data['origem'])) {
                throw new ProtocolException('Preencha a Origem para protocolos de Entrada');
            }
            $data['destino'] = '';
        }

        if ($data['tipo'] === 'Saída') {
            if (empty($data['destino'])) {
                throw new ProtocolException('Preencha o Destino para protocolos de Saída');
            }
            $data['origem'] = '';
        }

        return $data;
    }

    /**
     * Valida dados do protocolo
     *
     * @throws ProtocolException
     */
    private static function validateProtocolData(array $data): void
    {
        if (!empty($data['numero']) && !preg_match(self::NUMERO_PATTERN, $data['numero'])) {
            throw new ProtocolException('Número inválido. Use formato 0001/2025 ou 001/2025');
        }

        foreach (['tipo', 'assunto'] as $field) {
            if (empty($data[$field])) {
                throw new ProtocolException("Campo '{$field}' é obrigatório");
            }
        }

        if (!in_array($data['tipo'], ['Entrada', 'Saída'], true)) {
            throw new ProtocolException('Tipo deve ser "Entrada" ou "Saída"');
        }

        if (!empty($data['drive_link']) && !filter_var($data['drive_link'], FILTER_VALIDATE_URL)) {
            throw new ProtocolException('Link do Drive é inválido');
        }
    }

    /**
     * Verifica duplicatas de protocolo (mesmo número + tipo=Saída + mesmo tipo_documento)
     *
     * @throws ProtocolException
     */
    private static function checkDuplicateProtocol(array $data): void
    {
        if ($data['tipo'] !== 'Saída' || empty($data['numero'])) {
            return;
        }

        $existing = self::getProtocolPostByExactTitle($data['numero']);
        if ($existing) {
            $tipo          = get_post_meta($existing->ID, 'tipo', true);
            $tipoDocumento = get_post_meta($existing->ID, 'tipo_documento', true);
            if ($tipo === 'Saída' && (string)$tipoDocumento === (string)$data['tipo_documento']) {
                throw new ProtocolException('Já existe protocolo de saída deste tipo com este número');
            }
        }
    }

    /**
     * Cria novo protocolo
     *
     * @throws ProtocolException
     */
    private static function createProtocol(array $data): int
    {
        $title  = !empty($data['numero']) ? $data['numero'] : 'Protocolo ' . current_time('YmdHis');
        $postId = wp_insert_post([
            'post_type'   => 'protocolo',
            'post_title'  => $title,
            'post_status' => 'publish',
        ], true);

        if (is_wp_error($postId) || !$postId) {
            $errorMsg = is_wp_error($postId) ? $postId->get_error_message() : 'Erro desconhecido';
            throw new ProtocolException('Erro ao criar protocolo: ' . $errorMsg);
        }

        self::saveProtocolMeta($postId, $data);
        return (int)$postId;
    }

    /**
     * Salva metadados do protocolo
     */
    private static function saveProtocolMeta(int $postId, array $data): void
    {
        $metaMap = [
            'data'           => 'data',
            'tipo'           => 'tipo',
            'tipo_documento' => 'tipo_documento',
            'origem'         => 'origem',
            'destino'        => 'destino',
            'responsavel'    => 'responsavel',
            'assunto'        => 'assunto',
            'descricao'      => 'descricao',
            'prioridade'     => 'prioridade',
            'prazo'          => 'prazo',
            'status'         => 'status',
            'drive_link'     => 'drive_link',
        ];

        foreach ($metaMap as $dataKey => $metaKey) {
            if (array_key_exists($dataKey, $data)) {
                update_post_meta($postId, $metaKey, $data[$dataKey]);
            }
        }
    }

    /**
     * Processa dados de movimentação
     */
    private static function processMovementData(array $postData): array
    {
        return [
            'status'     => sanitize_text_field($postData['status'] ?? ''),
            'origem'     => sanitize_text_field($postData['origem'] ?? ''),
            'destino'    => sanitize_text_field($postData['destino'] ?? ''),
            'observacao' => sanitize_text_field($postData['observacao'] ?? ''),
            'data_mov'   => sanitize_text_field($postData['data_mov'] ?? ''),
            'drive_link' => self::sanitizeUrl($postData['link_drive'] ?? ''),
        ];
    }

    /**
     * Localiza protocolo por número (título exato)
     *
     * @throws ProtocolException
     */
    private static function findProtocolByNumber(string $numero): int
    {
        $post = self::getProtocolPostByExactTitle($numero);
        if (!$post) {
            throw new ProtocolException('Protocolo não encontrado', 404);
        }
        return (int)$post->ID;
    }

    /**
     * Atualiza dados de movimentação do protocolo
     */
    private static function updateProtocolMovement(int $postId, array $data): void
    {
        foreach (['status', 'origem', 'destino', 'data_mov'] as $field) {
            if (!empty($data[$field])) {
                update_post_meta($postId, $field, $data[$field]);
            }
        }

        if (!empty($data['drive_link'])) {
            update_post_meta($postId, 'drive_link', $data['drive_link']);
        }
    }

    /**
     * Salva histórico de movimentação
     */
    private static function saveMovementHistory(int $postId, array $data): void
    {
        if (!empty($data['observacao'])) {
            $history = get_post_meta($postId, 'historico', true);
            $history = is_array($history) ? $history : [];

            $history[] = [
                'quando'  => current_time('mysql'),
                'texto'   => $data['observacao'],
                'status'  => $data['status'] ?? '',
                'usuario' => self::getCurrentUserDisplayName(),
            ];

            update_post_meta($postId, 'historico', $history);
        }
    }

    /**
     * Resolve ID do protocolo para edição
     *
     * @throws ProtocolException
     */
    private static function resolveProtocolId(array $postData): int
    {
        $postId = (int)($postData['post_id'] ?? 0);
        $numero = sanitize_text_field($postData['numero'] ?? '');

        if (!$postId && $numero !== '') {
            $postId = self::findProtocolByNumber($numero);
        }

        if (!$postId) {
            throw new ProtocolException('Protocolo não encontrado', 404);
        }

        $post = get_post($postId);
        if (!$post || $post->post_type !== 'protocolo') {
            throw new ProtocolException('Protocolo não encontrado', 404);
        }

        return (int)$postId;
    }

    /**
     * Processa dados do formulário de edição
     */
    private static function processEditFormData(array $postData): array
    {
        $data = [
            'numero' => sanitize_text_field(wp_unslash($postData['numero'] ?? '')),
        ];

        foreach (self::EDITABLE_FIELDS as $field) {
            if (array_key_exists($field, $postData)) {
                $value = $postData[$field];
                if ($field === 'descricao') {
                    $data[$field] = sanitize_textarea_field(wp_unslash($value));
                } elseif ($field === 'link_drive') {
                    $data[$field] = self::sanitizeUrl((string)$value);
                } elseif ($field === 'data') {
                    $data[$field] = self::processDateField((string)$value);
                } else {
                    $data[$field] = sanitize_text_field(wp_unslash($value));
                }
            }
        }

        return $data;
    }

    /**
     * Valida dados de edição
     *
     * @throws ProtocolException
     */
    private static function validateEditData(array $data, int $postId): void
    {
        if (!empty($data['numero']) && !preg_match(self::NUMERO_PATTERN, $data['numero'])) {
            throw new ProtocolException('Número inválido. Use formato 0001/2025');
        }

        if (!empty($data['link_drive']) && !filter_var($data['link_drive'], FILTER_VALIDATE_URL)) {
            throw new ProtocolException('Link do Drive é inválido');
        }

        if (!empty($data['numero'])) {
            $existing = self::getProtocolPostByExactTitle($data['numero']);
            if ($existing && (int)$existing->ID !== (int)$postId) {
                throw new ProtocolException('Já existe outro protocolo com este número');
            }
        }
    }

    /**
     * Atualiza protocolo
     *
     * @throws ProtocolException
     */
    private static function updateProtocol(int $postId, array $data): void
    {
        if (!empty($data['numero'])) {
            $update = wp_update_post([
                'ID'         => $postId,
                'post_title' => $data['numero'],
            ], true);

            if (is_wp_error($update)) {
                throw new ProtocolException('Erro ao atualizar número do protocolo');
            }
        }

        foreach (self::EDITABLE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $metaKey = ($field === 'link_drive') ? 'drive_link' : $field;
                update_post_meta($postId, $metaKey, $data[$field]);
            }
        }
    }

    /**
     * Verifica se número de protocolo é duplicado
     */
    private static function isProtocolNumberDuplicate(string $numero): bool
    {
        if ($numero === '') {
            return false;
        }
        $post = self::getProtocolPostByExactTitle($numero);
        return (bool)$post;
    }

    /**
     * Upload de arquivo anexado
     */
    private static function handleFileUpload(int $postId, string $fieldName): void
    {
        if (empty($_FILES[$fieldName]['name'])) {
            return;
        }

        if (!function_exists('media_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attachmentId = media_handle_upload($fieldName, $postId);

        if (!is_wp_error($attachmentId)) {
            update_post_meta($postId, 'anexo_id', (int)$attachmentId);
        } else {
            error_log('Erro no upload de arquivo: ' . $attachmentId->get_error_message());
        }
    }

    /**
     * Registra log da operação
     */
    private static function logOperation(string $operation, int $postId, array $data): void
    {
        $user     = wp_get_current_user();
        $username = ($user && $user->exists()) ? $user->user_login : 'guest';

        $logData = [
            'operation'        => $operation,
            'protocol_id'      => $postId,
            'protocol_number'  => $data['numero'] ?? get_the_title($postId),
            'user'             => $username,
            'user_id'          => get_current_user_id(),
            'timestamp_utc'    => current_time('mysql', true),
            'timestamp_local'  => current_time('mysql', false),
            'ip'               => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ];

        error_log('Protocol Operation: ' . wp_json_encode($logData));
    }

    /**
     * Redireciona para o detalhe do protocolo
     * - Se AJAX, retorna JSON com URL de redirecionamento
     */
    private static function redirectToDetail(int $postId, string $status = 'ok'): void
    {
        $url = add_query_arg(['mensagem' => $status], get_permalink($postId));

        if (self::isAjax()) {
            wp_send_json_success([
                'redirect' => esc_url_raw($url),
                'postId'   => $postId,
                'status'   => $status,
            ]);
        }

        wp_safe_redirect($url);
        exit;
    }

    /**
     * Redireciona com mensagem (ex.: para lista)
     * - Se AJAX, retorna JSON
     */
    private static function redirectWithMessage(string $status = 'ok', ?int $postId = null): void
    {
        $fallback = home_url('/lista-de-protocolos/');
        $referer  = wp_get_referer();
        $base     = $referer ?: $fallback;

        $url = add_query_arg(['mensagem' => $status] + ($postId ? ['id' => $postId] : []), $base);

        if (self::isAjax()) {
            wp_send_json_success([
                'redirect' => esc_url_raw($url),
                'postId'   => $postId,
                'status'   => $status,
            ]);
        }

        wp_safe_redirect($url);
        exit;
    }

    /**
     * Finaliza com erro (AJAX: JSON, não-AJAX: redireciona com erro)
     */
    private static function abortWithError(string $message, int $code = 400): void
    {
        if (self::isAjax()) {
            wp_send_json_error([
                'message' => $message,
                'code'    => $code,
            ], $code);
        }

        $fallback = wp_get_referer() ?: home_url('/');
        $url      = add_query_arg(['erro' => rawurlencode($message)], $fallback);

        wp_safe_redirect($url);
        exit;
    }

    /**
     * Trata erros inesperados, loga e responde adequadamente
     */
    private static function handleUnexpectedError(\Throwable $e, string $friendlyMsg = 'Erro inesperado'): void
    {
        error_log(sprintf(
            '[Protocolo] %s: %s in %s:%d | Trace: %s',
            $friendlyMsg,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));

        self::abortWithError($friendlyMsg, 500);
    }

    /**
     * Retorna true se a requisição atual é AJAX
     */
    private static function isAjax(): bool
    {
        return defined('DOING_AJAX') && DOING_AJAX;
    }

    /**
     * Busca um post de protocolo pelo título EXATO
     */
    private static function getProtocolPostByExactTitle(string $title)
    {
        // get_page_by_title retorna post do tipo informado quando existe título exato
        $post = get_page_by_title($title, OBJECT, 'protocolo');
        return ($post && $post instanceof \WP_Post) ? $post : null;
    }
}
