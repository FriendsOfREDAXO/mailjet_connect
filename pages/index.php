<?php

declare(strict_types=1);

$addon = rex_addon::get('mailjet_connect');

echo rex_view::title($addon->i18n('mailjet_connect_title'));
rex_be_controller::includeCurrentPageSubPath();
