# Traducciones de Welow RRHH

* **Text domain:** `welow-rrhh`
* **Plantilla (POT):** `welow-rrhh.pot` — actualizada por `wp i18n make-pot . languages/welow-rrhh.pot --domain=welow-rrhh --exclude=vendor,tests,modules/skeleton`.

## Idioma fuente

El plugin se desarrolla con los mensajes ya en **español (España)**, por lo que **no se incluye `welow-rrhh-es_ES.mo`**: cuando WordPress no encuentra traducción cargada, recurre al `msgid` original (que ya es la cadena en español). Si alguien quiere personalizar la terminología, basta con compilar un `welow-rrhh-es_ES.mo` con los `msgstr` deseados.

## Cómo traducir a otro idioma

1. Copia la plantilla:

```bash
cp languages/welow-rrhh.pot languages/welow-rrhh-<locale>.po
```

Donde `<locale>` es el código WP (`en_US`, `fr_FR`, `pt_BR`, ...).

2. Edita el `.po` con [Poedit](https://poedit.net/) (recomendado) o cualquier editor de texto.
3. Compila a `.mo`:

```bash
msgfmt languages/welow-rrhh-en_US.po -o languages/welow-rrhh-en_US.mo
```

WordPress cargará automáticamente el `.mo` correspondiente al locale del sitio (ver `WPLANG` / Ajustes → Generales → Idioma del sitio).

## Convenciones para los traductores

* Mantén los **placeholders** (`%s`, `%1$s`, `%d`) sin cambiarlos de orden a menos que el idioma destino lo requiera.
* Las cadenas pueden incluir el carácter `·` como separador; respétalo.
* Comentarios `translators:` en el `.pot` aclaran el significado cuando hay placeholders ambiguos.
* No traduzcas los **codes de error** que aparecen en mensajes técnicos (`welow_vacation_year_closed`, etc.). Sólo el mensaje legible.

## Hooks i18n

Si necesitas filtrar/cargar traducciones desde un plugin externo, hay un `load_plugin_textdomain` estándar en `Plugin::load_textdomain()` que respeta `wp-content/languages/plugins/welow-rrhh-<locale>.mo` con precedencia sobre el shipping del propio plugin.
