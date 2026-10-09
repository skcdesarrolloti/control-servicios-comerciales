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
      'commercial_ticket_analyze' => 'analyzeTicket',
      'commercial_ticket_analysis_delete' => 'deleteAnalysis',
      'commercial_ticket_status' => 'changeStatus',
      'commercial_ticket_reassign' => 'reassign',
      'commercial_ticket_reply' => 'reply',
      'commercial_ticket_note' => 'addNote',
      'commercial_ticket_follow_up' => 'followUp',
      'commercial_ticket_postpone' => 'postpone',
      'commercial_ticket_activate' => 'activate',
      'commercial_ticket_close' => 'close',
      'commercial_permissions_save' => 'savePermissions',
      'commercial_notifications_recipients' => 'notificationRecipients',
      'commercial_notifications_send' => 'sendNotifications',
      'commercial_notifications_queue' => 'notificationQueue',
      'commercial_notifications_detail' => 'notificationDetail',
      'commercial_notifications_delete' => 'deleteNotification',
      'commercial_notifications_report' => 'notificationReport',
      'commercial_actor_detail' => 'actorDetail',
      'commercial_actor_preview' => 'actorPreview',
      'commercial_actor_save' => 'actorSave',
      'commercial_property_updates' => 'propertyUpdates',
      'commercial_signs_control' => 'signsControl',
      'commercial_property_detail' => 'propertyDetail',
      'commercial_highlight_request' => 'requestHighlight',
      'commercial_highlight_cancel_request' => 'cancelHighlightRequest',
      'commercial_highlight_complete' => 'completeHighlight',
      'commercial_highlight_release' => 'releaseHighlight',
      'commercial_highlight_toggle_premium' => 'togglePremium',
    ][$action] ?? null;
    if ($method === null) {
      return false;
    }
    $this->controller->{$method}($input);
    return true;
  }
}
