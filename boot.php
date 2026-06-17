<?php

declare(strict_types=1);

$addon = rex_addon::get('mailjet_connect');

if (rex::isBackend()) {
    rex_perm::register('mailjet_connect[]');
    rex_perm::register('mailjet_connect[settings]');
    rex_perm::register('mailjet_connect[yform_sync]');
    rex_perm::register('mailjet_connect[events]');
    rex_perm::register('mailjet_connect[diagnostics]');
}

if (!$addon->hasConfig('api_key')) {
    $addon->setConfig('api_key', '');
}

if (!$addon->hasConfig('api_secret')) {
    $addon->setConfig('api_secret', '');
}

if (!$addon->hasConfig('webhook_username')) {
    $addon->setConfig('webhook_username', '');
}

if (!$addon->hasConfig('webhook_password')) {
    $addon->setConfig('webhook_password', '');
}

if (!$addon->hasConfig('webhook_token')) {
    $addon->setConfig('webhook_token', '');
}

if (!$addon->hasConfig('webhook_events')) {
    $addon->setConfig('webhook_events', ['bounce', 'blocked', 'spam', 'sent']);
}

if (!$addon->hasConfig('keep_raw_payload_days')) {
    $addon->setConfig('keep_raw_payload_days', 180);
}

if (!$addon->hasConfig('enabled')) {
    $addon->setConfig('enabled', true);
}

if (!$addon->hasConfig('yform_sync_enabled')) {
    $addon->setConfig('yform_sync_enabled', false);
}

if (!$addon->hasConfig('yform_sync_table')) {
    $addon->setConfig('yform_sync_table', '');
}

if (!$addon->hasConfig('yform_sync_email_field')) {
    $addon->setConfig('yform_sync_email_field', 'email');
}

if (!$addon->hasConfig('yform_sync_action')) {
    $addon->setConfig('yform_sync_action', 'deactivate');
}

if (!$addon->hasConfig('yform_sync_status_field')) {
    $addon->setConfig('yform_sync_status_field', 'status');
}

if (!$addon->hasConfig('yform_sync_inactive_value')) {
    $addon->setConfig('yform_sync_inactive_value', '0');
}

if (!$addon->hasConfig('yform_sync_mode')) {
    $addon->setConfig('yform_sync_mode', 'immediate');
}

if (!$addon->hasConfig('yform_sync_event_types')) {
    $addon->setConfig('yform_sync_event_types', ['blocked', 'spam', 'bounce']);
}

if (!$addon->hasConfig('yform_sync_spam_action')) {
    $addon->setConfig('yform_sync_spam_action', 'sync');
}

if (rex_addon::get('cronjob')->isAvailable()) {
    rex_cronjob_manager::registerType(FriendsOfREDAXO\MailjetConnect\Cronjob\ProcessPendingSyncs::class);
}

FriendsOfREDAXO\MailjetConnect\Schema::ensureEventTable();

rex_api_function::register('mailjet_connect_webhook', rex_api_mailjet_connect_webhook::class);
