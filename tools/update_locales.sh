#!/usr/bin/env bash
#
# Planner — atualiza os catálogos de tradução.
#
# As saidas usam --no-wrap: uma entrada por linha, sem quebra automatica.
# Facilita revisar o diff de uma traducao e scriptar sobre o .po.
#
# Fluxo gettext padrão:
#   1. xgettext varre o código e extrai as strings-fonte (inglês) para o .pot
#   2. msgmerge atualiza cada .po existente, PRESERVANDO as traduções já feitas
#   3. msgfmt compila cada .po no .mo que o GLPI carrega
#
# Rode depois de acrescentar, remover ou editar qualquer `__()` / `_n()`.
# Traduções existentes não são perdidas: o msgmerge marca como "fuzzy" o que
# mudou e deixa o resto intacto.
#
# Requer PHP e gettext. Se a máquina não tiver os dois, rode num container:
#
#   docker run --rm -v "$PWD:/p" -w /p alpine:3.20 \
#     sh -c 'apk add --no-cache gettext bash php83-cli >/dev/null \
#            && bash tools/update_locales.sh'
#
set -euo pipefail

cd "$(dirname "$0")/.."

DOMAIN="planner"
LOCALES="locales"
POT="$LOCALES/$DOMAIN.pot"
SHADOW_DIR=".locales-shadow"

PHP_BIN="${PHP_BIN:-php}"

for bin in xgettext msgmerge msgfmt msgen "$PHP_BIN"; do
    command -v "$bin" >/dev/null || { echo "faltando: $bin"; exit 1; }
done

mkdir -p "$LOCALES"
rm -rf "$SHADOW_DIR"

cleanup() { rm -rf "$SHADOW_DIR"; }
trap cleanup EXIT

find_sources() {
    find . -type f -name "$1" \
        -not -path './vendor/*' -not -path './node_modules/*' \
        -not -path './.git/*' -not -path "./$SHADOW_DIR/*" \
        | sed 's|^\./||' | sort
}

mapfile -t PHP_FILES < <(find_sources '*.php')
mapfile -t TWIG_FILES < <(find_sources '*.twig')

# O xgettext ignora as strings dos templates Twig (ver tools/twig2php.php).
# As sombras preservam o número da linha, então as referências do .pot
# continuam apontando para um lugar real.
if [ ${#TWIG_FILES[@]} -gt 0 ]; then
    "$PHP_BIN" tools/twig2php.php "$SHADOW_DIR" "${TWIG_FILES[@]}"
fi

{
    printf '%s\n' "${PHP_FILES[@]}"
    for t in "${TWIG_FILES[@]}"; do printf '%s\n' "$SHADOW_DIR/$t.php"; done
} > "$SHADOW_DIR/sources.txt"

xgettext \
    --from-code=UTF-8 \
    --language=PHP \
    --keyword=__ \
    --keyword=_n:1,2 \
    --keyword=_x:1c,2 \
    --keyword=_nx:1c,2,3 \
    --keyword=__s \
    --package-name="GLPI Planner plugin" \
    --msgid-bugs-address="suporte@pellissari.com.br" \
    --add-comments=TRANSLATORS \
    --no-wrap \
    --output="$POT" \
    --files-from="$SHADOW_DIR/sources.txt"

# O xgettext deixa CHARSET literal no cabeçalho e as referências apontando
# para os arquivos sombra. As duas coisas são corrigidas aqui: sem o charset,
# o msgfmt recusa o catálogo; sem a limpeza da referência, o tradutor recebe
# um caminho de arquivo temporário que não existe no repositório.
sed -i \
    -e 's|charset=CHARSET|charset=UTF-8|' \
    -e "s|^#: $SHADOW_DIR/|#: |" \
    -e "s|\\.twig\\.php:|.twig:|g" \
    "$POT"

echo "atualizado: $POT ($(grep -c '^msgid "' "$POT") entradas)"

for po in "$LOCALES"/*.po; do
    [ -e "$po" ] || continue
    lang="$(basename "$po" .po)"

    if [ "$lang" = "en_GB" ]; then
        # en_GB é a tradução identidade: as strings-fonte já estão em inglês.
        # Existe porque a cadeia de fallback de `Plugin::loadLang()` é "idioma
        # do usuário, senão idioma padrão da INSTÂNCIA, senão en_GB.mo" — sem
        # este arquivo, um usuário com a interface em inglês numa instância
        # cujo padrão é pt_BR recebe o catálogo português registrado sob o
        # locale en_GB, e vê o plugin em português.
        msgen --no-wrap --output-file="$po" "$POT"
        # O msgen herda o cabeçalho de modelo do .pot, com os campos ainda em
        # branco. Preenchê-los evita que o msgfmt reclame a cada execução.
        sed -i \
            -e 's|^"Language: .*|"Language: en_GB\\n"|' \
            -e 's|^"Plural-Forms: .*|"Plural-Forms: nplurals=2; plural=(n != 1);\\n"|' \
            -e "s|^\"PO-Revision-Date: .*|\"PO-Revision-Date: $(date '+%Y-%m-%d %H:%M%z')\\\\n\"|" \
            -e 's|^"Last-Translator: .*|"Last-Translator: Pellissari <suporte@pellissari.com.br>\\n"|' \
            -e 's|^"Language-Team: .*|"Language-Team: Pellissari <suporte@pellissari.com.br>\\n"|' \
            "$po"
    else
        msgmerge --quiet --no-wrap --update --backup=none "$po" "$POT"
    fi

    msgfmt --check --statistics --output-file="$LOCALES/$lang.mo" "$po"
    echo "compilado: $LOCALES/$lang.mo"
done
