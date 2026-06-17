<?php

declare(strict_types=1);

/** @var rex_addon $this */

$addon = rex_addon::get('mailjet_connect');

if (rex_post('save_yform_sync', 'bool', false)) {
    $addon->setConfig('yform_sync_enabled', rex_post('yform_sync_enabled', 'bool', false));
    $addon->setConfig('yform_sync_table', trim((string) rex_post('yform_sync_table', 'string', '')));
    $addon->setConfig('yform_sync_email_field', trim((string) rex_post('yform_sync_email_field', 'string', 'email')));

    $yformSyncAction = rex_post('yform_sync_action', 'string', 'deactivate');
    if (!in_array($yformSyncAction, ['deactivate', 'delete'], true)) {
        $yformSyncAction = 'deactivate';
    }
    $addon->setConfig('yform_sync_action', $yformSyncAction);

    $addon->setConfig('yform_sync_status_field', trim((string) rex_post('yform_sync_status_field', 'string', 'status')));
    $addon->setConfig('yform_sync_inactive_value', trim((string) rex_post('yform_sync_inactive_value', 'string', '0')));

    $syncMode = rex_post('yform_sync_mode', 'string', 'immediate');
    if (!in_array($syncMode, ['immediate', 'cron'], true)) {
        $syncMode = 'immediate';
    }
    $addon->setConfig('yform_sync_mode', $syncMode);

    $syncEventTypes = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) rex_post('yform_sync_event_types', 'string', 'blocked,spam,bounce'), -1, PREG_SPLIT_NO_EMPTY) ?: []));

    $spamAction = rex_post('yform_sync_spam_action', 'string', 'sync');
    if (!in_array($spamAction, ['sync', 'log_only'], true)) {
        $spamAction = 'sync';
    }
    $addon->setConfig('yform_sync_spam_action', $spamAction);
    if ([] === $syncEventTypes) {
        $syncEventTypes = ['blocked', 'spam', 'bounce'];
    }
    $addon->setConfig('yform_sync_event_types', array_values($syncEventTypes));

    echo rex_view::success($addon->i18n('mailjet_connect_settings_saved'));
}

$yformTableOptions = [];
if (rex_addon::get('yform')->isAvailable() && class_exists('rex_yform_manager_table')) {
    foreach (rex_yform_manager_table::getAll() as $table) {
        $tableName = (string) $table->getTableName();
        $yformTableOptions[] = [
            'value' => $tableName,
            'label' => $table->getNameLocalized() . ' (' . $tableName . ')',
        ];
    }
} else {
    echo rex_view::warning($addon->i18n('mailjet_connect_yform_sync_yform_missing'));
}

$infoContent = '<p>' . rex_escape($addon->i18n('mailjet_connect_yform_sync_notice')) . '</p>';
$infoContent .= '<div class="alert alert-info">'
    . rex_escape($addon->i18n('mailjet_connect_yform_sync_criteria_notice'))
    . '</div>';
$infoContent .= '<div class="alert alert-warning">'
    . rex_escape($addon->i18n('mailjet_connect_yform_sync_mode_notice'))
    . '</div>';
$infoContent .= '<div class="alert alert-info">'
    . rex_escape($addon->i18n('mailjet_connect_yform_sync_cron_activation_notice'))
    . '</div>';
$infoFragment = new rex_fragment();
$infoFragment->setVar('body', $infoContent, false);
echo $infoFragment->parse('core/page/section.php');

$content = '<form method="post" class="rex-form">';
$content .= '<div class="form-group">';
$content .= '<label><input type="checkbox" name="yform_sync_enabled" value="1"' . ((bool) $addon->getConfig('yform_sync_enabled', false) ? ' checked' : '') . '> ' . rex_escape($addon->i18n('mailjet_connect_yform_sync_enabled')) . '</label>';
$content .= '</div>';
$content .= '<div class="form-group">';
$content .= '<label for="yform_sync_table">' . rex_escape($addon->i18n('mailjet_connect_yform_sync_table')) . '</label>';
$content .= '<input class="form-control" list="mailjet-connect-yform-tables" type="text" id="yform_sync_table" name="yform_sync_table" value="' . rex_escape((string) $addon->getConfig('yform_sync_table', '')) . '" placeholder="rex_meine_tabelle">';
$content .= '<datalist id="mailjet-connect-yform-tables">';

foreach ($yformTableOptions as $option) {
    $content .= '<option value="' . rex_escape($option['value']) . '">' . rex_escape($option['label']) . '</option>';
}

$content .= '</datalist>';
$content .= '</div>';
$content .= '<div class="row">';
$content .= '<div class="col-md-4">';
$content .= '<div class="form-group">';
$content .= '<label for="yform_sync_email_field">' . rex_escape($addon->i18n('mailjet_connect_yform_sync_email_field')) . '</label>';
$content .= '<input class="form-control" type="text" id="yform_sync_email_field" name="yform_sync_email_field" value="' . rex_escape((string) $addon->getConfig('yform_sync_email_field', 'email')) . '">';
$content .= '</div>';
$content .= '</div>';
$content .= '<div class="col-md-4">';
$content .= '<div class="form-group">';
$content .= '<label for="yform_sync_action">' . rex_escape($addon->i18n('mailjet_connect_yform_sync_action')) . '</label>';
$content .= '<select class="form-control" id="yform_sync_action" name="yform_sync_action">';
$content .= '<option value="deactivate"' . ((string) $addon->getConfig('yform_sync_action', 'deactivate') === 'deactivate' ? ' selected' : '') . '>' . rex_escape($addon->i18n('mailjet_connect_yform_sync_action_deactivate')) . '</option>';
$content .= '<option value="delete"' . ((string) $addon->getConfig('yform_sync_action', 'deactivate') === 'delete' ? ' selected' : '') . '>' . rex_escape($addon->i18n('mailjet_connect_yform_sync_action_delete')) . '</option>';
$content .= '</select>';
$content .= '</div>';
$content .= '</div>';
$content .= '<div class="col-md-4">';
$content .= '<div class="form-group">';
$content .= '<label for="yform_sync_spam_action">' . rex_escape($addon->i18n('mailjet_connect_yform_sync_spam_action')) . '</label>';
$content .= '<select class="form-control" id="yform_sync_spam_action" name="yform_sync_spam_action">';
$content .= '<option value="sync"' . ((string) $addon->getConfig('yform_sync_spam_action', 'sync') === 'sync' ? ' selected' : '') . '>' . rex_escape($addon->i18n('mailjet_connect_yform_sync_spam_action_sync')) . '</option>';
$content .= '<option value="log_only"' . ((string) $addon->getConfig('yform_sync_spam_action', 'sync') === 'log_only' ? ' selected' : '') . '>' . rex_escape($addon->i18n('mailjet_connect_yform_sync_spam_action_log_only')) . '</option>';
$content .= '</select>';
$content .= '<p class="help-block">' . rex_escape($addon->i18n('mailjet_connect_yform_sync_spam_action_notice')) . '</p>';
$content .= '</div>';
$content .= '</div>';
$content .= '<div class="col-md-4">';
$content .= '<div class="form-group">';
$content .= '<label for="yform_sync_mode">' . rex_escape($addon->i18n('mailjet_connect_yform_sync_mode')) . '</label>';
$content .= '<select class="form-control" id="yform_sync_mode" name="yform_sync_mode">';
$content .= '<option value="immediate"' . ((string) $addon->getConfig('yform_sync_mode', 'immediate') === 'immediate' ? ' selected' : '') . '>' . rex_escape($addon->i18n('mailjet_connect_yform_sync_mode_immediate')) . '</option>';
$content .= '<option value="cron"' . ((string) $addon->getConfig('yform_sync_mode', 'immediate') === 'cron' ? ' selected' : '') . '>' . rex_escape($addon->i18n('mailjet_connect_yform_sync_mode_cron')) . '</option>';
$content .= '</select>';
$content .= '</div>';
$content .= '</div>';
$content .= '<div class="col-md-4">';
$content .= '<div class="form-group">';
$content .= '<label for="yform_sync_event_types">' . rex_escape($addon->i18n('mailjet_connect_yform_sync_event_types')) . '</label>';
$content .= '<input class="form-control" type="text" id="yform_sync_event_types" name="yform_sync_event_types" value="' . rex_escape(implode(',', (array) $addon->getConfig('yform_sync_event_types', ['blocked', 'spam', 'bounce']))) . '">';
$content .= '</div>';
$content .= '</div>';
$content .= '</div>';
$content .= '<div class="row">';
$content .= '<div class="col-md-6">';
$content .= '<div class="form-group">';
$content .= '<label for="yform_sync_status_field">' . rex_escape($addon->i18n('mailjet_connect_yform_sync_status_field')) . '</label>';
$content .= '<input class="form-control" type="text" id="yform_sync_status_field" name="yform_sync_status_field" value="' . rex_escape((string) $addon->getConfig('yform_sync_status_field', 'status')) . '">';
$content .= '</div>';
$content .= '</div>';
$content .= '<div class="col-md-6">';
$content .= '<div class="form-group">';
$content .= '<label for="yform_sync_inactive_value">' . rex_escape($addon->i18n('mailjet_connect_yform_sync_inactive_value')) . '</label>';
$content .= '<input class="form-control" type="text" id="yform_sync_inactive_value" name="yform_sync_inactive_value" value="' . rex_escape((string) $addon->getConfig('yform_sync_inactive_value', '0')) . '">';
$content .= '</div>';
$content .= '</div>';
$content .= '</div>';
$content .= '<input type="hidden" name="save_yform_sync" value="1">';
$content .= '<button class="btn btn-primary" type="submit">' . rex_escape($addon->i18n('mailjet_connect_save')) . '</button>';
$content .= '</form>';

$fragment = new rex_fragment();
$fragment->setVar('title', $addon->i18n('mailjet_connect_yform_sync'));
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
