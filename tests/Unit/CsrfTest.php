<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Core\Csrf;

final class CsrfTest extends TestCase
{
  protected function setUp(): void
  {
    $_SESSION = [];
  }

  public function testOpeningThePanelAgainKeepsTheTokenOfTheOtherTabValid(): void
  {
    $csrf = new Csrf('secreto');
    $firstTab = $csrf->token('commercial_nonce');
    $secondTab = $csrf->token('commercial_nonce');

    self::assertSame($firstTab, $secondTab);
    self::assertTrue($csrf->verify('commercial_nonce', $firstTab, false));
    self::assertFalse($csrf->verify('commercial_nonce', 'otro|token', false));
  }

  public function testSingleUseTokensAreReplacedAfterUse(): void
  {
    $csrf = new Csrf('secreto');
    $token = $csrf->token('login');

    self::assertTrue($csrf->verify('login', $token));
    self::assertFalse($csrf->verify('login', $token));
    self::assertNotSame($token, $csrf->token('login'));
  }
}
