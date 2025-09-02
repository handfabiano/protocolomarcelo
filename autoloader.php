<?php
/**
 * PSR-4 Autoloader para o plugin Protocolo Municipal.
 * Salve este arquivo como autoloader.php na raiz do plugin.
 */

// Impede acesso direto
if (!defined('ABSPATH')) { exit; }

if (!defined('PMN_AUTOLOADER_REGISTERED')) {
    define('PMN_AUTOLOADER_REGISTERED', true);

    spl_autoload_register(function (string $class) {
        // Namespace raiz do projeto
        $prefix   = 'ProtocoloMunicipal\\';
        $base_dir = __DIR__ . '/src/'; // diretório base para as classes PSR-4

       // ---- Mapeamentos pontuais (arquivos fora do padrão estrito)
static $alias_map = [
    'ProtocoloMunicipal\\ListTable' => __DIR__ . '/src/Lista.php',
    'ProtocoloMunicipal\\Lista'     => __DIR__ . '/src/Lista.php',
    'ProtocoloMunicipal\\Report'    => __DIR__ . '/src/Report.php',
];

        // Verifica se a classe usa o namespace esperado
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            // não é nossa; deixa outros autoloaders tentarem
            return;
        }

        // Obtém a parte relativa do nome da classe (sem o prefixo do namespace)
        $relative_class = substr($class, $len);

        // Converte separadores de namespace em separadores de diretório e adiciona .php
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        $file = preg_replace('#/+#', '/', $file); // normaliza barras

        // Carrega o arquivo se existir
        if (is_file($file)) {
            require_once $file;
        }
        // Se não existir, silenciosamente não faz nada (WordPress-friendly)
    }, /*throw*/ false, /*prepend*/ true);
}
