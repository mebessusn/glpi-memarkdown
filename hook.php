<?php
/**
 * ---------------------------------------------------------------------
 *
 * Minimal Effort Markdown - install / hooks
 *
 * @copyright 2024 HellrazorX64
 * @license   MIT
 *
 * ---------------------------------------------------------------------
 */

const PLUGIN_MEMARKDOWN_TABLE = 'glpi_plugin_memarkdown_sources';

function plugin_memarkdown_install()
{
    global $DB;

    if (!$DB->tableExists(PLUGIN_MEMARKDOWN_TABLE)) {
        $charset   = method_exists('DBConnection', 'getDefaultCharset') ? DBConnection::getDefaultCharset() : 'utf8mb4';
        $collation = method_exists('DBConnection', 'getDefaultCollation') ? DBConnection::getDefaultCollation() : 'utf8mb4_unicode_ci';
        $sign      = method_exists('DBConnection', 'getDefaultPrimaryKeySignOption') ? DBConnection::getDefaultPrimaryKeySignOption() : '';
        $table     = PLUGIN_MEMARKDOWN_TABLE;

        $DB->doQuery("CREATE TABLE `$table` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(100) NOT NULL,
            `items_id` int {$sign} NOT NULL DEFAULT 0,
            `field` varchar(100) NOT NULL,
            `markdown` longtext,
            `html_hash` char(32) NOT NULL DEFAULT '',
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `item_field` (`itemtype`, `items_id`, `field`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }
    return true;
}

function plugin_memarkdown_uninstall()
{
    global $DB;

    if ($DB->tableExists(PLUGIN_MEMARKDOWN_TABLE)) {
        $DB->doQuery('DROP TABLE `' . PLUGIN_MEMARKDOWN_TABLE . '`');
    }
    return true;
}

/**
 * Hash of a stored HTML value, used to detect that the field was later changed
 * outside markdown mode (rich-text editor, API, mail collector...).
 */
function plugin_memarkdown_hash($html): string
{
    $html = (string) $html;
    // normalise whitespace so harmless re-serialisation does not invalidate the source
    $html = preg_replace('/\s+/', ' ', $html);
    return md5(trim($html));
}

/**
 * item_add / item_update: store (or forget) the markdown source posted by the editor.
 */
function plugin_memarkdown_item_saved(CommonDBTM $item)
{
    global $DB;

    if (!isset($item->input['_memarkdown_source']) || !is_array($item->input['_memarkdown_source'])) {
        return;
    }
    if (!$DB->tableExists(PLUGIN_MEMARKDOWN_TABLE)) {
        return;
    }

    $items_id = (int) $item->getID();
    if ($items_id <= 0) {
        return;
    }

    foreach ($item->input['_memarkdown_source'] as $field => $markdown) {
        $field = (string) $field;
        if (!preg_match('/^[a-z0-9_]+$/i', $field)) {
            continue;
        }
        $where = [
            'itemtype' => $item->getType(),
            'items_id' => $items_id,
            'field'    => $field,
        ];

        $markdown = (string) $markdown;
        if ($markdown === '' || !array_key_exists($field, $item->fields)) {
            $DB->delete(PLUGIN_MEMARKDOWN_TABLE, $where);
            continue;
        }

        $DB->updateOrInsert(PLUGIN_MEMARKDOWN_TABLE, [
            'markdown'  => $markdown,
            'html_hash' => plugin_memarkdown_hash($item->fields[$field]),
            'date_mod'  => date('Y-m-d H:i:s'),
        ] + $where, $where);
    }
}

function plugin_memarkdown_item_purged(CommonDBTM $item)
{
    global $DB;

    if ($DB->tableExists(PLUGIN_MEMARKDOWN_TABLE)) {
        $DB->delete(PLUGIN_MEMARKDOWN_TABLE, [
            'itemtype' => $item->getType(),
            'items_id' => (int) $item->getID(),
        ]);
    }
}

/**
 * post_item_form: print the stored markdown for this item, inside its form, as JSON.
 * Only sources whose HTML is unchanged since they were saved are emitted.
 */
function plugin_memarkdown_post_item_form(array $params)
{
    global $DB;

    $item = $params['item'] ?? null;
    if (!($item instanceof CommonDBTM) || $item->isNewItem()) {
        return;
    }
    if (!in_array($item->getType(), plugin_memarkdown_itemtypes(), true)) {
        return;
    }
    if (!$DB->tableExists(PLUGIN_MEMARKDOWN_TABLE)) {
        return;
    }

    $sources = [];
    $rows = $DB->request([
        'FROM'  => PLUGIN_MEMARKDOWN_TABLE,
        'WHERE' => [
            'itemtype' => $item->getType(),
            'items_id' => (int) $item->getID(),
        ],
    ]);
    foreach ($rows as $row) {
        $field = $row['field'];
        if (
            array_key_exists($field, $item->fields)
            && hash_equals((string) $row['html_hash'], plugin_memarkdown_hash($item->fields[$field]))
        ) {
            $markdown = (string) $row['markdown'];
            // GLPI 10 HTML-encodes every input value (and stores it that way),
            // so "> quote" was saved as "&#62; quote". Restore what was typed.
            if (version_compare(GLPI_VERSION, '11.0.0', '<') && class_exists('Glpi\\Toolbox\\Sanitizer')) {
                $markdown = \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars($markdown);
            }
            $sources[$field] = $markdown;
        }
    }
    if ($sources === []) {
        return;
    }

    $json = json_encode(
        [
            'itemtype' => $item->getType(),
            'items_id' => (int) $item->getID(),
            'sources'  => $sources,
        ],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
    echo '<script type="application/json" class="memarkdown-source">' . $json . '</script>';
}
