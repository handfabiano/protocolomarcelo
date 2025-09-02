<?php
/**
 * PSR-4 Autoloader para o plugin Protocolo Municipal.
 * Arquivo: autoloader.php (coloque na raiz do plugin).
 *
 * - Carrega classes sob o namespace raiz "ProtocoloMunicipal\" a partir de /src
 * - Silencioso se a classe não existir (padrão WordPress-friendly)
 */

// Impede acesso direto
if (!defined('ABSPATH')) { exit; }

if (!defined('PMN_AUTOLOADER_REGISTERED')) {
    define('PMN_AUTOLOADER_REGISTERED', true);

    spl_autoload_register(function (string $class) {
        // Namespace raiz do projeto
        $prefix   = 'ProtocoloMunicipal\\';
        $base_dir = __DIR__ . '/src/'; // diretório base para as classes PSR-4

        // Verifica se a classe usa o prefixo do namespace
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            // não é do nosso namespace
            return;
        }

        // Obtém a parte relativa do nome da classe (sem o prefixo do namespace)
        $relative_class = substr($class, $len);

        // Converte separadores de namespace em separadores de diretório e adiciona .php
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        $file = preg_replace('#/+#', '/', $file); // normaliza barras

        // Segurança básica: impede subidas de diretório
        if (strpos($file, '..') !== false) {
            return;
        }

        // Carrega o arquivo se existir
        if (is_file($file)) {
            require_once $file;
        }
        // Se não existir, silenciosamente não faz nada (WordPress-friendly)
    }, /*throw*/ false, /*prepend*/ true);
}
