<?php
namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exportação de dados para CSV
 * 
 * Exporta CSV via admin-post.php?action=pmn_export_csv
 * Gera arquivo com os filtros atuais da lista.
 * 
 * Como usar na lista:
 *   $url = wp_nonce_url(
 *       admin_url('admin-post.php?action=pmn_export_csv') . '&' . http_build_query($current_args), 
 *       'pmn_export_csv', 
 *       '_wpnonce'
 *   );
 *   echo '<a class="button excel" href="'.esc_url($url).'">Exportar CSV</a>';
 * 
 * @version 2.0
 * @author Sistema Protocolo Municipal
 */
class Exports 
{
    /**
     * Campos que serão exportados no CSV
     */
    private const CSV_HEADERS = [
        'Número',
        'Data',
        'Tipo',
        'Tipo Documento',
        'Origem',
        'Destino',
        'Assunto',
        'Descrição',
        'Prioridade',
        'Status',
        'Responsável',
        'Responsável E-mail',
        'Prazo (dias)',
        'Data Limite',
        'Drive',
        'Anexo URL',
        'Criado em',
        'Atualizado em',
        'ID'
    ];

    /**
     * Separador usado no CSV (ponto e vírgula para compatibilidade com Excel brasileiro)
     */
    private const CSV_SEPARATOR = ';';

    /**
     * Número máximo de registros para exportação (segurança)
     */
    private const MAX_EXPORT_RECORDS = 10000;

    /**
     * Inicializa os hooks necessários
     * 
     * @return void
     */
    public static function boot(): void 
    {
        // Para usuários logados
        add_action('admin_post_pmn_export_csv', [__CLASS__, 'handleCsvExport']);
        
        // Para usuários não logados (descomente se necessário)
        // add_action('admin_post_nopriv_pmn_export_csv', [__CLASS__, 'handleCsvExport']);
    }

    /**
     * Manipula a requisição de exportação CSV
     * 
     * @return void
     */
    public static function handleCsvExport(): void 
    {
        try {
            // Verificações de segurança
            self::validateRequest();
            
            // Obter filtros e construir query
            $filters = self::getExportFilters();
            $protocols = self::getProtocolsForExport($filters);
            
            // Log da exportação
            self::logExport($filters, count($protocols));
            
            // Gerar e enviar CSV
            self::generateAndSendCsv($protocols);
            
        } catch (\Exception $e) {
            self::handleExportError($e);
        }
    }

    /**
     * Valida a requisição de exportação
     * 
     * @throws \Exception Se a validação falhar
     * @return void
     */
    private static function validateRequest(): void 
    {
        // Verificar nonce
        $nonce = $_GET['_wpnonce'] ?? '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'pmn_export_csv')) {
            throw new \Exception('Token de segurança inválido', 403);
        }

        // Verificar permissões (se necessário)
        if (!current_user_can('read')) {
            throw new \Exception('Permissão insuficiente para exportar dados', 403);
        }

        // Rate limiting básico (opcional)
        self::checkRateLimit();
    }

    /**
     * Verifica rate limiting básico
     * 
     * @return void
     */
    private static function checkRateLimit(): void 
    {
        $user_id = get_current_user_id();
        $transient_key = "pmn_export_rate_limit_{$user_id}";
        
        if (get_transient($transient_key)) {
            wp_die(
                'Muitas exportações recentes. Aguarde um momento antes de tentar novamente.',
                'Rate Limit',
                ['response' => 429]
            );
        }
        
        // Bloquear por 30 segundos
        set_transient($transient_key, true, 30);
    }

    /**
     * Extrai e sanitiza os filtros da requisição
     * 
     * @return array Filtros sanitizados
     */
    private static function getExportFilters(): array 
    {
        return [
            'busca_num' => self::sanitizeInput('busca_num'),
            'status' => self::sanitizeInput('status'),
            'tipo' => self::sanitizeInput('tipo'),
            'tipo_documento' => self::sanitizeInput('tipo_documento'),
            'data_ini' => self::sanitizeInput('data_ini'),
            'data_fim' => self::sanitizeInput('data_fim'),
            'responsavel' => self::sanitizeInput('responsavel'),
        ];
    }

    /**
     * Sanitiza entrada do usuário
     * 
     * @param string $key Chave do parâmetro GET
     * @return string Valor sanitizado
     */
    private static function sanitizeInput(string $key): string 
    {
        if (!isset($_GET[$key])) {
            return '';
        }

        $value = wp_unslash($_GET[$key]);
        return sanitize_text_field($value);
    }

    /**
     * Busca protocolos para exportação baseado nos filtros
     * 
     * @param array $filters Filtros aplicados
     * @return array IDs dos protocolos encontrados
     */
    private static function getProtocolsForExport(array $filters): array 
    {
        $args = [
            'post_type' => 'protocolo',
            'posts_per_page' => self::MAX_EXPORT_RECORDS,
            'post_status' => ['publish', 'draft', 'private'],
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
        ];

        // Adicionar busca por número se fornecido
        if (!empty($filters['busca_num'])) {
            $args['s'] = $filters['busca_num'];
        }

        // Construir meta_query
        $metaQuery = self::buildExportMetaQuery($filters);
        if (count($metaQuery) > 1) {
            $args['meta_query'] = $metaQuery;
        }

        $query = new \WP_Query($args);
        return $query->posts;
    }

    /**
     * Constrói meta_query para exportação
     * 
     * @param array $filters Filtros aplicados
     * @return array Meta query construída
     */
    private static function buildExportMetaQuery(array $filters): array 
    {
        $metaQuery = ['relation' => 'AND'];

        // Filtros simples
        $simpleFilters = [
            'status' => 'status',
            'tipo' => 'tipo',
            'tipo_documento' => 'tipo_documento'
        ];

        foreach ($simpleFilters as $filterKey => $metaKey) {
            if (!empty($filters[$filterKey])) {
                $metaQuery[] = [
                    'key' => $metaKey,
                    'value' => $filters[$filterKey],
                    'compare' => '='
                ];
            }
        }

        // Filtro de responsável
        if (!empty($filters['responsavel'])) {
            $metaQuery[] = [
                'relation' => 'OR',
                [
                    'key' => 'responsavel',
                    'value' => $filters['responsavel'],
                    'compare' => 'LIKE'
                ],
                [
                    'key' => 'responsavel_email',
                    'value' => $filters['responsavel'],
                    'compare' => 'LIKE'
                ]
            ];
        }

        // Filtro de data
        $dateQuery = self::buildExportDateQuery($filters['data_ini'], $filters['data_fim']);
        if (!empty($dateQuery)) {
            $metaQuery[] = $dateQuery;
        }

        return $metaQuery;
    }

    /**
     * Constrói query de data para exportação
     * 
     * @param string $dataIni Data inicial
     * @param string $dataFim Data final
     * @return array Query de data ou array vazio
     */
    private static function buildExportDateQuery(string $dataIni, string $dataFim): array 
    {
        if (empty($dataIni) && empty($dataFim)) {
            return [];
        }

        $dIni = self::convertDateToMysql($dataIni);
        $dFim = self::convertDateToMysql($dataFim);

        if (!$dIni && !$dFim) {
            return [];
        }

        $query = [
            'key' => 'data',
            'type' => 'DATE'
        ];

        if ($dIni && $dFim) {
            $query['value'] = [$dIni, $dFim];
            $query['compare'] = 'BETWEEN';
        } elseif ($dIni) {
            $query['value'] = $dIni;
            $query['compare'] = '>=';
        } else {
            $query['value'] = $dFim;
            $query['compare'] = '<=';
        }

        return $query;
    }

    /**
     * Converte data para formato MySQL
     * 
     * @param string $date Data em formato dd/mm/yyyy ou yyyy-mm-dd
     * @return string Data em formato MySQL ou string vazia
     */
    private static function convertDateToMysql(string $date): string 
    {
        $date = trim($date);
        
        if (empty($date)) {
            return '';
        }

        // Formato brasileiro dd/mm/yyyy
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches)) {
            return sprintf('%04d-%02d-%02d', $matches[3], $matches[2], $matches[1]);
        }

        // Formato ISO yyyy-mm-dd
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date)) {
            return $date;
        }

        // Tentar parser mais flexível
        try {
            $dateObj = new \DateTime($date);
            return $dateObj->format('Y-m-d');
        } catch (\Exception $e) {
            error_log("Erro ao converter data '{$date}': " . $e->getMessage());
            return '';
        }
    }

    /**
     * Gera e envia o arquivo CSV
     * 
     * @param array $protocolIds IDs dos protocolos
     * @return void
     */
    private static function generateAndSendCsv(array $protocolIds): void 
    {
        // Configurar headers para download
        self::setCsvHeaders();

        // Abrir output stream
        $output = fopen('php://output', 'w');
        if (!$output) {
            throw new \Exception('Erro ao abrir stream de output');
        }

        // Escrever BOM para UTF-8 (compatibilidade Excel)
        echo "\xEF\xBB\xBF";

        // Escrever cabeçalho
        fputcsv($output, self::CSV_HEADERS, self::CSV_SEPARATOR);

        // Escrever dados
        foreach ($protocolIds as $postId) {
            $row = self::getProtocolCsvRow($postId);
            fputcsv($output, $row, self::CSV_SEPARATOR);
        }

        fclose($output);
        exit;
    }

    /**
     * Configura headers HTTP para download CSV
     * 
     * @return void
     */
    private static function setCsvHeaders(): void 
    {
        $filename = self::generateCsvFilename();
        
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    /**
     * Gera nome do arquivo CSV
     * 
     * @return string Nome do arquivo
     */
    private static function generateCsvFilename(): string 
    {
        $timestamp = date('Ymd-His');
        $user = wp_get_current_user();
        $userSuffix = $user->exists() ? '-' . sanitize_file_name($user->user_login) : '';
        
        return "protocolos-{$timestamp}{$userSuffix}.csv";
    }

    /**
     * Extrai dados de um protocolo para linha do CSV
     * 
     * @param int $postId ID do post
     * @return array Dados do protocolo para CSV
     */
    private static function getProtocolCsvRow(int $postId): array 
    {
        // Dados básicos
        $data = self::extractProtocolData($postId);
        
        // Calcular data limite
        $dataLimite = self::calculateDataLimite($data['data'], $data['prazo']);
        
        // URL do anexo
        $anexoUrl = self::getAnexoUrl($data['anexo_id']);
        
        // Datas formatadas
        $criadoEm = get_post_time('Y-m-d H:i:s', true, $postId);
        $atualizadoEm = get_post_modified_time('Y-m-d H:i:s', true, $postId);

        return [
            $data['numero'],
            self::formatDateForCsv($data['data']),
            $data['tipo'],
            $data['tipo_documento'],
            $data['origem'],
            $data['destino'],
            $data['assunto'],
            $data['descricao'],
            $data['prioridade'],
            $data['status'],
            $data['responsavel'],
            $data['responsavel_email'],
            $data['prazo'] > 0 ? $data['prazo'] : '',
            $dataLimite,
            $data['drive_link'],
            $anexoUrl,
            $criadoEm,
            $atualizadoEm,
            $postId
        ];
    }

    /**
     * Extrai dados brutos do protocolo
     * 
     * @param int $postId ID do post
     * @return array Dados brutos do protocolo
     */
    private static function extractProtocolData(int $postId): array 
    {
        return [
            'numero' => get_the_title($postId),
            'data' => (string) get_post_meta($postId, 'data', true),
            'tipo' => (string) get_post_meta($postId, 'tipo', true),
            'tipo_documento' => (string) get_post_meta($postId, 'tipo_documento', true),
            'origem' => (string) get_post_meta($postId, 'origem', true),
            'destino' => (string) get_post_meta($postId, 'destino', true),
            'assunto' => (string) get_post_meta($postId, 'assunto', true),
            'descricao' => (string) get_post_meta($postId, 'descricao', true),
            'prioridade' => (string) get_post_meta($postId, 'prioridade', true),
            'status' => (string) get_post_meta($postId, 'status', true),
            'responsavel' => (string) get_post_meta($postId, 'responsavel', true),
            'responsavel_email' => (string) get_post_meta($postId, 'responsavel_email', true),
            'prazo' => (int) get_post_meta($postId, 'prazo', true),
            'drive_link' => (string) get_post_meta($postId, 'drive_link', true),
            'anexo_id' => (int) get_post_meta($postId, 'anexo_id', true),
        ];
    }

    /**
     * Calcula data limite baseada na data inicial e prazo
     * 
     * @param string $data Data inicial
     * @param int $prazo Prazo em dias
     * @return string Data limite formatada ou string vazia
     */
    private static function calculateDataLimite(string $data, int $prazo): string 
    {
        if (empty($data) || $prazo <= 0) {
            return '';
        }

        try {
            $dataObj = new \DateTime($data);
            $dataObj->modify("+{$prazo} days");
            return $dataObj->format('d/m/Y');
        } catch (\Exception $e) {
            error_log("Erro ao calcular data limite: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Obtém URL do anexo
     * 
     * @param int $anexoId ID do anexo
     * @return string URL do anexo ou string vazia
     */
    private static function getAnexoUrl(int $anexoId): string 
    {
        if ($anexoId <= 0) {
            return '';
        }

        $url = wp_get_attachment_url($anexoId);
        return $url ?: '';
    }

    /**
     * Formata data para CSV
     * 
     * @param string $date Data no formato Y-m-d
     * @return string Data formatada em d/m/Y ou string original
     */
    private static function formatDateForCsv(string $date): string 
    {
        if (empty($date)) {
            return '';
        }

        try {
            $dateObj = new \DateTime($date);
            return $dateObj->format('d/m/Y');
        } catch (\Exception $e) {
            return $date; // Retorna original se não conseguir formatar
        }
    }

    /**
     * Registra log da exportação
     * 
     * @param array $filters Filtros aplicados
     * @param int $recordCount Número de registros exportados
     * @return void
     */
    private static function logExport(array $filters, int $recordCount): void 
    {
        $user = wp_get_current_user();
        $userName = $user->exists() ? $user->user_login : 'anônimo';
        
        $logMessage = sprintf(
            'Exportação CSV - Usuário: %s, Registros: %d, Filtros: %s',
            $userName,
            $recordCount,
            json_encode(array_filter($filters))
        );
        
        error_log($logMessage);
        
        // Opcional: salvar em custom table ou meta para auditoria
        if (function_exists('write_log')) {
            write_log($logMessage);
        }
    }

    /**
     * Trata erros durante a exportação
     * 
     * @param \Exception $e Exceção ocorrida
     * @return void
     */
    private static function handleExportError(\Exception $e): void 
    {
        // Log do erro
        error_log('Erro na exportação CSV: ' . $e->getMessage());
        
        // Resposta apropriada baseada no tipo de erro
        $statusCode = method_exists($e, 'getCode') && $e->getCode() > 0 ? $e->getCode() : 500;
        
        if ($statusCode === 403) {
            wp_die(
                'Você não tem permissão para realizar esta operação.',
                'Acesso Negado',
                ['response' => 403]
            );
        } elseif ($statusCode === 429) {
            wp_die(
                'Muitas tentativas de exportação. Tente novamente em alguns instantes.',
                'Limite Excedido',
                ['response' => 429]
            );
        } else {
            wp_die(
                'Ocorreu um erro durante a exportação. Tente novamente ou contate o administrador.',
                'Erro na Exportação',
                ['response' => 500]
            );
        }
    }

    /**
     * Método helper para debug (apenas em desenvolvimento)
     * 
     * @param array $data Dados para debug
     * @return void
     */
    private static function debugExport(array $data): void 
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Debug Exportação: ' . print_r($data, true));
        }
    }

    /**
     * Valida se os dados do protocolo são válidos para exportação
     * 
     * @param array $protocolData Dados do protocolo
     * @return bool True se válido
     */
    private static function isValidProtocolData(array $protocolData): bool 
    {
        // Validações básicas
        if (empty($protocolData['numero'])) {
            return false;
        }

        // Adicionar outras validações conforme necessário
        return true;
    }

    /**
     * Limpa recursos e finaliza exportação
     * 
     * @return void
     */
    private static function cleanup(): void 
    {
        // Limpar buffers se necessário
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Executar garbage collection se necessário
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
}