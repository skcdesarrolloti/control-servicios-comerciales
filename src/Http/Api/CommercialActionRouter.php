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
      'commercial_tickets_filter' => 'filterTickets',
      'commercial_ticket_detail' => 'ticketDetail',
      'commercial_ticket_status' => 'changeStatus',
      'commercial_ticket_reassign' => 'reassign',
      'commercial_ticket_reply' => 'reply',
      'commercial_ticket_note' => 'addNote',
      'commercial_ticket_follow_up' => 'followUp',
      'commercial_ticket_postpone' => 'postpone',
      'commercial_ticket_activate' => 'activate',
      'commercial_ticket_close' => 'close',
      'commercial_permissions_save' => 'savePermissions',
    ][$action] ?? null;
    if ($method === null) {
      return false;
    }
    $this->controller->{$method}($input);
  }
}
