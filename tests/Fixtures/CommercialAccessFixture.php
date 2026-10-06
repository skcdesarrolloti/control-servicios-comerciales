<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Core\Csrf;
use SCM\Core\Settings;

final class CommercialAccessFixture
{
  public static function make(): array
  {
    [$db] = CommercialNotificationsFixture::make();
    $db->insert('wp_jet_cct_confi_sistema', ['funcion' => 'control_servicios_comerciales_config', 'valor' => '{"commercial_permissions":{"9":{"views":["inicio"],"actions":[]}}}']);
    $settings = new Settings($db, true, 'control_servicios_comerciales_config');
    $csrf = new Csrf(str_repeat('x', 40));
    $_SESSION['scm_csrf']['commercial_nonce'] = 'fixture';
    App::init($db, new Auth($db), $csrf, $settings);
    return [$db, $settings, $csrf];
  }
}
