<?php

declare(strict_types=1);

namespace SCM\Http\Api;

use SCM\Http\Controller\CampaignApiController;
use SCM\Http\Controller\CommercialApiController;
use SCM\Http\Controller\PromotionApiController;
use SCM\Http\Controller\StrategicPlanApiController;

final class CommercialActionRouter
{
  /** Endpoints de la pestaña Promoción: los atiende PromotionApiController, que se crea solo cuando se piden. */
  public const PROMOTION_ACTIONS = [
    'commercial_promo_analytics_logs' => 'analyticsLogs',
    'commercial_pop_product_save' => 'popProductSave',
    'commercial_pop_stock_adjust' => 'popStockAdjust',
    'commercial_pop_product_active' => 'popProductActive',
    'commercial_pop_delivery_create' => 'popDeliveryCreate',
    'commercial_pop_delivery_cancel' => 'popDeliveryCancel',
    'commercial_pop_sync' => 'popSync',
    'commercial_social_sync' => 'socialSync',
    'commercial_social_disconnect' => 'socialDisconnect',
    'commercial_piece_save' => 'pieceSave',
    'commercial_piece_active' => 'pieceActive',
    'commercial_piece_posts' => 'piecePosts',
    'commercial_piece_links' => 'pieceLinks',
  ];

  /** Endpoints de Herramientas › Plan estratégico: los atiende StrategicPlanApiController, creado solo cuando se piden. */
  public const STRATEGY_ACTIONS = [
    'commercial_plan_list' => 'list',
    'commercial_plan_save' => 'save',
    'commercial_plan_delete' => 'delete',
    'commercial_plan_progress' => 'progress',
    'commercial_plan_result' => 'result',
  ];

  /** Endpoints de Notificaciones › Campañas y Plantillas de correo: los atiende CampaignApiController. */
  public const CAMPAIGN_ACTIONS = [
    'commercial_campaign_preview' => 'preview',
    'commercial_campaign_send' => 'send',
    'commercial_campaign_test' => 'test',
    'commercial_campaign_detail' => 'detail',
    'commercial_campaign_control' => 'control',
    'commercial_template_get' => 'templateGet',
    'commercial_template_save' => 'templateSave',
  ];

  private CommercialApiController $controller;
  /** @var (\Closure():PromotionApiController)|null */
  private ?\Closure $promotion;
  /** @var (\Closure():StrategicPlanApiController)|null */
  private ?\Closure $strategy;
  /** @var (\Closure():CampaignApiController)|null */
  private ?\Closure $campaigns;

  /**
   * @param (\Closure():PromotionApiController)|null $promotion
   * @param (\Closure():StrategicPlanApiController)|null $strategy
   * @param (\Closure():CampaignApiController)|null $campaigns
   */
  public function __construct(CommercialApiController $controller, ?\Closure $promotion = null, ?\Closure $strategy = null, ?\Closure $campaigns = null)
  {
    $this->controller = $controller;
    $this->promotion = $promotion;
    $this->strategy = $strategy;
    $this->campaigns = $campaigns;
  }

  /** @param array<string,mixed> $input */
  public function dispatch(string $action, array $input): bool
  {
    if (isset(self::PROMOTION_ACTIONS[$action])) {
      if ($this->promotion === null) {
        return false;
      }
      $target = ($this->promotion)();
      $method = self::PROMOTION_ACTIONS[$action];
    } elseif (isset(self::STRATEGY_ACTIONS[$action])) {
      if ($this->strategy === null) {
        return false;
      }
      $target = ($this->strategy)();
      $method = self::STRATEGY_ACTIONS[$action];
    } elseif (isset(self::CAMPAIGN_ACTIONS[$action])) {
      if ($this->campaigns === null) {
        return false;
      }
      $target = ($this->campaigns)();
      $method = self::CAMPAIGN_ACTIONS[$action];
    } else {
      $target = $this->controller;
      $method = self::controllerMethod($action);
    }
    if ($method === null) {
      return false;
    }
    $target->{$method}($input);
    return true;
  }

  private static function controllerMethod(string $action): ?string
  {
    return [
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
      'commercial_calendar_due' => 'calendarDue',
      'commercial_calendar_cita_notify' => 'calendarCitaNotify',
      'commercial_settings_load' => 'settingsLoad',
      'commercial_settings_save' => 'settingsSave',
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
      'commercial_sign_lookup' => 'signLookup',
      'commercial_sign_save' => 'signSave',
      'commercial_task_sign_form' => 'taskSignForm',
      'commercial_tools_preapproval_lookup' => 'preapprovalLookup',
      'commercial_tools_preapproval_pdf' => 'preapprovalPdf',
      'commercial_tools_market_options' => 'marketOptions',
      'commercial_tools_market_analyze' => 'marketAnalyze',
      'commercial_tools_cleaning_calculate' => 'cleaningCalculate',
      'commercial_tools_cleaning_pdf' => 'cleaningPdf',
      'commercial_tools_utm_options' => 'utmOptions',
      'commercial_property_entry_form' => 'propertyEntryForm',
      'commercial_property_entry_save' => 'propertyEntrySave',
      'commercial_property_neighborhood' => 'propertyNeighborhood',
      'commercial_property_media_form' => 'propertyMediaForm',
      'commercial_property_media_draft' => 'propertyMediaDraft',
      'commercial_property_media_upload' => 'propertyMediaUpload',
      'commercial_property_media_save' => 'propertyMediaSave',
      'commercial_image_cleanup_status' => 'imageCleanupStatus',
      'commercial_image_cleanup' => 'imageCleanup',
      'commercial_property_video_config' => 'propertyVideoConfig',
      'commercial_property_video_create' => 'propertyVideoCreate',
      'commercial_property_video_status' => 'propertyVideoStatus',
      'commercial_property_video_cancel' => 'propertyVideoCancel',
      'commercial_property_video_download' => 'propertyVideoDownload',
      'commercial_property_detail' => 'propertyDetail',
      'commercial_highlight_request' => 'requestHighlight',
      'commercial_highlight_cancel_request' => 'cancelHighlightRequest',
      'commercial_highlight_complete' => 'completeHighlight',
      'commercial_highlight_release' => 'releaseHighlight',
      'commercial_highlight_toggle_premium' => 'togglePremium',
    ][$action] ?? null;
  }
}
