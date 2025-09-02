<?php
namespace ProtocoloMunicipal;
if (!defined('ABSPATH')) exit;

class Settings {
  const OPT = 'pmn_settings';

  public static function boot(): void {
    add_action('admin_menu', [__CLASS__,'menu']);
    add_action('admin_init', [__CLASS__,'register']);
    // Devolve o label configurado para toda a base:
    add_filter('pmn/gabinete_label', [__CLASS__,'gabinete_label']);
  }

  public static function gabinete_label($default = 'Gab. Ver. Marcelo Nunes'): string {
    $opt = get_option(self::OPT, []);
    $label = isset($opt['gabinete_label']) && $opt['gabinete_label'] !== '' ? $opt['gabinete_label'] : $default;
    return (string) $label;
  }

  public static function menu(): void {
    add_options_page(
      'Protocolo Municipal',
      'Protocolo Municipal',
      'manage_options',
      'pmn-settings',
      [__CLASS__,'page']
    );
  }

  public static function register(): void {
    register_setting(self::OPT, self::OPT, [
      'type' => 'array',
      'sanitize_callback' => function($v){
        return ['gabinete_label' => sanitize_text_field($v['gabinete_label'] ?? '')];
      },
    ]);
    add_settings_section('pmn_main', 'Configurações', '__return_false', self::OPT);
    add_settings_field(
      'gabinete_label',
      'Nome do Gabinete padrão',
      [__CLASS__,'field_gab'],
      self::OPT,
      'pmn_main'
    );
  }

  public static function field_gab(): void {
    $opt = get_option(self::OPT, []);
    $val = esc_attr($opt['gabinete_label'] ?? 'Gab. Ver. Marcelo Nunes');
    echo '<input type="text" name="'.self::OPT.'[gabinete_label]" value="'.$val.'" class="regular-text" />';
    echo '<p class="description">Usado automaticamente: Entrada ⇒ destino; Saída ⇒ origem.</p>';
  }

  public static function page(): void {
    echo '<div class="wrap"><h1>Protocolo Municipal</h1><form method="post" action="options.php">';
    settings_fields(self::OPT);
    do_settings_sections(self::OPT);
    submit_button();
    echo '</form></div>';
  }
}
Settings::boot();
