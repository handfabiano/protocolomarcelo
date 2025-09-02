<?php
namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) exit;

class ListTable {

    // Configurações padrão
    private const POSTS_PER_PAGE     = 10;
    private const MAX_REPORT_ITEMS   = 150;

    // Valores aceitos
    private const VALID_STATUSES     = ['Em tramitação', 'Concluído', 'Arquivado'];
    private const VALID_TIPOS        = ['Entrada', 'Saída'];
    private const VALID_TIPOS_DOC    = ['Ofício', 'Memorando', 'Requerimento', 'Despacho', 'Outro'];
    private const VALID_EXPORTS      = ['excel', 'pdf'];

    /**
     * Página principal (lista com filtros)
     */
    public static function render_protocolo_tabela() {
        $core = Core::instance();

        // Segurança
        $not_logged = $core->require_login();
        if ($not_logged) return $not_logged;

        $no_perm = $core->require_permission();
        if ($no_perm) return $no_perm;

        // Filtros e paginação
        $filters = self::sanitize_filters($_GET);
        $paged   = self::get_current_page();

        // Exportação por GET (opcional)
        if (self::should_export()) {
            return self::handle_export($filters);
        }

        ob_start();

        echo $core->barra_login_status();
        echo $core->css_responsivo();
        echo self::get_enhanced_styles();

        // Abas
        echo self::render_navigation_tabs();

        // Formulário de filtros
        echo self::render_filters_form($filters);

        // Lista/cards
        echo self::render_protocols_list($filters, $paged);

        return ob_get_clean();
    }

    /**
     * Relatório simplificado
     */
    public static function render_relatorio_simples() {
        $core = Core::instance();

        $not_logged = $core->require_login();
        if ($not_logged) return $not_logged;

        $no_perm = $core->require_permission();
        if ($no_perm) return $no_perm;

        $filters = self::sanitize_report_filters($_GET);
        $paged   = self::get_current_page();

        ob_start();
        echo $core->css_responsivo();
        echo self::get_report_styles();

        echo '<h2 class="mn-report-title">📊 Relatório de Protocolos</h2>';
        echo self::render_report_filters($filters);
        echo self::render_report_table($filters, $paged);

        return ob_get_clean();
    }

    // =========================
    // Sanitização / Utilidades
    // =========================

    private static function sanitize_filters(array $input): array {
        return [
            'busca_num'      => isset($input['busca_num']) ? sanitize_text_field(wp_unslash($input['busca_num'])) : '',
            'status'         => (isset($input['status']) && in_array($input['status'], self::VALID_STATUSES, true)) ? sanitize_text_field(wp_unslash($input['status'])) : '',
            'tipo'           => (isset($input['tipo']) && in_array($input['tipo'], self::VALID_TIPOS, true)) ? sanitize_text_field(wp_unslash($input['tipo'])) : '',
            'tipo_documento' => (isset($input['tipo_documento']) && in_array($input['tipo_documento'], self::VALID_TIPOS_DOC, true)) ? sanitize_text_field(wp_unslash($input['tipo_documento'])) : '',
            'data_ini'       => isset($input['data_ini']) ? self::sanitize_date($input['data_ini']) : '',
            'data_fim'       => isset($input['data_fim']) ? self::sanitize_date($input['data_fim']) : '',
            'responsavel'    => isset($input['responsavel']) ? sanitize_text_field(wp_unslash($input['responsavel'])) : '',
        ];
    }

    private static function sanitize_report_filters(array $input): array {
        return [
            'busca_num'      => isset($input['busca_num']) ? sanitize_text_field($input['busca_num']) : '',
            'status'         => (isset($input['status']) && in_array($input['status'], self::VALID_STATUSES, true)) ? sanitize_text_field($input['status']) : '',
            'tipo'           => (isset($input['tipo']) && in_array($input['tipo'], self::VALID_TIPOS, true)) ? sanitize_text_field($input['tipo']) : '',
            'tipo_documento' => (isset($input['tipo_documento']) && in_array($input['tipo_documento'], self::VALID_TIPOS_DOC, true)) ? sanitize_text_field($input['tipo_documento']) : '',
        ];
    }

    private static function sanitize_date(string $date): string {
        $val = sanitize_text_field(wp_unslash($date));
        if ($val === '') return '';
        try {
            $dt = new \DateTimeImmutable($val);
            return $dt->format('Y-m-d');
        } catch (\Exception $e) {
            return '';
        }
    }

    private static function get_current_page(): int {
        $paged = get_query_var('paged') ?: (isset($_GET['paged']) ? $_GET['paged'] : 1);
        return max(1, intval($paged));
    }

    private static function should_export(): bool {
        return !empty($_GET['export'])
            && in_array($_GET['export'], self::VALID_EXPORTS, true)
            && isset($_GET['_mnexp']);
    }

    private static function handle_export(array $filters): string {
        if (!wp_verify_nonce($_GET['_mnexp'] ?? '', 'mn_export_protocolos')) {
            wp_die('Erro de segurança. Tente novamente.');
        }
        return self::export_protocolos(sanitize_text_field($_GET['export']), $filters);
    }

    // ==============
    // Blocos visuais
    // ==============

    private static function render_navigation_tabs(): string {
        $pages = [
            'lista' => ['slug' => ['lista-de-protocolos', 'lista'], 'icon' => '📄', 'label' => 'Lista'],
            'novo'  => ['slug' => ['cadastrar', 'novo'], 'icon' => '➕', 'label' => 'Novo Protocolo'],
            'cons'  => ['slug' => ['visualizar-protocolo', 'consultar'], 'icon' => '🔎', 'label' => 'Consultar'],
        ];

        $current_slug = is_page() ? (get_queried_object()->post_name ?? '') : '';
        $out = '<nav class="mn-tabs" role="navigation" aria-label="Navegação de protocolos">';

        foreach ($pages as $page) {
            $page_obj = get_page_by_path($page['slug'][0]) ?: get_page_by_path($page['slug'][1]);
            if (!$page_obj) continue;

            $is_active = in_array($current_slug, $page['slug'], true);
            $url       = get_permalink($page_obj);

            $out .= sprintf(
                '<a class="mn-tab%s" %s href="%s">%s %s</a>',
                $is_active ? ' is-active' : '',
                $is_active ? 'aria-current="page"' : '',
                esc_url($url),
                $page['icon'],
                esc_html($page['label'])
            );
        }

        $out .= '</nav>';
        return $out;
    }

    /**
     * Formulário de filtros (corrigido)
     */
    private static function render_filters_form(array $filters): string {
        $user_options = self::get_user_options();
        $csv_url      = self::build_csv_export_url($filters);

        ob_start(); ?>
        <form method="get" class="mn-filtros-bar" role="search" aria-label="Filtros de protocolos">
            <div class="mn-form-group">
                <label for="mn_busca_num">Nº Protocolo</label>
                <input id="mn_busca_num" type="text" name="busca_num"
                       value="<?php echo esc_attr($filters['busca_num']); ?>"
                       placeholder="Ex: 2025001">
            </div>

            <div class="mn-form-group">
                <label for="mn_status">Status</label>
                <select id="mn_status" name="status">
                    <option value="">Todos os status</option>
                    <?php foreach (self::VALID_STATUSES as $status_option): ?>
                        <option value="<?php echo esc_attr($status_option); ?>"
                            <?php selected($filters['status'], $status_option); ?>>
                            <?php echo esc_html($status_option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mn-form-group">
                <label for="mn_tipo">Tipo</label>
                <select id="mn_tipo" name="tipo">
                    <option value="">Todos os tipos</option>
                    <?php foreach (self::VALID_TIPOS as $tipo_option): ?>
                        <option value="<?php echo esc_attr($tipo_option); ?>"
                            <?php selected($filters['tipo'], $tipo_option); ?>>
                            <?php echo esc_html($tipo_option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mn-form-group">
                <label for="mn_tipodoc">Tipo Documento</label>
                <select id="mn_tipodoc" name="tipo_documento">
                    <option value="">Todos os documentos</option>
                    <?php foreach (self::VALID_TIPOS_DOC as $doc_option): ?>
                        <option value="<?php echo esc_attr($doc_option); ?>"
                            <?php selected($filters['tipo_documento'], $doc_option); ?>>
                            <?php echo esc_html($doc_option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mn-form-group">
                <label for="mn_data_ini">Data Inicial</label>
                <input id="mn_data_ini" type="date" name="data_ini" value="<?php echo esc_attr($filters['data_ini']); ?>">
            </div>

            <div class="mn-form-group">
                <label for="mn_data_fim">Data Final</label>
                <input id="mn_data_fim" type="date" name="data_fim" value="<?php echo esc_attr($filters['data_fim']); ?>">
            </div>

            <div class="mn-form-group">
                <label for="mn_responsavel">Responsável</label>
                <select id="mn_responsavel" name="responsavel">
                    <option value="">Todos os responsáveis</option>
                    <?php foreach ($user_options as $user): ?>
                        <option value="<?php echo esc_attr($user->display_name); ?>"
                            <?php selected($filters['responsavel'], $user->display_name); ?>>
                            <?php echo esc_html($user->display_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mn-filtros-actions">
                <button type="submit" class="mn-btn mn-btn-primary">🔍 Filtrar</button>

                <a href="<?php echo esc_url($csv_url); ?>" class="mn-btn mn-btn-excel" rel="nofollow">
                    📊 Exportar CSV
                </a>

                <button type="button" class="mn-btn mn-btn-print" onclick="print_protocols()">
                    🖨️ Imprimir
                </button>

                <?php if (array_filter($filters)): ?>
                    <a href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'], '?')); ?>" class="mn-btn mn-btn-secondary">
                        🗑️ Limpar Filtros
                    </a>
                <?php endif; ?>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Lista de protocolos em cards
     */
    private static function render_protocols_list(array $filters, int $paged): string {
        $query = self::build_protocols_query($filters, $paged);
        $urls  = self::get_action_urls();

        ob_start(); ?>
        <div class="mn-protocols-container">
            <?php if ($query->have_posts()): ?>
                <div class="mn-results-summary">
                    <span class="mn-count">
                        <?php echo sprintf('Encontrados: <strong>%d</strong> protocolos', intval($query->found_posts)); ?>
                    </span>
                    <?php if (array_filter($filters)): ?>
                        <span class="mn-filters-active">📋 Filtros ativos</span>
                    <?php endif; ?>
                </div>

                <div class="mn-card-lista">
                    <?php while ($query->have_posts()): $query->the_post(); ?>
                        <?php echo self::render_protocol_card(get_the_ID(), $urls); ?>
                    <?php endwhile; ?>
                </div>

                <?php echo self::render_pagination($query, $filters, $paged); ?>
            <?php else: ?>
                <div class="mn-empty-state">
                    <div class="mn-empty-icon">📋</div>
                    <h3>Nenhum protocolo encontrado</h3>
                    <p>Tente ajustar os filtros ou criar um novo protocolo.</p>
                    <?php if (!empty($urls['novo'])): ?>
                        <a href="<?php echo esc_url($urls['novo']); ?>" class="mn-btn mn-btn-primary">➕ Criar Novo Protocolo</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        wp_reset_postdata();
        return ob_get_clean();
    }

    /**
     * Card individual
     */
    private static function render_protocol_card(int $id, array $urls): string {
        $meta        = self::get_protocol_meta($id);
        $status_info = self::get_status_info($meta);
        $actions     = self::get_protocol_actions($id, $urls, $meta);

        ob_start(); ?>
        <article class="mn-card<?php echo $status_info['atrasado'] ? ' mn-card--urgent' : ''; ?>" data-protocol-id="<?php echo esc_attr($id); ?>">
            <header class="mn-card-header">
                <a href="<?php echo esc_url($actions['detalhe_url']); ?>" class="mn-numero" title="Ver detalhes do protocolo"
                   aria-label="Protocolo número <?php echo esc_attr($meta['numero']); ?>">
                    <?php echo esc_html($meta['numero']); ?>
                </a>

                <span class="mn-status mn-status--<?php echo esc_attr($status_info['class']); ?>" title="<?php echo esc_attr($status_info['tooltip']); ?>">
                    <?php echo esc_html($meta['status'] ?: 'Indefinido'); ?>
                    <?php if ($status_info['atrasado']): ?><span class="mn-status-urgent" aria-label="Protocolo em atraso">⚠️</span><?php endif; ?>
                </span>
            </header>

            <div class="mn-card-content">
                <div class="mn-info-grid">
                    <div class="mn-info">
                        <span class="mn-info-label">Data:</span>
                        <span class="mn-info-value"><?php echo esc_html(self::format_date($meta['data'])); ?></span>
                    </div>
                    <div class="mn-info">
                        <span class="mn-info-label">Tipo:</span>
                        <span class="mn-info-value"><?php echo esc_html($meta['tipo'] ?: '—'); ?></span>
                    </div>
                    <div class="mn-info">
                        <span class="mn-info-label">Documento:</span>
                        <span class="mn-info-value"><?php echo esc_html($meta['tipo_documento'] ?: '—'); ?></span>
                    </div>
                    <div class="mn-info mn-info--full">
                        <span class="mn-info-label">Assunto:</span>
                        <span class="mn-info-value" title="<?php echo esc_attr($meta['assunto']); ?>">
                            <?php echo esc_html(self::truncate_text($meta['assunto'] ?? '', 80)); ?>
                        </span>
                    </div>
                </div>
            </div>

            <footer class="mn-card-actions">
                <?php foreach ($actions['buttons'] as $action): ?>
                    <a href="<?php echo esc_url($action['url']); ?>" class="mn-btn-action"
                       title="<?php echo esc_attr($action['title']); ?>"
                       aria-label="<?php echo esc_attr($action['aria_label']); ?>"
                       <?php echo $action['attributes']; ?>>
                        <?php echo $action['icon']; ?>
                        <span class="mn-action-text"><?php echo esc_html($action['text']); ?></span>
                    </a>
                <?php endforeach; ?>
            </footer>
        </article>
        <?php
        return ob_get_clean();
    }

    /**
     * Query da lista
     */
    private static function build_protocols_query(array $filters, int $paged): \WP_Query {
        $meta_query = ['relation' => 'AND'];

        foreach (['status', 'tipo', 'tipo_documento'] as $field) {
            if (!empty($filters[$field])) {
                $meta_query[] = ['key' => $field, 'value' => $filters[$field], 'compare' => '='];
            }
        }

        if (!empty($filters['responsavel'])) {
            $meta_query[] = [
                'key'     => 'responsavel',
                'value'   => $filters['responsavel'],
                'compare' => 'LIKE'
            ];
        }

        if (!empty($filters['data_ini']) || !empty($filters['data_fim'])) {
            $date_query = ['key' => 'data', 'type' => 'DATE'];
            if (!empty($filters['data_ini']) && !empty($filters['data_fim'])) {
                $date_query['value']   = [$filters['data_ini'], $filters['data_fim']];
                $date_query['compare'] = 'BETWEEN';
            } elseif (!empty($filters['data_ini'])) {
                $date_query['value']   = $filters['data_ini'];
                $date_query['compare'] = '>=';
            } else {
                $date_query['value']   = $filters['data_fim'];
                $date_query['compare'] = '<=';
            }
            $meta_query[] = $date_query;
        }

        $args = [
            'post_type'              => 'protocolo',
            'posts_per_page'         => self::POSTS_PER_PAGE,
            'paged'                  => $paged,
            'post_status'            => 'publish',
            'no_found_rows'          => false,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'orderby'                => 'date',
            'order'                  => 'DESC',
        ];

        if (count($meta_query) > 1) $args['meta_query'] = $meta_query;
        if (!empty($filters['busca_num'])) $args['s'] = $filters['busca_num'];

        return new \WP_Query($args);
    }

    /**
     * Metas do post
     */
    private static function get_protocol_meta(int $id): array {
        $meta_keys = ['data','tipo','tipo_documento','assunto','status','anexo_id','prazo','drive_link','responsavel'];
        $meta      = ['numero' => get_the_title($id)];

        foreach ($meta_keys as $key) {
            $meta[$key] = get_post_meta($id, $key, true);
        }
        return $meta;
    }

    /**
     * Status + atraso
     */
    private static function get_status_info(array $meta): array {
        $atrasado    = false;
        $data_limite = '';
        $tooltip     = $meta['status'] ?: 'Status indefinido';

        if (!empty($meta['data']) && !empty($meta['prazo'])) {
            try {
                $lim = (new \DateTimeImmutable($meta['data']))->modify('+' . intval($meta['prazo']) . ' days');
                $data_limite = $lim->format('Y-m-d');
                $atrasado    = ($meta['status'] !== 'Concluído') && (current_time('Y-m-d') > $data_limite);
                if ($atrasado) $tooltip .= ' (Atrasado desde ' . $lim->format('d/m/Y') . ')';
            } catch (\Exception $e) { /* silencioso */ }
        }

        $class_map = [
            'Concluído'     => 'success',
            'Arquivado'     => 'archived',
            'Em tramitação' => $atrasado ? 'urgent' : 'progress'
        ];

        return [
            'atrasado'    => $atrasado,
            'data_limite' => $data_limite,
            'class'       => $class_map[$meta['status']] ?? 'default',
            'tooltip'     => $tooltip
        ];
    }

    /**
     * Botões/Ações
     */
    private static function get_protocol_actions(int $id, array $urls, array $meta): array {
        $actions = [
            'detalhe_url' => !empty($urls['detalhe']) ? add_query_arg(['id'=>$id], $urls['detalhe']) : '',
            'buttons'     => [],
        ];

        if (!empty($urls['movimentar'])) {
            $actions['buttons'][] = [
                'url'        => add_query_arg(['id'=>$id], $urls['movimentar']),
                'icon'       => '🔄',
                'text'       => 'Movimentar',
                'title'      => 'Adicionar movimentação',
                'aria_label' => 'Movimentar protocolo ' . ($meta['numero'] ?? ''),
                'attributes' => ''
            ];
        }

        if (!empty($urls['editar'])) {
            $actions['buttons'][] = [
                'url'        => add_query_arg(['id'=>$id], $urls['editar']),
                'icon'       => '✏️',
                'text'       => 'Editar',
                'title'      => 'Editar protocolo',
                'aria_label' => 'Editar protocolo ' . ($meta['numero'] ?? ''),
                'attributes' => ''
            ];
        }

        if (!empty($meta['anexo_id'])) {
            $anexo_url = wp_get_attachment_url(intval($meta['anexo_id']));
            if ($anexo_url) {
                $actions['buttons'][] = [
                    'url'        => $anexo_url,
                    'icon'       => '📎',
                    'text'       => 'Anexo',
                    'title'      => 'Visualizar anexo',
                    'aria_label' => 'Ver anexo do protocolo ' . ($meta['numero'] ?? ''),
                    'attributes' => 'target="_blank" rel="noopener"'
                ];
            }
        }

        if (!empty($meta['drive_link'])) {
            $actions['buttons'][] = [
                'url'        => $meta['drive_link'],
                'icon'       => '🗂️',
                'text'       => 'Drive',
                'title'      => 'Abrir no Google Drive',
                'aria_label' => 'Abrir no Drive protocolo ' . ($meta['numero'] ?? ''),
                'attributes' => 'target="_blank" rel="noopener nofollow"'
            ];
        }

        return $actions;
    }

    /**
     * Paginação
     */
    private static function render_pagination(\WP_Query $query, array $filters, int $paged): string {
        $total_pages = intval($query->max_num_pages);
        if ($total_pages <= 1) return '';

        $current_args = array_filter($filters, static fn($v) => $v !== '' && $v !== null);

        $big      = 999999999;
        $base_url = str_replace($big, '%#%', esc_url(get_pagenum_link($big)));

        ob_start(); ?>
        <nav class="mn-paginacao" aria-label="Navegação de páginas">
            <?php
            echo paginate_links([
                'base'      => $base_url,
                'format'    => '',
                'current'   => $paged,
                'total'     => $total_pages,
                'prev_text' => '← Anterior',
                'next_text' => 'Próxima →',
                'type'      => 'list',
                'end_size'  => 2,
                'mid_size'  => 1,
                'add_args'  => $current_args,
            ]);
            ?>
            <div class="mn-pagination-info">
                Página <strong><?php echo intval($paged); ?></strong> de <strong><?php echo $total_pages; ?></strong>
                (<?php echo intval($query->found_posts); ?> protocolos)
            </div>
        </nav>
        <?php
        return ob_get_clean();
    }

    // ============
    // Relatório
    // ============

    private static function render_report_filters(array $filters): string {
        ob_start(); ?>
        <form method="get" class="mn-report-filters">
            <div class="mn-filters-row">
                <input type="text" name="busca_num" placeholder="Número do protocolo"
                       value="<?php echo esc_attr($filters['busca_num']); ?>">

                <select name="status">
                    <option value="">Status: Todos</option>
                    <?php foreach (self::VALID_STATUSES as $status): ?>
                        <option value="<?php echo esc_attr($status); ?>" <?php selected($filters['status'], $status); ?>>
                            <?php echo esc_html($status); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="tipo">
                    <option value="">Tipo: Todos</option>
                    <?php foreach (self::VALID_TIPOS as $tipo): ?>
                        <option value="<?php echo esc_attr($tipo); ?>" <?php selected($filters['tipo'], $tipo); ?>>
                            <?php echo esc_html($tipo); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="tipo_documento">
                    <option value="">Documento: Todos</option>
                    <?php foreach (self::VALID_TIPOS_DOC as $doc): ?>
                        <option value="<?php echo esc_attr($doc); ?>" <?php selected($filters['tipo_documento'], $doc); ?>>
                            <?php echo esc_html($doc); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="mn-btn mn-btn-primary">🔍 Filtrar</button>
                <button type="button" onclick="window.print()" class="mn-btn mn-btn-print">🖨️ Imprimir</button>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    private static function build_report_query(array $filters, int $paged): \WP_Query {
        $meta_query = ['relation' => 'AND'];

        foreach (['status', 'tipo', 'tipo_documento'] as $field) {
            if (!empty($filters[$field])) {
                $meta_query[] = ['key' => $field, 'value' => $filters[$field], 'compare' => '='];
            }
        }

        $args = [
            'post_type'              => 'protocolo',
            'posts_per_page'         => self::MAX_REPORT_ITEMS,
            'paged'                  => $paged,
            'post_status'            => 'publish',
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'orderby'                => 'date',
            'order'                  => 'DESC',
        ];

        if (count($meta_query) > 1) $args['meta_query'] = $meta_query;
        if (!empty($filters['busca_num'])) $args['s'] = $filters['busca_num'];

        return new \WP_Query($args);
    }

    private static function render_report_table(array $filters, int $paged): string {
        $query = self::build_report_query($filters, $paged);

        ob_start(); ?>
        <div class="mn-table-container">
            <?php if ($query->have_posts()): ?>
                <table class="mn-report-table" role="table">
                    <thead>
                        <tr>
                            <th scope="col">Número</th>
                            <th scope="col">Data</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Documento</th>
                            <th scope="col">Origem/Destino</th>
                            <th scope="col">Status</th>
                            <th scope="col">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($query->have_posts()): $query->the_post(); ?>
                            <?php echo self::render_report_row(get_the_ID()); ?>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="mn-empty-state">
                    <p>📊 Nenhum dado encontrado para o relatório.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
        wp_reset_postdata();
        return ob_get_clean();
    }

    private static function render_report_row(int $id): string {
        $meta          = self::get_protocol_meta($id);
        $status_info   = self::get_status_info($meta);
        $origemDestino = ($meta['tipo'] === 'Entrada') ? get_post_meta($id, 'origem', true) : get_post_meta($id, 'destino', true);
        $detalhe_url   = self::get_detail_url($id);
        $drive_link    = $meta['drive_link'];

        ob_start(); ?>
        <tr class="<?php echo $status_info['atrasado'] ? 'mn-row--urgent' : ''; ?>">
            <td data-label="Número">
                <a href="<?php echo esc_url($detalhe_url); ?>" class="mn-protocol-link" title="Ver detalhes">
                    <?php echo esc_html($meta['numero']); ?>
                </a>
            </td>
            <td data-label="Data"><?php echo esc_html(self::format_date($meta['data'])); ?></td>
            <td data-label="Tipo"><?php echo esc_html($meta['tipo'] ?: '—'); ?></td>
            <td data-label="Documento"><?php echo esc_html($meta['tipo_documento'] ?: '—'); ?></td>
            <td data-label="Origem/Destino"><?php echo esc_html($origemDestino ?: '—'); ?></td>
            <td data-label="Status">
                <span class="mn-status mn-status--<?php echo esc_attr($status_info['class']); ?>">
                    <?php echo esc_html($meta['status'] ?: 'Indefinido'); ?>
                    <?php if ($status_info['atrasado']): ?><span title="Atrasado" class="mn-urgent-indicator">⚠️</span><?php endif; ?>
                </span>
            </td>
            <td data-label="Ações">
                <div class="mn-table-actions">
                    <?php if ($drive_link): ?>
                        <a href="<?php echo esc_url($drive_link); ?>" target="_blank" rel="noopener nofollow" class="mn-btn-sm mn-btn-drive" title="Abrir no Google Drive">🗂️ Drive</a>
                    <?php else: ?>
                        <span class="mn-no-action">—</span>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    // ==============
    // Helpers diversos
    // ==============

    private static function get_user_options(): array {
        $user_query = new \WP_User_Query([
            'fields'  => ['display_name'],
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ]);
        return $user_query->get_results();
    }

    private static function get_action_urls(): array {
        $pages = [
            'editar'     => ['editar-protocolo', 'editar'],
            'movimentar' => ['movimentar-protocolo', 'movimentar'],
            'detalhe'    => ['visualizacao-detalhada-do-protocolo', 'visualizar-protocolo'], // tenta os dois
            'novo'       => ['cadastrar', 'novo'],
        ];
        $urls = [];
        foreach ($pages as $key => $slugs) {
            $page      = get_page_by_path($slugs[0]) ?: get_page_by_path($slugs[1]);
            $urls[$key]= $page ? get_permalink($page) : '';
        }
        return $urls;
    }

    private static function build_csv_export_url(array $filters): string {
        $args = array_merge([
            'action'   => 'pmn_export_csv',
            '_wpnonce' => wp_create_nonce('pmn_export_csv'),
        ], array_filter($filters));
        return add_query_arg($args, admin_url('admin-post.php'));
    }

    private static function get_detail_url(int $id): string {
        $page = get_page_by_path('visualizar-protocolo') ?: get_page_by_path('visualizacao-detalhada-do-protocolo');
        return $page ? add_query_arg(['id' => $id], get_permalink($page)) : '';
    }

    private static function format_date(string $date): string {
        if ($date === '') return '—';
        try {
            $dt = new \DateTimeImmutable($date);
            return $dt->format('d/m/Y');
        } catch (\Exception $e) {
            return $date;
        }
    }

    private static function truncate_text(string $text, int $length = 80): string {
        if ($text === '') return '—';
        if (mb_strlen($text) <= $length) return $text;
        $cut        = mb_substr($text, 0, $length);
        $last_space = mb_strrpos($cut, ' ');
        if ($last_space !== false) $cut = mb_substr($cut, 0, $last_space);
        return $cut . '...';
    }

    /**
     * CSS + JS utilitários (inclui print_protocols())
     */
    private static function get_enhanced_styles(): string {
        return '
        <style>
        /* (estilos resumidos) — os completos já estavam no seu arquivo, mantidos */
        /* Tabs */
        .mn-tabs{display:flex;gap:12px;margin:20px 0;border-bottom:2px solid #f1f5f9;padding-bottom:16px}
        .mn-tab{display:inline-flex;align-items:center;gap:8px;padding:12px 16px;border:2px solid transparent;border-radius:12px;text-decoration:none;font-weight:600;color:#475569;background:#f8fafc;transition:.2s}
        .mn-tab:hover{background:#e2e8f0;color:#334155}
        .mn-tab.is-active,.mn-tab[aria-current="page"]{background:linear-gradient(135deg,#e7f0ff,#dbeafe);border-color:#3b82f6;color:#1e40af;font-weight:700}

        /* Barra de filtros */
        .mn-filtros-bar{background:linear-gradient(135deg,#f8fafc,#f1f5f9);padding:24px;border-radius:16px;box-shadow:0 4px 24px rgba(8,84,186,.08);border:1px solid #e2e8f0;margin-bottom:24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;align-items:end}
        .mn-filtros-actions{grid-column:1/-1;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:8px}

        /* Botões */
        .mn-btn{min-height:44px;padding:12px 20px;border:none;border-radius:12px;font-size:.95rem;font-weight:600;cursor:pointer;transition:.2s;display:inline-flex;align-items:center;gap:8px;text-decoration:none}
        .mn-btn-primary{background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff}
        .mn-btn-primary:hover{background:linear-gradient(135deg,#1d4ed8,#1e3a8a);transform:translateY(-1px)}
        .mn-btn-excel{background:linear-gradient(135deg,#10b981,#047857);color:#fff}
        .mn-btn-excel:hover{background:linear-gradient(135deg,#047857,#065f46);transform:translateY(-1px)}
        .mn-btn-print{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff}
        .mn-btn-print:hover{background:linear-gradient(135deg,#d97706,#b45309);transform:translateY(-1px)}
        .mn-btn-secondary{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1}
        .mn-btn-secondary:hover{background:#e2e8f0;color:#334155}

        /* Cards, info e paginação — idem versão anterior */
        .mn-card-lista{display:grid;grid-template-columns:repeat(auto-fit,minmax(350px,1fr));gap:20px;margin:16px 0 30px}
        .mn-card{background:#fff;border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,.08);padding:20px;border:1px solid #e2e8f0;display:flex;flex-direction:column;gap:16px;transition:.2s}
        .mn-card:hover{box-shadow:0 8px 32px rgba(0,0,0,.12);transform:translateY(-2px)}
        .mn-card--urgent{border-left:4px solid #ef4444;background:linear-gradient(135deg,#fef2f2,#fff)}
        .mn-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
        .mn-numero{font-size:1.25rem;color:#1e40af;text-decoration:none;font-weight:700;padding:4px 8px;border-radius:6px}
        .mn-numero:hover{background:#dbeafe;color:#1e3a8a}
        .mn-status{padding:6px 12px;border-radius:20px;font-size:.85rem;font-weight:600;display:inline-flex;align-items:center;gap:4px}
        .mn-status--success{background:#dcfce7;color:#166534}
        .mn-status--progress{background:#dbeafe;color:#1e40af}
        .mn-status--urgent{background:#fef2f2;color:#dc2626;animation:pulse-urgent 2s infinite}
        .mn-status--archived{background:#f1f5f9;color:#64748b}
        .mn-status--default{background:#f3f4f6;color:#6b7280}
        @keyframes pulse-urgent{0%,100%{opacity:1}50%{opacity:.7}}
        .mn-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 16px}
        .mn-info-label{font-size:.8rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.5px}
        .mn-info-value{font-size:.95rem;color:#1e293b;font-weight:500}
        .mn-card-actions{display:flex;gap:8px;margin-top:auto;padding-top:8px;border-top:1px solid #f1f5f9;flex-wrap:wrap}
        .mn-btn-action{background:#f1f5f9;color:#475569;border:none;border-radius:8px;padding:8px 12px;font-size:.9rem;font-weight:500;cursor:pointer;text-decoration:none;transition:.2s;display:inline-flex;align-items:center;gap:6px}
        .mn-btn-action:hover{background:#3b82f6;color:#fff;transform:translateY(-1px)}

        .mn-paginacao{margin:32px 0;text-align:center}
        .mn-paginacao ul{display:inline-flex;gap:8px;padding:0;list-style:none;margin:0 0 16px}
        .mn-paginacao .page-numbers{background:#fff;color:#475569;border:1px solid #e2e8f0;padding:10px 16px;border-radius:8px;font-weight:500;text-decoration:none;transition:.2s}
        .mn-paginacao .current{background:#3b82f6;color:#fff;border-color:#3b82f6}

        /* Tabela do relatório */
        .mn-table-container{overflow-x:auto;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);border:1px solid #e2e8f0}
        .mn-report-table{width:100%;border-collapse:collapse;font-size:.95rem}
        .mn-report-table th{background:linear-gradient(135deg,#f1f5f9,#e2e8f0);color:#334155;font-weight:600;padding:16px 12px;text-align:left;border-bottom:2px solid #cbd5e1;position:sticky;top:0;z-index:10}
        .mn-report-table td{padding:12px;border-bottom:1px solid #f1f5f9;vertical-align:top}
        .mn-protocol-link{color:#1e40af;text-decoration:none;font-weight:600}
        .mn-protocol-link:hover{color:#1e3a8a;text-decoration:underline}
        .mn-row--urgent{background:linear-gradient(135deg,#fef2f2,#fff)!important}
        .mn-urgent-indicator{margin-left:4px;animation:pulse-urgent 2s infinite}

        @media (max-width:950px){
          .mn-filtros-bar{grid-template-columns:1fr;gap:12px}
          .mn-card-lista{grid-template-columns:1fr;gap:16px}
          .mn-tabs{flex-direction:column;gap:8px}
          .mn-tab{justify-content:center}
        }
        @media (max-width:768px){
          .mn-filtros-actions{flex-direction:column}
          .mn-btn{width:100%;justify-content:center}
          .mn-info-grid{grid-template-columns:1fr;gap:8px}
          .mn-card-actions{justify-content:center}
          .mn-report-table, .mn-report-table thead, .mn-report-table tbody, .mn-report-table th, .mn-report-table td, .mn-report-table tr{display:block}
          .mn-report-table thead tr{position:absolute;top:-9999px;left:-9999px}
          .mn-report-table tr{border:1px solid #e2e8f0;border-radius:8px;margin-bottom:16px;padding:12px;background:#fff}
          .mn-report-table td{border:none;position:relative;padding:8px 8px 8px 120px;text-align:left}
          .mn-report-table td:before{content:attr(data-label) ":";position:absolute;left:8px;width:100px;font-weight:600;color:#475569;text-align:left}
        }
        @media print{
          .mn-tabs, .mn-filtros-bar, .mn-card-actions, .mn-paginacao{display:none!important}
          .mn-card{break-inside:avoid;box-shadow:none;border:1px solid #ccc;margin-bottom:20px}
          .mn-report-table{font-size:12px}
          .mn-report-table th, .mn-report-table td{padding:8px 4px}
        }
        </style>
        <script>
        function print_protocols(){
          const elementsToHide = document.querySelectorAll(".mn-filtros-bar, .mn-card-actions, .mn-paginacao");
          elementsToHide.forEach(el => el.style.display = "none");
          window.print();
          setTimeout(() => elementsToHide.forEach(el => el.style.display = ""), 1000);
        }
        document.addEventListener("keydown", function(e){
          if(e.key === "Escape"){
            document.querySelectorAll(".mn-modal, .mn-overlay").forEach(m => m.remove());
          }
        });
        </script>';
    }

    private static function get_report_styles(): string {
        return '
        <style>
        .mn-report-title{color:#1e40af;text-align:center;margin-bottom:32px;font-size:1.8rem;font-weight:700;display:flex;align-items:center;justify-content:center;gap:12px}
        .mn-report-filters{background:linear-gradient(135deg,#f8fafc,#f1f5f9);padding:20px;border-radius:12px;margin-bottom:24px;border:1px solid #e2e8f0}
        @media print{.mn-report-filters{display:none!important} body{font-size:12px}}
        </style>';
    }

    /**
     * Exportação (placeholder)
     */
    private static function export_protocolos(string $format, array $filters): string {
        // Implemente aqui se quiser exportação direta via GET (?export=excel|pdf&_mnexp=...)
        return '<div class="notice notice-info"><p>Exportação em desenvolvimento para o formato: ' .
               esc_html($format) . '</p></div>';
    }
}
