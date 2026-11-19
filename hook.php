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
/** Larger sources are not kept (the rendered HTML is still saved by GLPI). */
const PLUGIN_MEMARKDOWN_MAX_SOURCE = 1048576;

/**
 * Rich-text fields whose markdown source may be stored, per itemtype.
 *
 * @return string[]
 */
function plugin_memarkdown_fields(string $itemtype): array
{
    switch ($itemtype) {
        case 'KnowbaseItem':
        case 'KnowbaseItemTranslation':
            return ['answer'];
        case 'Reminder':
            return ['text'];
        case 'TicketValidation':
        case 'ChangeValidation':
            return ['comment_submission', 'comment_validation'];
        default: // tickets, problems, changes, followups, tasks, solutions
            return ['content'];
    }
}

/** $DB->doQuery() only exists since GLPI 10.0.11. */
function plugin_memarkdown_query(string $sql): void
{
    global $DB;
    if (method_exists($DB, 'doQuery')) {
        $DB->doQuery($sql);
    } else {
        $DB->query($sql);
    }
}

function plugin_memarkdown_install()
{
    global $DB;

    if (!$DB->tableExists(PLUGIN_MEMARKDOWN_TABLE)) {
        $charset   = method_exists('DBConnection', 'getDefaultCharset') ? DBConnection::getDefaultCharset() : 'utf8mb4';
        $collation = method_exists('DBConnection', 'getDefaultCollation') ? DBConnection::getDefaultCollation() : 'utf8mb4_unicode_ci';
        $sign      = method_exists('DBConnection', 'getDefaultPrimaryKeySignOption') ? DBConnection::getDefaultPrimaryKeySignOption() : '';
        $table     = PLUGIN_MEMARKDOWN_TABLE;

        plugin_memarkdown_query("CREATE TABLE `$table` (
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
        plugin_memarkdown_query('DROP TABLE `' . PLUGIN_MEMARKDOWN_TABLE . '`');
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

function plugin_memarkdown_item_added(CommonDBTM $item)
{
    // fields given on creation
    plugin_memarkdown_item_saved($item, array_keys(is_array($item->input) ? $item->input : []));
}

function plugin_memarkdown_item_updated(CommonDBTM $item)
{
    // only fields whose value actually changed in this update
    plugin_memarkdown_item_saved($item, is_array($item->updates ?? null) ? $item->updates : []);
}

/**
 * Store (or forget) the markdown source posted by the editor.
 *
 * A source is only accepted for an allow-listed rich-text field that is part of
 * this save: it describes the HTML being saved now, never HTML someone else wrote.
 *
 * @param string[] $changed fields written by this add / update
 */
function plugin_memarkdown_item_saved(CommonDBTM $item, array $changed = [])
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

    $allowed = plugin_memarkdown_fields($item->getType());
    foreach ($item->input['_memarkdown_source'] as $field => $markdown) {
        $field = (string) $field;
        if (
            !in_array($field, $allowed, true)
            || !in_array($field, $changed, true)
            || !$DB->fieldExists($item->getTable(), $field)
        ) {
            continue; // field untouched by this save: keep what is stored
        }
        $where = [
            'itemtype' => $item->getType(),
            'items_id' => $items_id,
            'field'    => $field,
        ];

        $markdown = is_string($markdown) ? $markdown : '';
        if ($markdown === '' || strlen($markdown) > PLUGIN_MEMARKDOWN_MAX_SOURCE) {
            $DB->delete(PLUGIN_MEMARKDOWN_TABLE, $where);
            continue;
        }

        // Hash the value as stored in the database (on GLPI 10 $item->fields
        // still holds the SQL-escaped input after an update).
        $saved = $DB->request([
            'SELECT' => [$field],
            'FROM'   => $item->getTable(),
            'WHERE'  => ['id' => $items_id],
        ])->current();
        if ($saved === null) {
            continue;
        }

        $DB->updateOrInsert(PLUGIN_MEMARKDOWN_TABLE, [
            'markdown'  => $markdown,
            'html_hash' => plugin_memarkdown_hash($saved[$field]),
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
    // the source is only needed to edit the item: not sent to read-only viewers
    if (!$item->canUpdateItem()) {
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
            in_array($field, plugin_memarkdown_fields($item->getType()), true)
            && array_key_exists($field, $item->fields)
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
