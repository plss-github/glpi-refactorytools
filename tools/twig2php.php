<?php

/**
 * RefactoryTools — torna as strings dos templates Twig visíveis para o xgettext.
 *
 * O xgettext não entende Twig. Rodá-lo com `--language=PHP` sobre um `.twig`
 * também não resolve: o analisador PHP só olha dentro de `<?php ... ?>`, e um
 * template Twig não tem essas tags — todas as strings ficam de fora
 * (na prática, 47 das 113 strings deste plugin eram encontradas, só as dos
 * arquivos PHP).
 *
 * Este script gera, para cada template, um arquivo PHP "sombra" que contém
 * apenas as chamadas `__()` / `_n()` encontradas, **na mesma linha em que
 * aparecem no template**. Preservar a linha é o ponto: é assim que as
 * referências `#:` do .pot continuam apontando para um lugar real que o
 * tradutor consegue abrir.
 *
 * Uso: php tools/twig2php.php <dir-de-saida> <arquivo.twig> [...]
 * Cada sombra é gravada como <dir-de-saida>/<caminho-original>.php
 */

$out_dir = $argv[1] ?? '';
$files   = array_slice($argv, 2);

if ($out_dir === '' || $files === []) {
    fwrite(STDERR, "uso: php tools/twig2php.php <dir-saida> <arquivo.twig> [...]\n");
    exit(1);
}

// Captura `__('...'` e `_n('...', '...'` com aspas simples ou duplas.
$pattern = '/\b(__|_n|_x|_nx|__s)\s*\(\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")'
    . '(?:\s*,\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"))*/u';

$total = 0;

foreach ($files as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fwrite(STDERR, "não foi possível ler: $file\n");
        exit(1);
    }

    $shadow = [];
    foreach ($lines as $i => $line) {
        $calls = [];
        if (preg_match_all($pattern, $line, $m)) {
            foreach ($m[0] as $call) {
                // O xgettext só precisa da chamada; fechar o parêntese torna
                // a linha PHP válida mesmo quando o original quebra em várias
                // linhas no template.
                $calls[] = $call . ');';
                $total++;
            }
        }

        $content = implode(' ', $calls);

        // A linha 1 carrega a abertura `<?php` junto com o que houver nela,
        // para a contagem de linhas do arquivo sombra bater exatamente com a
        // do template.
        $shadow[] = $i === 0 ? rtrim('<?php ' . $content) : $content;
    }

    $target = rtrim($out_dir, '/') . '/' . $file . '.php';
    @mkdir(dirname($target), 0o775, true);
    file_put_contents($target, implode("\n", $shadow) . "\n");
}

fwrite(STDERR, "sombras geradas para " . count($files) . " template(s), {$total} chamada(s)\n");
