<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\Campaigns\CampaignEngine;
use SCM\Commercial\Campaigns\CampaignMessage;
use SCM\Commercial\Campaigns\CampaignRecipients;
use SCM\Commercial\Campaigns\CampaignTemplates;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Core\Auth;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Response\JsonResponse;

/**
 * Endpoints de Notificaciones › Campañas y Plantillas de correo (CommercialActionRouter::CAMPAIGN_ACTIONS). Cada uno
 * valida el nonce y la subpestaña; enviar, pausar, reanudar, reintentar, excluir y cancelar piden campanas_enviar, y
 * cambiar plantillas pide plantillas_editar. Enviar y cancelar llegan con la casilla de confirmación marcada.
 */
final class CampaignApiController
{
  private Database $db;
  private Csrf $csrf;
  private CommercialAccessPolicy $policy;
  private string $baseUrl;
  /** @var (\Closure():\DateTimeImmutable)|null */
  private ?\Closure $clock;

  /**
   * @param array<string,mixed> $config
   * @param (\Closure():\DateTimeImmutable)|null $clock
   */
  public function __construct(Database $db, Settings $settings, Csrf $csrf, array $config, ?\Closure $clock = null)
  {
    $this->db = $db;
    $this->csrf = $csrf;
    $adminCargos = is_array($config['dashboard_admin_cargos'] ?? null) ? $config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $this->policy = new CommercialAccessPolicy($settings, $db, $adminCargos);
    $this->baseUrl = rtrim((string) ($config['base_url'] ?? (defined('SCM_BASE_URL') ? constant('SCM_BASE_URL') : '')), '/');
    $this->clock = $clock;
  }

  /** @param array<string,mixed> $input */
  public function preview(array $input): never
  {
    $this->guard($input, 'campanas', 'campanas_enviar');
    $this->respond(function () use ($input): array {
      $engine = $this->engine();
      return $engine->preview($engine->spec($input));
    });
  }

  /** @param array<string,mixed> $input */
  public function send(array $input): never
  {
    $this->guard($input, 'campanas', 'campanas_enviar');
    $this->confirmed($input);
    $this->respond(function () use ($input): array {
      $engine = $this->engine();
      $result = $engine->send($engine->spec($input), $this->employee(), trim(Auth::user()));
      if ($result['queued'] === 0) {
        return $result + ['message' => 'No se programó ningún mensaje: todos los destinatarios ya la recibieron, no la aceptan o no tienen ' . (($input['channel'] ?? '') === 'email' ? 'correo' : 'celular') . '.'];
      }
      return $result + ['message' => 'Campaña programada: ' . number_format($result['queued'], 0, ',', '.') . ' mensajes por lotes.'];
    });
  }

  /** Correo de prueba a quien prepara la campaña, con los datos del primer destinatario. @param array<string,mixed> $input */
  public function test(array $input): never
  {
    $this->guard($input, 'campanas', 'campanas_enviar');
    $this->respond(function () use ($input): array {
      $engine = $this->engine();
      $spec = $engine->spec($input);
      if ($spec['channel'] !== 'email') {
        throw new \InvalidArgumentException('La prueba se envía por correo; para SMS y WhatsApp revisa la vista previa.');
      }
      $email = trim((string) $this->db->getVar('SELECT correo FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE id_empleado = ? LIMIT 1', [$this->employee()]));
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new \InvalidArgumentException('Tu funcionario no tiene un correo válido para recibir la prueba.');
      }
      $sample = $engine->preview($spec)['sample'];
      $message = CampaignMessage::build($spec, ['nombre' => (string) ($sample['nombre'] ?? trim(Auth::user())), 'tipo_actor' => 'prueba', 'tipo_label' => 'Prueba']);
      if ($message === null) {
        throw new \InvalidArgumentException('El correo quedó vacío.');
      }
      $now = gmdate('Y-m-d H:i:s');
      $this->db->insert(CampaignEngine::QUEUE, [
        'project_code' => CampaignEngine::PROJECT_CODE, 'source_module' => CampaignEngine::SOURCE_MODULE . '_prueba', 'channel' => 'email', 'provider' => 'email_smtp',
        'destination' => $email, 'destination_name' => trim(Auth::user()), 'subject' => '[Prueba] ' . $message['subject'], 'message_text' => $message['text'],
        'message_html' => CampaignEngine::tagLinks($message['html'], $spec['utm_campaign']), 'template_name' => '', 'template_language' => '', 'payload_json' => '[]',
        'meta_json' => json_encode(['gda' => ['campaign_tag' => '', 'source_module' => 'campaign_test', 'id_funcionario' => $this->employee()]], JSON_THROW_ON_ERROR),
        'status' => 'pending', 'priority' => 50, 'max_attempts' => 3, 'scheduled_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        'created_by' => CampaignEngine::PROJECT_CODE, 'dedupe_key' => 'campaign-test:' . bin2hex(random_bytes(8)),
      ]);
      return ['message' => 'Prueba enviada a ' . $email . '. Llega en unos minutos.'];
    });
  }

  /** @param array<string,mixed> $input */
  public function detail(array $input): never
  {
    $this->guard($input, 'campanas');
    $this->respond(fn(): array => ['campaign' => $this->engine()->detail(trim((string) ($input['tag'] ?? '')))]);
  }

  /** Pausar, reanudar, reintentar fallidos, cancelar o excluir. @param array<string,mixed> $input */
  public function control(array $input): never
  {
    $this->guard($input, 'campanas', 'campanas_enviar');
    $operation = (string) ($input['operation'] ?? '');
    if (in_array($operation, ['cancel', 'exclude'], true)) {
      $this->confirmed($input);
    }
    $this->respond(function () use ($input, $operation): array {
      $engine = $this->engine();
      $tag = trim((string) ($input['tag'] ?? ''));
      $count = static fn(int $n): string => number_format($n, 0, ',', '.');
      return ['message' => match ($operation) {
        'pause' => 'Campaña en pausa: ' . $count($engine->setPaused($tag, true)) . ' mensajes esperan.',
        'resume' => 'Campaña reanudada: ' . $count($engine->setPaused($tag, false)) . ' mensajes vuelven a la cola.',
        'retry' => $count($engine->retryFailed($tag)) . ' mensajes fallidos vuelven a intentarse.',
        'cancel' => 'Campaña cancelada: ' . $count($engine->cancel($tag)) . ' mensajes no se enviarán.',
        'exclude' => (function () use ($engine, $tag, $input): string {
          $engine->exclude($tag, (string) ($input['type'] ?? ''), (int) ($input['actor'] ?? 0), (string) ($input['reason'] ?? ''), $this->employee());
          return 'La persona quedó excluida de esta campaña.';
        })(),
        default => throw new \InvalidArgumentException('Acción no válida.'),
      }];
    });
  }

  /** HTML de una plantilla para editarla o verla. @param array<string,mixed> $input */
  public function templateGet(array $input): never
  {
    $this->guard($input, 'plantillas_correo');
    $this->respond(function () use ($input): array {
      $template = (new CampaignTemplates($this->db))->find((int) ($input['id'] ?? 0));
      if ($template === null) {
        throw new \InvalidArgumentException('La plantilla no existe.');
      }
      return ['template' => $template];
    });
  }

  /** Crear, editar, duplicar, archivar o reactivar. @param array<string,mixed> $input */
  public function templateSave(array $input): never
  {
    $this->guard($input, 'plantillas_correo', 'plantillas_editar');
    $operation = (string) ($input['operation'] ?? 'save');
    if ($operation === 'archive') {
      $this->confirmed($input);
    }
    $this->respond(function () use ($input, $operation): array {
      $templates = new CampaignTemplates($this->db);
      $id = (int) ($input['id'] ?? 0);
      return match ($operation) {
        'save' => ['id' => $templates->save($input, $this->employee()), 'message' => $id > 0 ? 'Plantilla guardada.' : 'Plantilla creada.'],
        'duplicate' => ['id' => $templates->duplicate($id, $this->employee()), 'message' => 'Copia creada.'],
        'archive' => (function () use ($templates, $id): array { $templates->setArchived($id, true, $this->employee()); return ['message' => 'Plantilla archivada: ya no se ofrece en las campañas.']; })(),
        'restore' => (function () use ($templates, $id): array { $templates->setArchived($id, false, $this->employee()); return ['message' => 'Plantilla reactivada.']; })(),
        default => throw new \InvalidArgumentException('Acción no válida.'),
      };
    });
  }

  private function engine(): CampaignEngine
  {
    return new CampaignEngine($this->db, new CampaignRecipients($this->db, $this->policy), $this->baseUrl, $this->clock);
  }

  /** @param callable():array<string,mixed> $operation */
  private function respond(callable $operation): never
  {
    try {
      $data = $operation();
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\RuntimeException $exception) {
      if ($exception instanceof \PDOException) {
        error_log('[commercial-campaigns] ' . $exception->getMessage());
        JsonResponse::error('No se pudo completar la acción. Intenta de nuevo.', 500);
      }
      JsonResponse::error($exception->getMessage(), 409);
    } catch (\Throwable $exception) {
      error_log('[commercial-campaigns] ' . $exception->getMessage());
      JsonResponse::error('No se pudo completar la acción. Intenta de nuevo.', 500);
    }
    JsonResponse::success($data);
  }

  /** @param array<string,mixed> $input */
  private function guard(array $input, string $sub, string $action = ''): void
  {
    if (!$this->csrf->verify('commercial_nonce', (string) ($input['nonce'] ?? ''), false)) {
      JsonResponse::error('Verificación de seguridad fallida.', 403);
    }
    if (!$this->policy->canSubview('notificaciones', $sub)) {
      JsonResponse::error('No tienes permiso para entrar a esta sección de Notificaciones.', 403);
    }
    if ($action !== '' && !$this->policy->canAct($action)) {
      JsonResponse::error('No tienes permiso para esta acción.', 403);
    }
  }

  /** @param array<string,mixed> $input */
  private function confirmed(array $input): void
  {
    if (!filter_var($input['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
      JsonResponse::error('Confirma la acción marcando la casilla.', 422);
    }
  }

  private function employee(): string
  {
    $employee = trim(Auth::employeeId());
    return $employee !== '' ? $employee : (string) Auth::userId();
  }
}
