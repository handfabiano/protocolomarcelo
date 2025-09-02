<?php
namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Relatório com filtros + impressão limpa + link CSV
 * Shortcode: [protocolo_relatorio]
 * 
 * @version 2.0
 * @author Sistema Protocolo Municipal
 */
class Report 
{
    /**
     * Status disponíveis para protocolo
     */
    private const STATUS_OPTIONS = [
        'Em tramitação',
        'Concluído',
        'Arquivado',
        'Pendente'
    ];

    /**
     * Tipos disponíveis para protocolo
     */
    private const TIPO_OPTIONS = [
        'Entrada',
        'Saída'
    ];

    /**
     * Tipos de documento disponíveis
     */
    private const TIPO_DOCUMENTO_OPTIONS = [
        'Ofício',
        'Memorando',
        'Requerimento',
        'Relatório',
        'Despacho',
        'Outro'
    ];

    /**
     * Renderiza o relatório completo
     * 
     * @return string HTML do relatório
     */
    public static function render(): string 
    {
        $filters = self::getFilters();
        $query = self::buildQuery($filters);
        $csvLink = self::generateCsvLink($filters);
        
        return self::renderView($query, $filters, $csvLink);
    }

    /**
     * Extrai e sanitiza os filtros da requisição
     * 
     * @return array Filtros sanitizados
     */
    private static function getFilters(): array 
    {
        return [
            'busca_num' => self::sanitizeInput('busca_num'),
            'status' => self::sanitizeInput('status'),
            'tipo' => self::sanitizeInput('tipo'),
            'tipo_documento' => self::sanitizeInput('tipo_documento'),
            'data_ini' => self::sanitizeInput('data_ini'),
            'data_fim' => self::sanitizeInput('data_fim'),
            'responsavel' => self::sanitizeInput('responsavel')
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
     * Constrói a query WP_Query com base nos filtros
     * 
     * @param array $filters Filtros aplicados
     * @return \WP_Query Query construída
     */
    private static function buildQuery(array $filters): \WP_Query 
    {
        $args = [
            'post_type' => 'protocolo',
            'posts_per_page' => 500,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        // Adiciona busca por número se fornecido
        if (!empty($filters['busca_num'])) {
            $args['s'] = $filters['busca_num'];
        }

        // Constrói meta_query
        $metaQuery = self::buildMetaQuery($filters);
        if (count($metaQuery) > 1) {
            $args['meta_query'] = $metaQuery;
        }

        return new \WP_Query($args);
    }

    /**
     * Constrói meta_query para os filtros
     * 
     * @param array $filters Filtros aplicados
     * @return array Meta query construída
     */
    private static function buildMetaQuery(array $filters): array 
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

        // Filtro de responsável (busca em nome e email)
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
        $dateQuery = self::buildDateQuery($filters['data_ini'], $filters['data_fim']);
        if (!empty($dateQuery)) {
            $metaQuery[] = $dateQuery;
        }

        return $metaQuery;
    }

    /**
     * Constrói query de data
     * 
     * @param string $dataIni Data inicial
     * @param string $dataFim Data final
     * @return array Query de data ou array vazio
     */
    private static function buildDateQuery(string $dataIni, string $dataFim): array 
    {
        if (empty($dataIni) && empty($dataFim)) {
            return [];
        }

        try {
            $dtIni = $dataIni ? new \DateTimeImmutable($dataIni) : null;
            $dtFim = $dataFim ? new \DateTimeImmutable($dataFim) : null;

            $query = [
                'key' => 'data',
                'type' => 'DATE'
            ];

            if ($dtIni && $dtFim) {
                $query['value'] = [
                    $dtIni->format('Y-m-d'),
                    $dtFim->format('Y-m-d')
                ];
                $query['compare'] = 'BETWEEN';
            } elseif ($dtIni) {
                $query['value'] = $dtIni->format('Y-m-d');
                $query['compare'] = '>=';
            } else {
                $query['value'] = $dtFim->format('Y-m-d');
                $query['compare'] = '<=';
            }

            return $query;

        } catch (\Exception $e) {
            error_log('Erro ao processar datas no filtro: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Gera link para exportação CSV
     * 
     * @param array $filters Filtros aplicados
     * @return string URL para exportação CSV
     */
    private static function generateCsvLink(array $filters): string 
    {
        $baseArgs = [
            'action' => 'pmn_export_csv',
            '_wpnonce' => wp_create_nonce('pmn_export_csv'),
        ];

        $filterArgs = array_filter($filters, fn($v) => $v !== '');
        $allArgs = array_merge($baseArgs, $filterArgs);

        return add_query_arg($allArgs, admin_url('admin-post.php'));
    }

    /**
     * Renderiza a view completa do relatório
     * 
     * @param \WP_Query $query Query dos protocolos
     * @param array $filters Filtros aplicados
     * @param string $csvLink Link para CSV
     * @return string HTML renderizado
     */
    private static function renderView(\WP_Query $query, array $filters, string $csvLink): string 
    {
        ob_start();
        ?>
        <?php echo self::renderStyles(); ?>
        
        <div class="pmn-r-wrap" id="pmn-report-root">
            <?php echo self::renderHeader($csvLink); ?>
            <?php echo self::renderFilterForm($filters); ?>
            <?php echo self::renderTable($query); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza os estilos CSS
     * 
     * @return string CSS styles
     */
    private static function renderStyles(): string 
    {
        return '
        <style>
            .pmn-r-wrap {
                background: #fff;
                border: 1px solid #e6ecfb;
                border-radius: 16px;
                box-shadow: 0 2px 16px rgba(23, 87, 182, 0.1);
                padding: 20px;
                margin: 20px 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            
            .pmn-r-head {
                display: flex;
                gap: 12px;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                margin-bottom: 20px;
                border-bottom: 2px solid #f6f9ff;
                padding-bottom: 15px;
            }
            
            .pmn-r-title {
                font-weight: 800;
                color: #1757b6;
                font-size: 1.4rem;
                margin: 0;
            }
            
            .pmn-r-actions {
                display: flex;
                gap: 10px;
            }
            
            .pmn-r-actions a,
            .pmn-r-actions button {
                background: linear-gradient(135deg, #1757b6 0%, #2a6bc7 100%);
                color: #fff;
                border: none;
                border-radius: 8px;
                padding: 10px 16px;
                text-decoration: none;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s ease;
                font-size: 14px;
            }
            
            .pmn-r-actions a:hover,
            .pmn-r-actions button:hover {
                background: linear-gradient(135deg, #144a9e 0%, #2460b8 100%);
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(23, 87, 182, 0.3);
            }
            
            .pmn-r-filtros {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 12px;
                margin: 20px 0;
                padding: 20px;
                background: #f8faff;
                border-radius: 12px;
                border: 1px solid #e6ecfb;
            }
            
            .pmn-r-filtros input,
            .pmn-r-filtros select {
                border: 2px solid #d9e2ff;
                border-radius: 8px;
                padding: 10px 12px;
                font-size: 14px;
                transition: border-color 0.2s ease;
                background: #fff;
            }
            
            .pmn-r-filtros input:focus,
            .pmn-r-filtros select:focus {
                outline: none;
                border-color: #1757b6;
                box-shadow: 0 0 0 3px rgba(23, 87, 182, 0.1);
            }
            
            .pmn-r-filtros button {
                background: linear-gradient(135deg, #28a745 0%, #34ce57 100%);
                color: #fff;
                border: none;
                border-radius: 8px;
                padding: 10px 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s ease;
                grid-column: span 2;
                justify-self: start;
            }
            
            .pmn-r-filtros button:hover {
                background: linear-gradient(135deg, #218838 0%, #2db84d 100%);
                transform: translateY(-1px);
            }
            
            .pmn-r-table-container {
                overflow-x: auto;
                border-radius: 12px;
                border: 1px solid #e6ecfb;
            }
            
            table.pmn-r {
                width: 100%;
                border-collapse: collapse;
                background: #fff;
                min-width: 1000px;
            }
            
            table.pmn-r th,
            table.pmn-r td {
                border: 1px solid #eef2fb;
                padding: 12px 10px;
                text-align: left;
                vertical-align: top;
            }
            
            table.pmn-r th {
                background: linear-gradient(135deg, #f6f9ff 0%, #eef4ff 100%);
                color: #1d2b5e;
                font-weight: 700;
                font-size: 13px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                position: sticky;
                top: 0;
                z-index: 10;
            }
            
            table.pmn-r tbody tr {
                transition: background-color 0.2s ease;
            }
            
            table.pmn-r tbody tr:nth-child(even) {
                background: #fafbff;
            }
            
            table.pmn-r tbody tr:hover {
                background: #f0f5ff;
            }
            
            table.pmn-r td {
                font-size: 14px;
                line-height: 1.4;
            }
            
            table.pmn-r a {
                color: #1757b6;
                text-decoration: none;
                font-weight: 600;
            }
            
            table.pmn-r a:hover {
                text-decoration: underline;
            }
            
            .pmn-no-results {
                text-align: center;
                padding: 40px;
                color: #6c757d;
                font-style: italic;
            }
            
            @media screen and (max-width: 768px) {
                .pmn-r-wrap {
                    padding: 15px;
                    margin: 10px;
                }
                
                .pmn-r-head {
                    flex-direction: column;
                    align-items: stretch;
                }
                
                .pmn-r-filtros {
                    grid-template-columns: 1fr;
                }
                
                .pmn-r-filtros button {
                    grid-column: span 1;
                }
            }
            
            @media print {
                .hide-print {
                    display: none !important;
                }
                
                .pmn-r-wrap {
                    box-shadow: none;
                    border: none;
                    padding: 0;
                }
                
                table.pmn-r {
                    font-size: 12px;
                }
                
                table.pmn-r th,
                table.pmn-r td {
                    padding: 6px 4px;
                }
            }
        </style>';
    }

    /**
     * Renderiza o cabeçalho do relatório
     * 
     * @param string $csvLink Link para CSV
     * @return string HTML do cabeçalho
     */
    private static function renderHeader(string $csvLink): string 
    {
        ob_start();
        ?>
        <div class="pmn-r-head">
            <h1 class="pmn-r-title">📋 Relatório de Protocolos</h1>
            <div class="pmn-r-actions hide-print">
                <a href="<?php echo esc_url($csvLink); ?>" title="Exportar dados para CSV">
                    📊 Exportar CSV
                </a>
                <button type="button" onclick="<?php echo self::getPrintScript(); ?>" title="Imprimir relatório">
                    🖨️ Imprimir
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza o formulário de filtros
     * 
     * @param array $filters Filtros aplicados
     * @return string HTML do formulário
     */
    private static function renderFilterForm(array $filters): string 
    {
        ob_start();
        ?>
        <form method="get" class="pmn-r-filtros hide-print">
            <input 
                type="text" 
                name="busca_num" 
                placeholder="🔍 Número do Protocolo" 
                value="<?php echo esc_attr($filters['busca_num']); ?>"
            >
            
            <select name="status">
                <option value="">📋 Status: Todos</option>
                <?php foreach (self::STATUS_OPTIONS as $status): ?>
                    <option value="<?php echo esc_attr($status); ?>" <?php selected($filters['status'], $status); ?>>
                        <?php echo esc_html($status); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <select name="tipo">
                <option value="">📁 Tipo: Todos</option>
                <?php foreach (self::TIPO_OPTIONS as $tipo): ?>
                    <option value="<?php echo esc_attr($tipo); ?>" <?php selected($filters['tipo'], $tipo); ?>>
                        <?php echo esc_html($tipo); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <select name="tipo_documento">
                <option value="">📄 Tipo Doc: Todos</option>
                <?php foreach (self::TIPO_DOCUMENTO_OPTIONS as $doc): ?>
                    <option value="<?php echo esc_attr($doc); ?>" <?php selected($filters['tipo_documento'], $doc); ?>>
                        <?php echo esc_html($doc); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <input 
                type="date" 
                name="data_ini" 
                title="Data inicial"
                value="<?php echo esc_attr($filters['data_ini']); ?>"
            >
            
            <input 
                type="date" 
                name="data_fim" 
                title="Data final"
                value="<?php echo esc_attr($filters['data_fim']); ?>"
            >
            
            <input 
                type="text" 
                name="responsavel" 
                placeholder="👤 Responsável" 
                value="<?php echo esc_attr($filters['responsavel']); ?>"
            >
            
            <button type="submit">🔍 Filtrar Resultados</button>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza a tabela de resultados
     * 
     * @param \WP_Query $query Query dos protocolos
     * @return string HTML da tabela
     */
    private static function renderTable(\WP_Query $query): string 
    {
        ob_start();
        ?>
        <div class="pmn-r-table-container">
            <table class="pmn-r">
                <thead>
                    <tr>
                        <th>Número</th>
                        <th>Data</th>
                        <th>Tipo</th>
                        <th>Tipo Doc</th>
                        <th>Origem</th>
                        <th>Destino</th>
                        <th>Assunto</th>
                        <th>Status</th>
                        <th>Responsável</th>
                        <th>Prazo</th>
                        <th>Limite</th>
                        <th>Drive</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($query->have_posts()): ?>
                        <?php while ($query->have_posts()): $query->the_post(); ?>
                            <?php echo self::renderTableRow(get_the_ID()); ?>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="12" class="pmn-no-results">
                                🔍 Nenhum protocolo encontrado com os filtros aplicados.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        wp_reset_postdata();
        return ob_get_clean();
    }

    /**
     * Renderiza uma linha da tabela
     * 
     * @param int $postId ID do post
     * @return string HTML da linha
     */
    private static function renderTableRow(int $postId): string 
    {
        $data = self::getProtocolData($postId);
        
        ob_start();
        ?>
        <tr>
            <td><strong><?php echo esc_html($data['numero']); ?></strong></td>
            <td><?php echo esc_html($data['data_formatada']); ?></td>
            <td><?php echo esc_html($data['tipo']); ?></td>
            <td><?php echo esc_html($data['tipo_documento']); ?></td>
            <td><?php echo esc_html($data['origem']); ?></td>
            <td><?php echo esc_html($data['destino']); ?></td>
            <td><?php echo esc_html($data['assunto']); ?></td>
            <td>
                <span class="status-badge status-<?php echo esc_attr(strtolower(str_replace(' ', '-', $data['status']))); ?>">
                    <?php echo esc_html($data['status']); ?>
                </span>
            </td>
            <td><?php echo esc_html($data['responsavel']); ?></td>
            <td><?php echo esc_html($data['prazo'] ?: '—'); ?></td>
            <td><?php echo esc_html($data['limite'] ?: '—'); ?></td>
            <td>
                <?php if ($data['drive']): ?>
                    <a href="<?php echo esc_url($data['drive']); ?>" target="_blank" rel="noopener noreferrer">
                        🔗 Abrir
                    </a>
                <?php else: ?>
                    —
                <?php endif; ?>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Extrai dados do protocolo
     * 
     * @param int $postId ID do post
     * @return array Dados do protocolo
     */
    private static function getProtocolData(int $postId): array 
    {
        $data = [
            'numero' => get_the_title($postId),
            'data' => (string) get_post_meta($postId, 'data', true),
            'tipo' => (string) get_post_meta($postId, 'tipo', true),
            'tipo_documento' => (string) get_post_meta($postId, 'tipo_documento', true),
            'origem' => (string) get_post_meta($postId, 'origem', true),
            'destino' => (string) get_post_meta($postId, 'destino', true),
            'assunto' => (string) get_post_meta($postId, 'assunto', true),
            'status' => (string) get_post_meta($postId, 'status', true),
            'responsavel' => (string) get_post_meta($postId, 'responsavel', true),
            'prazo' => (int) get_post_meta($postId, 'prazo', true),
            'drive' => (string) get_post_meta($postId, 'drive_link', true),
        ];

        // Formatar data
        $data['data_formatada'] = self::formatDate($data['data']);
        
        // Calcular limite
        $data['limite'] = self::calculateLimite($data['data'], $data['prazo']);

        return $data;
    }

    /**
     * Formata data para exibição
     * 
     * @param string $date Data no formato Y-m-d
     * @return string Data formatada ou string vazia
     */
    private static function formatDate(string $date): string 
    {
        if (empty($date)) {
            return '';
        }

        try {
            $dateObj = new \DateTimeImmutable($date);
            return $dateObj->format('d/m/Y');
        } catch (\Exception $e) {
            return $date;
        }
    }

    /**
     * Calcula data limite baseada na data e prazo
     * 
     * @param string $data Data inicial
     * @param int $prazo Prazo em dias
     * @return string Data limite ou string vazia
     */
    private static function calculateLimite(string $data, int $prazo): string 
    {
        if (empty($data) || $prazo <= 0) {
            return '';
        }

        try {
            $dataObj = new \DateTimeImmutable($data);
            $limite = $dataObj->modify("+{$prazo} days");
            return $limite->format('d/m/Y');
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Retorna o script JavaScript para impressão
     * 
     * @return string Script JavaScript
     */
    private static function getPrintScript(): string 
    {
        return "
        (function() {
            var root = document.getElementById('pmn-report-root');
            var printWindow = window.open('', '_blank', 'width=1200,height=800');
            
            var printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='utf-8'>
                    <title>Relatório de Protocolos</title>
                    <style>
                        body { 
                            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
                            padding: 20px; 
                            color: #333;
                        }
                        h1 { 
                            color: #1757b6; 
                            border-bottom: 2px solid #e6ecfb; 
                            padding-bottom: 10px; 
                        }
                        table { 
                            width: 100%; 
                            border-collapse: collapse; 
                            margin-top: 20px; 
                        }
                        th, td { 
                            border: 1px solid #ddd; 
                            padding: 8px; 
                            text-align: left; 
                            font-size: 12px;
                        }
                        th { 
                            background: #f6f9ff; 
                            font-weight: 700;
                        }
                        tr:nth-child(even) { 
                            background: #fafbff; 
                        }
                        .hide-print { 
                            display: none !important; 
                        }
                    </style>
                </head>
                <body>
                    \${root.innerHTML}
                </body>
                </html>
            `;
            
            printWindow.document.write(printContent);
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
            printWindow.close();
        })()";
    }
}