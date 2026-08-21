<?php

declare(strict_types=1);

namespace SCM\Http\Api;

use SCM\Http\Controller\CommercialApiController;

final class CommercialActionRouter
{
  private CommercialApiController $controller;

  public function __construct(CommercialApiController $controller)
  {
    $this->controller = $controller;
  }

  /** @param array<string,mixed> $input */
  public function dispatch(string $action, array $input): bool
  {
    $method = [
      'commercial_ticket_status' => 'changeStatus',
      'commercial_ticket_reassign' => 'reassign',
      'commercial_permissions_save' => 'savePermissions',
    ][$action] ?? null;
    if ($method === null) {
      return false;
    }
    $this->controller->{$method}($input);
  }
}
