<?php
namespace ProtocoloMunicipal;

if (!defined('ABSPATH')) exit;

class Forms
{
    /** CADASTRAR */
    public static function render_protocolo_form()
    {
        $action = esc_url(admin_url('admin-post.php'));
        $user   = wp_get_current_user();

        ob_start(); ?>

        <form class="mn-form" action="<?php echo $action; ?>" method="post" enctype="multipart/form-data" id="mn-protocolo-form">
            <input type="hidden" name="action" value="mn_save_protocolo">
            <?php wp_nonce_field('mn_save_protocolo_action','mn_protocolo_nonce'); ?>

            <div class="mn-section">📋 Cadastrar Novo Protocolo</div>

            <div class="mn-grid">
                <div class="mn-form-group mn-col-4">
                    <label for="mn_numero" class="required">Número do Protocolo</label>
                    <input id="mn_numero" name="numero" required placeholder="Ex.: 0001/2025">
                    <div class="helper-text">Formato sugerido: XXXX/YYYY</div>
                </div>

                <div class="mn-form-group mn-col-4">
                    <label for="mn_data" class="required">Data</label>
                    <input type="date" id="mn_data" name="data" required value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="mn-form-group mn-col-4">
                    <label for="mn_prioridade">Prioridade</label>
                    <select id="mn_prioridade" name="prioridade">
                        <option value="Média" selected>🟡 Média</option>
                        <option value="Alta">🔴 Alta</option>
                        <option value="Baixa">🟢 Baixa</option>
                    </select>
                </div>

                <div class="mn-form-group mn-col-4">
                    <label for="mn_tipo" class="required">Tipo de Movimento</label>
                    <select id="mn_tipo" name="tipo" required>
                        <option value="Entrada">📥 Entrada</option>
                        <option value="Saída">📤 Saída</option>
                    </select>
                </div>

                <div class="mn-form-group mn-col-4">
                    <label for="mn_tipodoc" class="required">Tipo de Documento</label>
                    <select id="mn_tipodoc" name="tipo_documento" required>
                        <option value="">Selecione...</option>
                        <option value="Ofício">📄 Ofício</option>
                        <option value="Memorando">📝 Memorando</option>
                        <option value="Requerimento">📋 Requerimento</option>
                        <option value="Relatório">📊 Relatório</option>
                        <option value="Despacho">⚖️ Despacho</option>
                        <option value="Outro">❓ Outro</option>
                    </select>
                </div>

                <div class="mn-form-group mn-col-4" id="group_tipodoc_outro" data-show="false">
                    <label for="mn_tipodoc_outro">Especificar Tipo</label>
                    <input id="mn_tipodoc_outro" name="tipo_documento_outro" placeholder="Qual tipo de documento?">
                </div>

                <div class="mn-form-group mn-col-6" id="group_origem" data-show="true">
                    <label for="mn_origem">Origem</label>
                    <input id="mn_origem" name="origem" placeholder="Ex.: Gabinete, Secretaria, Cidadão...">
                    <div class="helper-text">De onde vem o documento</div>
                </div>

                <div class="mn-form-group mn-col-6" id="group_destino" data-show="false">
                    <label for="mn_destino">Destino</label>
                    <input id="mn_destino" name="destino" placeholder="Ex.: Secretaria X, Câmara, etc.">
                    <div class="helper-text">Para onde vai o documento</div>
                </div>

                <div class="mn-form-group mn-col-12">
                    <label for="mn_assunto" class="required">Assunto</label>
                    <input id="mn_assunto" name="assunto" required placeholder="Descreva brevemente o assunto">
                </div>

                <div class="mn-form-group mn-col-12">
                    <label for="mn_descricao">Descrição Detalhada</label>
                    <textarea id="mn_descricao" name="descricao" rows="4" placeholder="Detalhes adicionais sobre o protocolo..."></textarea>
                    <div class="helper-text">Informações complementares (opcional)</div>
                </div>

                <div class="mn-form-group mn-col-3">
                    <label for="mn_prazo">Prazo (dias)</label>
                    <input type="number" id="mn_prazo" name="prazo" min="0" step="1" placeholder="0" value="0">
                    <div class="helper-text">0 = sem prazo definido</div>
                </div>

                <div class="mn-form-group mn-col-3">
                    <label for="mn_status">Status Inicial</label>
                    <select id="mn_status" name="status">
                        <option value="Em tramitação" selected>🔄 Em tramitação</option>
                        <option value="Pendente">⏸️ Pendente</option>
                        <option value="Concluído">✅ Concluído</option>
                        <option value="Arquivado">📁 Arquivado</option>
                    </select>
                </div>

                <div class="mn-form-group mn-col-6">
                    <label for="mn_resp_email">E-mail do Responsável</label>
                    <input id="mn_resp_email" name="responsavel_email" type="email" placeholder="responsavel@prefeitura.gov.br">
                    <div class="helper-text">Para notificações e acompanhamento</div>
                </div>

                <div class="mn-form-group mn-col-6">
                    <label for="mn_drive">Link do Google Drive/Docs</label>
                    <input id="mn_drive" name="drive_link" type="url" placeholder="https://drive.google.com/...">
                    <div class="helper-text">Link para documentos online</div>
                </div>

                <div class="mn-form-group mn-col-6">
                    <label for="mn_anexo">Anexar Arquivo</label>
                    <div class="mn-file-input">
                        <input type="file" id="mn_anexo" name="anexo" data-max="<?php echo 10*1024*1024; ?>" accept=".pdf,.jpg,.jpeg,.png,.heic,.webp">
                        <div class="mn-file-label">
                            <span class="mn-file-icon">📎</span>
                            <span class="mn-file-text">Clique para selecionar arquivo (máx. 10MB)</span>
                        </div>
                    </div>
                    <div class="helper-text">PDF, JPG, PNG, HEIC ou WEBP</div>
                </div>
            </div>

            <div class="mn-actions">
                <button class="mn-btn mn-btn-main" type="submit" id="submit-btn">
                    💾 Salvar Protocolo
                </button>
            </div>
        </form>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('mn-protocolo-form');
            const tipoSelect = document.getElementById('mn_tipo');
            const tipodocSelect = document.getElementById('mn_tipodoc');
            const origemGroup = document.getElementById('group_origem');
            const destinoGroup = document.getElementById('group_destino');
            const outroGroup = document.getElementById('group_tipodoc_outro');
            const progressBar = null; // sem barra visual global

            function toggleOrigemDestino() {
                const isSaida = tipoSelect.value === 'Saída';
                origemGroup.setAttribute('data-show', (!isSaida).toString());
                destinoGroup.setAttribute('data-show', isSaida.toString());
                document.getElementById('mn_origem').required = !isSaida;
                document.getElementById('mn_destino').required = isSaida;
            }

            function toggleOutroTipo() {
                const isOutro = tipodocSelect.value === 'Outro';
                outroGroup.setAttribute('data-show', isOutro.toString());
                document.getElementById('mn_tipodoc_outro').required = isOutro;
            }

            function updateProgress() {
                if (!progressBar) return;
                const requiredFields = form.querySelectorAll('input[required], select[required]');
                let filled = 0;
                requiredFields.forEach(field => { if (field.value.trim() !== '') filled++; });
                const progress = (filled / requiredFields.length) * 100;
                progressBar.style.width = progress + '%';
            }

            form.addEventListener('submit', () => {
                const btn = document.getElementById('submit-btn');
                btn.classList.add('mn-btn-loading');
                btn.textContent = 'Salvando...';
            });

            tipoSelect.addEventListener('change', () => { toggleOrigemDestino(); updateProgress(); });
            tipodocSelect.addEventListener('change', () => { toggleOutroTipo(); updateProgress(); });
            form.querySelectorAll('input, select, textarea').forEach(f => {
                f.addEventListener('input', updateProgress);
                f.addEventListener('change', updateProgress);
            });

            toggleOrigemDestino();
            toggleOutroTipo();
            updateProgress();
        });

        function bindFancyFile(id){
  const input = document.getElementById(id);
  if(!input) return;
  const label = input.parentElement.querySelector('.mn-file-text');
  input.addEventListener('change', () => {
    const f = input.files[0];
    label.innerHTML = f
      ? '<span class="mn-file-selected">'+f.name+' ('+(f.size/1048576).toFixed(2)+'MB)</span>'
      : 'Clique para selecionar arquivo (máx. 10MB)';
  });
}
bindFancyFile('mn_anexo');     // Cadastro
bindFancyFile('mn_anexo_mov'); // Movimentar

        </script>
        <?php
        return ob_get_clean();
    }

    /** MOVIMENTAR (padronizado) */
    public static function render_movimentar_form(): string
{
    // (Se você já tem um enqueue global, pode manter) — deixa tudo bonitinho
    if (!wp_style_is('mn-forms-enhanced', 'enqueued')) {
        // CSS base do plugin
        $base_url = plugin_dir_url(dirname(__FILE__));
        wp_enqueue_style('mn-forms-enhanced', $base_url . 'assets/css/forms-enhanced.css', [], '1.2.0');
    }
    if (!wp_script_is('mn-forms-me', 'enqueued')) {
        wp_enqueue_script('mn-forms-me', plugin_dir_url(dirname(__FILE__)) . 'assets/js/forms-mov-editar.js', ['jquery'], '1.2.0', true);
    }

    $action  = esc_url(admin_url('admin-post.php'));
    $ajaxurl = esc_url(admin_url('admin-ajax.php'));
    $id      = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $numero  = $id ? get_the_title($id) : '';

    // Metadados para o painel de resumo
    $meta = [
        'data'            => $id ? (string) get_post_meta($id, 'data', true) : '',
        'tipo'            => $id ? (string) get_post_meta($id, 'tipo', true) : '',
        'tipo_documento'  => $id ? (string) get_post_meta($id, 'tipo_documento', true) : '',
        'origem'          => $id ? (string) get_post_meta($id, 'origem', true) : '',
        'destino'         => $id ? (string) get_post_meta($id, 'destino', true) : '',
        'status'          => $id ? (string) get_post_meta($id, 'status', true) : 'Em tramitação',
        'prioridade'      => $id ? (string) get_post_meta($id, 'prioridade', true) : 'Média',
        'prazo'           => $id ? (string) get_post_meta($id, 'prazo', true) : '0',
        'drive_link'      => $id ? (string) get_post_meta($id, 'drive_link', true) : '',
        'anexo_id'        => $id ? (int) get_post_meta($id, 'anexo_id', true) : 0,
    ];

    $permalink   = $id ? get_permalink($id) : '';
    $today       = current_time('Y-m-d');
    $check_nonce = wp_create_nonce('mn_checa_numero_nonce');

    // Timeline pronta (usa seu componente, se existir)
    $timeline_html = '';
    if ($id) {
        if (class_exists('\\ProtocoloMunicipal\\Timeline') && method_exists('\\ProtocoloMunicipal\\Timeline', 'render')) {
            // Usa o seu componente oficial
            $timeline_html = \ProtocoloMunicipal\Timeline::render($id, ['compact' => true, 'limit' => 25]);
        } else {
            // Fallback simples com base no meta 'historico'
            $history = get_post_meta($id, 'historico', true);
            $history = is_array($history) ? array_reverse($history) : [];
            ob_start();
            ?>
            <ul class="mn-timeline">
                <?php if ($history): foreach ($history as $h): ?>
                    <li class="mn-tl-item">
                        <div class="mn-tl-head">
                            <strong><?php echo esc_html($h['status'] ?? ''); ?></strong>
                            <span><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($h['quando'] ?? 'now'))); ?></span>
                        </div>
                        <div class="mn-tl-body"><?php echo esc_html($h['texto'] ?? ''); ?></div>
                        <?php if (!empty($h['usuario'])): ?>
                            <div class="mn-tl-foot">por <?php echo esc_html($h['usuario']); ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; else: ?>
                    <li class="mn-tl-item"><em style="color:#6b7280">Sem movimentações registradas.</em></li>
                <?php endif; ?>
            </ul>
            <?php
            $timeline_html = ob_get_clean();
        }
    }

    // Estilos mínimos para “ficar lindo” mesmo sem seu CSS global
    ob_start(); ?>
    <style>
    .mn-wrap{display:grid;gap:22px}
    .mn-2col{grid-template-columns:1.65fr 1fr}
    @media(max-width:980px){.mn-2col{grid-template-columns:1fr}}
    .mn-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;box-shadow:0 4px 20px rgba(0,0,0,.04)}
    .mn-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
    .mn-breadcrumb{color:#6b7280;font-size:14px}
    .mn-chip{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 10px;font-size:12px;border:1px solid #e5e7eb;background:#f9fafb}
    .mn-chip .dot{width:8px;height:8px;border-radius:999px;display:inline-block}
    .dot-green{background:#10b981}.dot-yellow{background:#f59e0b}.dot-red{background:#ef4444}.dot-blue{background:#3b82f6}
    .mn-title{font-weight:800;font-size:20px}
    .mn-sub{color:#6b7280;margin-top:2px}
    .mn-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}
    .mn-col-12{grid-column:span 12}.mn-col-6{grid-column:span 6}.mn-col-4{grid-column:span 4}.mn-col-3{grid-column:span 3}
    @media(max-width:680px){.mn-col-12,.mn-col-6,.mn-col-4,.mn-col-3{grid-column:span 12}}
    .mn-form-group label{display:block;font-weight:600;margin:6px 0}
    .mn-input,.mn-select,textarea{width:100%;border:1px solid #d1d5db;border-radius:12px;padding:10px 12px;outline:none}
    .mn-input:focus,.mn-select:focus,textarea:focus{border-color:#6366f1;box-shadow:0 0 0 4px rgba(99,102,241,.15)}
    .mn-help{color:#6b7280;font-size:12px;margin-top:6px}
    .mn-actions{display:flex;gap:12px;margin-top:12px}
    .mn-btn{border-radius:12px;padding:10px 14px;border:1px solid transparent;cursor:pointer}
    .mn-btn.primary{background:#2563eb;color:#fff}.mn-btn.primary[disabled]{opacity:.7;cursor:not-allowed}
    .mn-btn.ghost{background:#fff;border-color:#e5e7eb}
    .mn-side .row{display:flex;justify-content:space-between;padding:8px 0;border-top:1px dashed #e5e7eb}
    .mn-side .row:first-child{border-top:0}
    .mn-side .k{color:#6b7280}
    .mn-timeline{list-style:none;padding:0;margin:0}
    .mn-tl-item{padding:10px 0;border-top:1px dashed #e5e7eb}
    .mn-tl-item:first-child{border-top:0}
    .mn-tl-head{display:flex;justify-content:space-between}
    .mn-toast{position:fixed;right:16px;bottom:16px;background:#111827;color:#fff;padding:10px 14px;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,.2);opacity:0;transform:translateY(12px);transition:.25s}
    .mn-toast.show{opacity:1;transform:translateY(0)}
    .mn-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid #e5e7eb;background:#fff}
    .mn-file{display:flex;align-items:center;gap:8px}
    .counter{font-size:12px;color:#6b7280;text-align:right;margin-top:4px}
    .sticky{position:sticky;top:16px}
    </style>
    <div class="mn-wrap<div class="mn-alignfull">
  <div class="mn-wrap mn-two-col">
      ...
  </div>
</div>


        <!-- COLUNA ESQUERDA (FORM) -->
        <div class="mn-card">
            <div class="mn-head">
                <div>
                    <div class="mn-breadcrumb">Protocolos <?= $numero ? ' / ' . esc_html($numero) : ''; ?> / Movimentar</div>
                    <div class="mn-title">Movimentar Protocolo</div>
                    <div class="mn-sub">Registre a movimentação e atualize o status. Salva via AJAX.</div>
                </div>
                <div class="mn-chip" title="Status atual">
                    <?php
                    $dot = 'dot-blue';
                    if ($meta['status']==='Concluído') $dot='dot-green';
                    elseif ($meta['status']==='Pendente') $dot='dot-yellow';
                    elseif ($meta['status']==='Arquivado') $dot='dot-red';
                    ?>
                    <span class="dot <?= esc_attr($dot); ?>"></span>
                    <span><?= esc_html($meta['status']); ?></span>
                </div>
            </div>

            <form id="mn-mov-form" class="mn-form" action="<?php echo $action; ?>" method="post" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="action" value="mn_save_movimentacao">
                <?php wp_nonce_field('mn_save_movimentacao_action','mn_mov_nonce'); ?>
                <?php if ($id && $numero): ?>
                    <input type="hidden" name="numero" value="<?php echo esc_attr($numero); ?>">
                <?php endif; ?>

                <div class="mn-grid">
                    <div class="mn-form-group mn-col-6">
                        <label>Nº Protocolo</label>
                        <input class="mn-input" value="<?php echo esc_attr($numero ?: '—'); ?>" disabled>
                    </div>

                    <div class="mn-form-group mn-col-3">
                        <label for="mn_data_mov">Data</label>
                        <input class="mn-input" type="date" id="mn_data_mov" name="data_mov" required value="<?php echo esc_attr($today); ?>">
                    </div>

                    <div class="mn-form-group mn-col-3">
                        <label for="mn_status_mov">Novo Status</label>
                        <select class="mn-select" id="mn_status_mov" name="status" required>
                            <?php
                            $st = ['Em tramitação','Concluído','Arquivado','Pendente'];
                            foreach ($st as $s) {
                                echo '<option value="'.esc_attr($s).'">'.esc_html($s).'</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="mn-form-group mn-col-6">
                        <label for="mn_origem_mov">Origem (opcional)</label>
                        <input class="mn-input" id="mn_origem_mov" name="origem" placeholder="Ex.: Setor A">
                    </div>

                    <div class="mn-form-group mn-col-6">
                        <label for="mn_destino_mov">Destino</label>
                        <input class="mn-input" id="mn_destino_mov" name="destino" placeholder="Ex.: Setor B" required>
                    </div>

                    <div class="mn-form-group mn-col-12">
                        <label for="mn_obs">Descrição / Observação</label>
                        <textarea class="mn-input" id="mn_obs" name="observacao" rows="4" maxlength="800" placeholder="O que foi feito? Para onde seguiu? Quem recebeu?" required></textarea>
                        <div class="counter"><span id="mn_obs_count">0</span>/800</div>
                    </div>

                    <div class="mn-form-group mn-col-6">
                        <label for="mn_anexo_mov">Anexar Arquivo <span class="mn-badge">máx. 10MB</span></label>
                        <div class="mn-file">
                            <input class="mn-input" type="file" id="mn_anexo_mov" name="anexo"
                                   data-max="<?php echo 10*1024*1024; ?>"
                                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        </div>
                        <div class="mn-help">PDF, JPG, PNG, DOC ou DOCX</div>
                    </div>

                    <div class="mn-form-group mn-col-6">
                        <label for="mn_drive_mov">Link Drive (opcional)</label>
                        <input class="mn-input" type="url" id="mn_drive_mov" name="link_drive" placeholder="https://drive.google.com/...">
                        <div class="mn-help">Adicione um link para o documento no Drive se desejar.</div>
                    </div>
                </div>

                <div class="mn-actions">
                    <button class="mn-btn primary" type="submit" id="mn-submit" <?php echo ($id && $numero) ? '' : 'disabled'; ?>>
                        <span class="txt">Salvar movimentação</span>
                        <span class="spinner" style="display:none;margin-left:8px">⏳</span>
                    </button>
                    <?php if ($permalink): ?>
                        <a class="mn-btn ghost" href="<?php echo esc_url($permalink); ?>">Ver detalhes</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- COLUNA DIREITA (RESUMO + TIMELINE) -->
        <aside class="mn-card mn-side sticky">
            <div class="mn-head" style="margin-bottom:8px">
                <div class="mn-title" style="font-size:18px">Resumo</div>
            </div>

            <div class="row"><span class="k">Nº</span><span class="v"><strong><?php echo $numero ? esc_html($numero) : '—'; ?></strong></span></div>
            <div class="row"><span class="k">Tipo</span><span class="v"><?php echo esc_html($meta['tipo'] ?: '—'); ?></span></div>
            <div class="row"><span class="k">Documento</span><span class="v"><?php echo esc_html($meta['tipo_documento'] ?: '—'); ?></span></div>
            <div class="row"><span class="k">Prioridade</span><span class="v"><?php echo esc_html($meta['prioridade'] ?: '—'); ?></span></div>
            <div class="row"><span class="k">Prazo (dias)</span><span class="v"><?php echo esc_html((string)$meta['prazo']); ?></span></div>
            <?php if (!empty($meta['drive_link'])): ?>
                <div class="row"><span class="k">Drive</span><span class="v"><a href="<?php echo esc_url($meta['drive_link']); ?>" target="_blank" rel="noopener">Abrir</a></span></div>
            <?php endif; ?>
            <?php if (!empty($meta['anexo_id'])):
                $url = wp_get_attachment_url($meta['anexo_id']);
                if ($url): ?>
                <div class="row"><span class="k">Anexo</span><span class="v"><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">Baixar</a></span></div>
            <?php endif; endif; ?>

            <div style="margin-top:16px">
                <div class="mn-title" style="font-size:16px;margin-bottom:6px">Timeline</div>
                <div id="mn-timeline"><?php echo $timeline_html; ?></div>
            </div>
        </aside>
    </div>

    <!-- Toast -->
    <div id="mn-toast" class="mn-toast" role="status" aria-live="polite"></div>

    <script>
    (function(){
        const form    = document.getElementById('mn-mov-form');
        const btn     = document.getElementById('mn-submit');
        const spinner = btn ? btn.querySelector('.spinner') : null;
        const txt     = btn ? btn.querySelector('.txt') : null;
        const toast   = document.getElementById('mn-toast');
        const obs     = document.getElementById('mn_obs');
        const count   = document.getElementById('mn_obs_count');
        const timeline= document.getElementById('mn-timeline');
        const ajaxUrl = "<?php echo $ajaxurl; ?>";

        if (obs && count){
            const upd = () => { count.textContent = String(obs.value.length); };
            obs.addEventListener('input', upd); upd();
        }

        // Validação de tamanho do arquivo
        const file = document.getElementById('mn_anexo_mov');
        if (file) {
            file.addEventListener('change', function(){
                const max = parseInt(this.dataset.max || '0', 10);
                const f = this.files && this.files[0] ? this.files[0] : null;
                if (max && f && f.size > max){
                    this.value = '';
                    showToast('Arquivo maior que 10MB.', true);
                }
            });
        }

        function showToast(msg, isErr){
            if (!toast) return;
            toast.textContent = msg;
            toast.style.background = isErr ? '#b91c1c' : '#111827';
            toast.classList.add('show');
            setTimeout(()=>toast.classList.remove('show'), 2800);
        }

        if (form){
            form.addEventListener('submit', function(e){
                e.preventDefault();

                // Envia por AJAX para usar o handler wp_ajax_mn_save_movimentacao
                const fd = new FormData(form);
                // Garante o endpoint AJAX
                fd.set('action', 'mn_save_movimentacao');

                if (btn){ btn.disabled = true; if(spinner) spinner.style.display='inline'; if(txt) txt.textContent='Salvando...'; }

                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(j => {
                        if (j && j.success){
                            showToast('Movimentação salva!', false);

                            // Redirecionamento (Actions já devolve "redirect" em AJAX)
                            if (j.data && j.data.redirect){
                                setTimeout(()=>{ window.location.href = j.data.redirect; }, 800);
                                return;
                            }

                            // Caso não haja redirect, tenta atualizar a timeline via HTML parcial (se expuser um endpoint no futuro).
                            // Por ora, recarrega a página como fallback.
                            setTimeout(()=>{ window.location.reload(); }, 800);
                        } else {
                            const msg = (j && j.data && j.data.message) ? j.data.message : 'Erro ao salvar movimentação.';
                            showToast(msg, true);
                        }
                    })
                    .catch(() => showToast('Falha de rede ao salvar.', true))
                    .finally(()=>{
                        if (btn){ btn.disabled = false; if(spinner) spinner.style.display='none'; if(txt) txt.textContent='Salvar movimentação'; }
                    });
            }, { passive: false });
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}


    /** EDITAR (padronizado) */
    public static function render_editar_form()
    {
        $action  = esc_url(admin_url('admin-post.php'));
        $id      = isset($_GET['id']) ? intval($_GET['id']) : 0;

        $numero   = $id ? get_the_title($id) : '';
        $data     = $id ? (string) get_post_meta($id,'data',true) : '';
        $tipo     = $id ? (string) get_post_meta($id,'tipo',true) : '';
        $tipodoc  = $id ? (string) get_post_meta($id,'tipo_documento',true) : '';
        $origem   = $id ? (string) get_post_meta($id,'origem',true) : '';
        $destino  = $id ? (string) get_post_meta($id,'destino',true) : '';
        $assunto  = $id ? (string) get_post_meta($id,'assunto',true) : '';
        $desc     = $id ? (string) get_post_meta($id,'descricao',true) : '';
        $prior    = $id ? (string) get_post_meta($id,'prioridade',true) : 'Média';
        $prazo    = $id ? (int)    get_post_meta($id,'prazo',true) : 0;
        $status   = $id ? (string) get_post_meta($id,'status',true) : 'Em tramitação';
        $email    = $id ? (string) get_post_meta($id,'responsavel_email',true) : '';
        $drive    = $id ? (string) get_post_meta($id,'drive_link',true) : '';

        ob_start(); ?>
        <form class="mn-form" action="<?php echo $action; ?>" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="mn_save_editar">
            <?php wp_nonce_field('mn_save_editar_action','mn_editar_nonce'); ?>
            <input type="hidden" name="protocolo_id" value="<?php echo (int)$id; ?>">

            <div class="mn-section">✏️ Editar Protocolo</div>

            <div class="mn-grid">
                <div class="mn-form-group mn-col-4">
                    <label for="mn_numero_edit">Nº Protocolo</label>
                    <input id="mn_numero_edit" name="numero" value="<?php echo esc_attr($numero); ?>" required>
                </div>

                <div class="mn-form-group mn-col-4">
                    <label for="mn_data_edit">Data</label>
                    <input type="date" id="mn_data_edit" name="data" value="<?php echo esc_attr($data); ?>">
                </div>

                <div class="mn-form-group mn-col-4">
                    <label for="mn_prioridade_edit">Prioridade</label>
                    <select id="mn_prioridade_edit" name="prioridade">
                        <option <?php selected($prior,'Média'); ?>>Média</option>
                        <option <?php selected($prior,'Alta'); ?>>Alta</option>
                        <option <?php selected($prior,'Baixa'); ?>>Baixa</option>
                    </select>
                </div>

                <div class="mn-form-group mn-col-4">
                    <label for="mn_tipo_edit">Tipo</label>
                    <select id="mn_tipo_edit" name="tipo">
                        <option <?php selected($tipo,'Entrada'); ?>>Entrada</option>
                        <option <?php selected($tipo,'Saída'); ?>>Saída</option>
                    </select>
                </div>

                <div class="mn-form-group mn-col-4">
                    <label for="mn_tipodoc_edit">Tipo de Documento</label>
                    <select id="mn_tipodoc_edit" name="tipo_documento">
                        <?php
                        $opts = ['Ofício','Memorando','Requerimento','Relatório','Despacho','Outro'];
                        foreach ($opts as $o) echo '<option '.selected($tipodoc,$o,false).'>'.$o.'</option>';
                        ?>
                    </select>
                </div>

                <div class="mn-form-group mn-col-4">
                    <label for="mn_prazo_edit">Prazo (dias)</label>
                    <input type="number" id="mn_prazo_edit" name="prazo" min="0" step="1" value="<?php echo esc_attr($prazo); ?>">
                </div>

                <div class="mn-form-group mn-col-6">
                    <label for="mn_origem_edit">Origem</label>
                    <input id="mn_origem_edit" name="origem" value="<?php echo esc_attr($origem); ?>">
                </div>

                <div class="mn-form-group mn-col-6">
                    <label for="mn_destino_edit">Destino</label>
                    <input id="mn_destino_edit" name="destino" value="<?php echo esc_attr($destino); ?>">
                </div>

                <div class="mn-form-group mn-col-12">
                    <label for="mn_assunto_edit">Assunto</label>
                    <input id="mn_assunto_edit" name="assunto" value="<?php echo esc_attr($assunto); ?>">
                </div>

                <div class="mn-form-group mn-col-12">
                    <label for="mn_desc_edit">Descrição</label>
                    <textarea id="mn_desc_edit" name="descricao" rows="4"><?php echo esc_textarea($desc); ?></textarea>
                </div>

                <div class="mn-form-group mn-col-6">
                    <label for="mn_status_edit">Status</label>
                    <select id="mn_status_edit" name="status">
                        <?php
                        $st = ['Em tramitação','Concluído','Arquivado','Pendente'];
                        foreach ($st as $s) echo '<option '.selected($status,$s,false).'>'.$s.'</option>';
                        ?>
                    </select>
                </div>

                <div class="mn-form-group mn-col-6">
                    <label for="mn_drive_edit">Link Drive</label>
                    <input type="url" id="mn_drive_edit" name="drive_link" value="<?php echo esc_attr($drive); ?>">
                </div>

                <div class="mn-form-group mn-col-6">
                    <label for="mn_email_edit">E-mail do responsável</label>
                    <input type="email" id="mn_email_edit" name="responsavel_email" value="<?php echo esc_attr($email); ?>">
                </div>

                <div class="mn-form-group mn-col-6">
                    <label for="mn_anexo_edit">Anexo</label>
                    <div class="mn-file-input">
                        <input type="file" id="mn_anexo_edit" name="anexo"
                               data-max="<?php echo 10*1024*1024; ?>"
                               accept=".pdf,.jpg,.jpeg,.png,.heic,.webp">
                        <div class="mn-file-label">
                            <span class="mn-file-icon">📎</span>
                            <span class="mn-file-text">Clique para selecionar arquivo (máx. 10MB)</span>
                        </div>
                    </div>
                    <div class="helper-text">PDF, JPG, PNG, HEIC ou WEBP</div>
                </div>
            </div>

            <div class="mn-actions">
                <button class="mn-btn mn-btn-main" type="submit">Salvar Edição</button>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /** CONSULTA / VISUALIZAÇÃO (sem CSS inline) */
    public static function render_visualizar_protocolo()
    {
        $id      = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $numeroQ = isset($_GET['numero']) ? sanitize_text_field(wp_unslash($_GET['numero'])) : '';
        if (!$id && $numeroQ !== '') {
            $p = get_page_by_title($numeroQ, OBJECT, 'protocolo');
            if ($p instanceof \WP_Post) $id = (int) $p->ID;
        }

        // Formulário simples quando não houver ID/numero
        if (!$id) {
            ob_start(); ?>
            <div class="mn-form">
                <div class="mn-section">Visualização detalhada</div>
                <form method="get" class="mn-grid">
                    <div class="mn-form-group mn-col-6">
                        <label for="mn_numero_consulta">Nº Protocolo</label>
                        <input id="mn_numero_consulta" name="numero" value="<?php echo esc_attr($numeroQ); ?>">
                    </div>
                    <div class="mn-actions mn-col-12">
                        <button class="mn-btn mn-btn-main" type="submit">Consultar</button>
                    </div>
                </form>
            </div>
            <?php return ob_get_clean();
        }

        // Dados
        $numero      = get_the_title($id);
        $data        = (string) get_post_meta($id, 'data', true);
        $tipo        = (string) get_post_meta($id, 'tipo', true);
        $tipo_doc    = (string) get_post_meta($id, 'tipo_documento', true);
        $origem      = (string) get_post_meta($id, 'origem', true);
        $destino     = (string) get_post_meta($id, 'destino', true);
        $assunto     = (string) get_post_meta($id, 'assunto', true);
        $desc        = (string) get_post_meta($id, 'descricao', true);
        $prazo       = (int)    get_post_meta($id, 'prazo', true);
        $status_doc  = (string) get_post_meta($id, 'status', true);
        $resp        = (string) get_post_meta($id, 'responsavel', true);
        $resp_email  = (string) get_post_meta($id, 'responsavel_email', true);
        $drive_link  = (string) get_post_meta($id, 'drive_link', true);
        $anexo_id    = (int)    get_post_meta($id, 'anexo_id', true);
        $anexo_url   = $anexo_id ? wp_get_attachment_url($anexo_id) : '';

        // Prazo / atraso
        $atrasado = false; $limite_fmt = '';
        try {
            if ($data && $prazo > 0) {
                $lim = (new \DateTimeImmutable($data))->modify('+' . $prazo . ' days');
                $limite_fmt = $lim->format('d/m/Y');
                $atrasado = ($status_doc !== 'Concluído') && (\current_time('Y-m-d') > $lim->format('Y-m-d'));
            }
        } catch (\Exception $e) {}

        $status_class =
            $status_doc === 'Concluído' ? 'concluido' :
            ($status_doc === 'Arquivado' ? 'arquivado' :
            ($atrasado ? 'atrasado' : ''));

        // Ações
        $pLista     = get_page_by_path('lista-de-protocolos'); if(!$pLista) $pLista = get_page_by_path('lista');
        $url_lista  = $pLista ? get_permalink($pLista) : '';
        $pEditar    = get_page_by_path('editar-protocolo');    if(!$pEditar) $pEditar = get_page_by_path('editar');
        $url_editar = $pEditar ? add_query_arg(['id'=>$id], get_permalink($pEditar)) : '';
        $pMov       = get_page_by_path('movimentar-protocolo');if(!$pMov) $pMov = get_page_by_path('movimentar');
        $url_mov    = $pMov ? add_query_arg(['id'=>$id], get_permalink($pMov)) : '';

        ob_start(); ?>
        <div class="mn-v-card">
            <div class="mn-v-top">
                <div>
                    <div class="mn-v-num"><?php echo esc_html($numero); ?></div>
                    <div>
                        <?php if ($tipo_doc): ?><span class="mn-pill"><?php echo esc_html($tipo_doc); ?></span><?php endif; ?>
                        <?php if ($tipo):     ?><span class="mn-pill outline"><?php echo esc_html($tipo); ?></span><?php endif; ?>
                    </div>
                </div>
                <span class="mn-status <?php echo esc_attr($status_class); ?>">
                    <?php echo esc_html($status_doc ?: '—'); ?>
                </span>
            </div>

            <?php if ($assunto): ?>
                <div class="mn-assunto">Assunto: <?php echo esc_html($assunto); ?></div>
            <?php endif; ?>

            <div class="mn-kv">
                <div class="row"><span class="mn-k">Data</span><span class="mn-v"><?php echo esc_html($data ?: '—'); ?></span></div>
                <?php if ($prazo > 0): ?>
                    <div class="row"><span class="mn-k">Prazo</span>
                        <span class="mn-v">
                            <?php echo $limite_fmt ? esc_html($limite_fmt) : '—'; ?>
                            <?php echo $atrasado ? ' — <b style="color:#b00">ATRASADO</b>' : ''; ?>
                        </span>
                    </div>
                <?php endif; ?>
                <div class="row"><span class="mn-k">Origem</span><span class="mn-v"><?php echo esc_html($origem ?: '—'); ?></span></div>
                <div class="row"><span class="mn-k">Destino</span><span class="mn-v"><?php echo esc_html($destino ?: '—'); ?></span></div>
                <div class="row"><span class="mn-k">Responsável</span><span class="mn-v"><?php echo esc_html($resp ?: '—'); ?></span></div>
                <div class="row"><span class="mn-k">E-mail</span><span class="mn-v"><?php echo esc_html($resp_email ?: '—'); ?></span></div>
            </div>

            <?php if ($desc): ?>
                <div class="mn-desc"><b style="color:#2a387b">Descrição:</b><br><?php echo esc_html($desc); ?></div>
            <?php endif; ?>

            <div class="mn-actions">
                <?php if ($url_mov):    ?><a class="mn-btn-action" href="<?php echo esc_url($url_mov); ?>">🔁 Movimentar</a><?php endif; ?>
                <?php if ($url_editar): ?><a class="mn-btn-action" href="<?php echo esc_url($url_editar); ?>">✏️ Editar</a><?php endif; ?>
                <?php if ($anexo_url):  ?><a class="mn-btn-action" href="<?php echo esc_url($anexo_url); ?>" target="_blank" rel="noopener">📎 Anexo</a><?php endif; ?>
                <?php if ($drive_link): ?><a class="mn-btn-action" href="<?php echo esc_url($drive_link); ?>" target="_blank" rel="noopener nofollow">🗂️ Drive</a><?php endif; ?>
                <?php if ($url_lista):  ?><a class="mn-btn-action" href="<?php echo esc_url($url_lista); ?>">⬅️ Voltar à lista</a><?php endif; ?>
            </div>
        </div>

        <div class="mn-sec-title"><span class="mn-sec-pill">Timeline</span></div>
        <?php
        if (class_exists('\\ProtocoloMunicipal\\Timeline')) {
            echo \ProtocoloMunicipal\Timeline::render($id);
        }

        return ob_get_clean();
    }
}
