<?php
namespace ProtocoloMunicipal;
if (!defined('ABSPATH')) exit;

class Timeline {
  public static function render($protocolo_id, $limit = 50) {
    $protocolo_id = intval($protocolo_id);
    $limit = max(1, intval($limit));
    if ($protocolo_id <= 0) return '';

    $items = [];
    $gab_label = (string) apply_filters('pmn/gabinete_label', 'Gab. Ver. Marcelo Nunes');

    // Abertura
    $data_ab = (string) get_post_meta($protocolo_id, 'data', true);
    $ts_ab   = self::to_ts($data_ab) ?: (int) get_post_time('U', true, $protocolo_id);

    $tipo    = (string) get_post_meta($protocolo_id,'tipo',true);
    $orig_m  = (string) get_post_meta($protocolo_id,'origem',true);
    $dest_m  = (string) get_post_meta($protocolo_id,'destino',true);
    if ($tipo === 'Entrada' && $dest_m === '') $dest_m = $gab_label;
    if ($tipo === 'Saída'   && $orig_m === '') $orig_m = $gab_label;

    $anexo_open_id = (int) get_post_meta($protocolo_id,'anexo_id',true);
    $items[] = [
      'ts'          => $ts_ab,
      'titulo'      => 'Abertura do protocolo',
      'status'      => (string) get_post_meta($protocolo_id,'status',true),
      'responsavel' => (string) get_post_meta($protocolo_id,'responsavel',true),
      'origem'      => $orig_m,
      'destino'     => $dest_m,
      'descricao'   => (string) get_post_meta($protocolo_id,'assunto',true),
      'anexo_url'   => $anexo_open_id ? wp_get_attachment_url($anexo_open_id) : '',
      'drive_link'  => (string) get_post_meta($protocolo_id,'drive_link',true),
    ];

    // Movimentações (meta array)
    $movs = get_post_meta($protocolo_id, 'movimentacoes', true);
    if (is_array($movs)) {
      foreach ($movs as $m) {
        $ts = self::to_ts($m['data'] ?? '') ?: self::to_ts($m['created_at'] ?? '') ?: time();
        $items[] = [
          'ts'          => $ts,
          'titulo'      => 'Movimentação',
          'status'      => (string)($m['status'] ?? ''),
          'responsavel' => (string)($m['responsavel'] ?? ''),
          'origem'      => (string)($m['origem'] ?? ''),
          'destino'     => (string)($m['destino'] ?? ''),
          'descricao'   => (string)($m['descricao'] ?? ''),
          'anexo_url'   => !empty($m['anexo_id']) ? wp_get_attachment_url((int)$m['anexo_id']) : '',
          'drive_link'  => (string)($m['drive_link'] ?? ''),
        ];
      }
    }

    // Filhos
    $children = get_children([
      'post_parent' => $protocolo_id,
      'post_type'   => 'any',
      'numberposts' => -1,
      'post_status' => 'any',
      'orderby'     => 'date',
      'order'       => 'DESC',
    ]);
    foreach ($children as $c) {
      $desc = (string) get_post_meta($c->ID,'descricao',true);
      $stat = (string) get_post_meta($c->ID,'status',true);
      $dest = (string) get_post_meta($c->ID,'destino',true);
      $orig = (string) get_post_meta($c->ID,'origem',true);
      $resp = (string) get_post_meta($c->ID,'responsavel',true);
      if ($desc==='' && $stat==='' && $dest==='' && $orig==='' && $resp==='') continue;

      $data     = (string) get_post_meta($c->ID,'data',true);
      $anexo_id = (int) get_post_meta($c->ID,'mov_anexo',true);
      $items[] = [
        'ts'          => self::to_ts($data) ?: strtotime($c->post_date_gmt.' UTC'),
        'titulo'      => 'Movimentação',
        'status'      => $stat,
        'responsavel' => $resp,
        'origem'      => $orig,
        'destino'     => $dest,
        'descricao'   => $desc,
        'anexo_url'   => $anexo_id ? wp_get_attachment_url($anexo_id) : '',
        'drive_link'  => (string) get_post_meta($c->ID,'mov_drive',true),
      ];
    }

    // Relacionados por meta protocolo_id
    $rel = get_posts([
      'post_type'   => 'any',
      'numberposts' => -1,
      'post_status' => 'any',
      'meta_query'  => [['key'=>'protocolo_id','value'=>$protocolo_id]],
      'orderby'     => 'date',
      'order'       => 'DESC',
    ]);
    foreach ($rel as $c) {
      $desc = (string) get_post_meta($c->ID,'descricao',true);
      $stat = (string) get_post_meta($c->ID,'status',true);
      $dest = (string) get_post_meta($c->ID,'destino',true);
      $orig = (string) get_post_meta($c->ID,'origem',true);
      $resp = (string) get_post_meta($c->ID,'responsavel',true);
      if ($desc==='' && $stat==='' && $dest==='' && $orig==='' && $resp==='') continue;

      $data     = (string) get_post_meta($c->ID,'data',true);
      $anexo_id = (int) get_post_meta($c->ID,'mov_anexo',true);
      $items[] = [
        'ts'          => self::to_ts($data) ?: strtotime($c->post_date_gmt.' UTC'),
        'titulo'      => 'Movimentação',
        'status'      => $stat,
        'responsavel' => $resp,
        'origem'      => $orig,
        'destino'     => $dest,
        'descricao'   => $desc,
        'anexo_url'   => $anexo_id ? wp_get_attachment_url($anexo_id) : '',
        'drive_link'  => (string) get_post_meta($c->ID,'mov_drive',true),
      ];
    }

    // Ordena + limita
    usort($items, fn($a,$b) => ($a['ts'] <=> $b['ts']) * -1);
    $items = array_slice($items, 0, $limit);
    if (!$items) return '';

    ob_start(); ?>
    <style>
      .pmn-tl{position:relative;margin:16px 0;padding-left:20px}
      .pmn-tl::before{content:"";position:absolute;left:8px;top:0;bottom:0;width:2px;background:#e3eaf9}
      .pmn-tl-item{position:relative;margin:0 0 12px 0;padding-left:16px}
      .pmn-tl-item::before{content:"";position:absolute;left:-3px;top:6px;width:10px;height:10px;border-radius:50%;background:#0854ba;box-shadow:0 0 0 3px #eaf1fd}
      .pmn-tl-head{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
      .pmn-tl-date{font-weight:700;color:#2a387b}
      .pmn-tl-badge{border-radius:16px;background:#eef3ff;border:1px solid #d9e2ff;color:#1757b6;font-weight:700;padding:3px 10px;font-size:.85em}
      .pmn-tl-badge.ok{background:#c8fae6;border-color:#9ee3c8;color:#0f6f4f}
      .pmn-tl-badge.arch{background:#fdeaea;border-color:#f1c1c1;color:#a32222}
      .pmn-tl-badge.wait{background:#fff6e6;border-color:#ffe3a8;color:#8a5b00}
      .pmn-tl-flow{margin:4px 0 0 0;color:#1c255a}
      .pmn-tl-flow .lab{font-weight:700;color:#2a387b}
      .pmn-tl-text{margin:6px 0 2px 0}
      .pmn-tl-actions a{display:inline-block;margin-right:8px;text-decoration:none}
    </style>
    <div class="pmn-tl" aria-label="Timeline de movimentações">
      <?php foreach ($items as $it): 
        $status = $it['status'] ?? '';
        $cls = '';
        if ($status === 'Concluído')        $cls = 'ok';
        elseif ($status === 'Arquivado')    $cls = 'arch';
        elseif ($status === 'Pendente')     $cls = 'wait';
      ?>
        <div class="pmn-tl-item">
          <div class="pmn-tl-head">
            <div class="pmn-tl-date"><?php echo esc_html( date_i18n('d/m/Y', (int)$it['ts']) ); ?></div>
            <?php if(!empty($status)): ?>
              <span class="pmn-tl-badge <?php echo esc_attr($cls); ?>"><?php echo esc_html($status); ?></span>
            <?php endif; ?>
            <?php if(!empty($it['responsavel'])): ?>
              <span class="pmn-tl-badge">@<?php echo esc_html($it['responsavel']); ?></span>
            <?php endif; ?>
          </div>
          <?php if(!empty($it['origem']) || !empty($it['destino'])): ?>
            <div class="pmn-tl-flow">
              <span class="lab">Origem:</span> <?php echo esc_html($it['origem'] ?: '—'); ?>
              &nbsp;→&nbsp;
              <span class="lab">Destino:</span> <?php echo esc_html($it['destino'] ?: '—'); ?>
            </div>
          <?php endif; ?>
          <?php if(!empty($it['descricao'])): ?>
            <div class="pmn-tl-text"><?php echo esc_html($it['descricao']); ?></div>
          <?php endif; ?>
          <div class="pmn-tl-actions">
            <?php if(!empty($it['anexo_url'])): ?>
              <a href="<?php echo esc_url($it['anexo_url']); ?>" target="_blank" rel="noopener">📎 Anexo</a>
            <?php endif; ?>
            <?php if(!empty($it['drive_link'])): ?>
              <a href="<?php echo esc_url($it['drive_link']); ?>" target="_blank" rel="noopener nofollow">🗂️ Drive</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
  }

  private static function to_ts($ymd) {
    if (!$ymd) return 0;
    try { return (new \DateTimeImmutable($ymd))->getTimestamp(); }
    catch (\Exception $e) { return 0; }
  }
}
