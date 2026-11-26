<?php

declare(strict_types=1);

namespace SCM\Commercial;

/**
 * Herramientas de Gestión Operativa del popup de la tarea que dependen del tema. Responder, Nota interna, Seguimiento,
 * Postergar, Trasladar y Cerrar van en todos los temas; estas solo en los temas donde se usan. La vista oculta el botón
 * y el servidor rechaza abrir o guardar el formulario desde una tarea de otro tema. Es regla del negocio fija en el
 * código, no va en Configuración.
 */
final class CommercialTaskTools
{
  public const SIGN = 'sign';
  public const PROPERTY = 'property';
  public const MEDIA = 'media';
  public const QUOTE = 'quote';
  public const STUDY = 'study';

  /** Temas (clave normalizada) en que sale cada herramienta. */
  private const TOPICS = [
    self::SIGN => ['captacion', 'recaptacion', 'retoque', 'ruta'],
    self::PROPERTY => ['captacion', 'recaptacion'],
    self::MEDIA => ['captacion', 'recaptacion', 'actualizacion'],
    self::QUOTE => ['arriendo', 'venta', 'arriendo o venta'],
    self::STUDY => ['arriendo', 'arriendo o venta'],
  ];

  private const LABELS = [
    self::SIGN => 'Aviso nuevo',
    self::PROPERTY => 'Ingresar inmueble',
    self::MEDIA => 'Multimedia del inmueble',
    self::QUOTE => 'Cotizar inmuebles',
    self::STUDY => 'Estudio de aseguradora',
  ];

  public static function allows(string $tool, string $topic): bool
  {
    return in_array(self::topicKey($topic), self::TOPICS[$tool] ?? [], true);
  }

  /** @param array<string,mixed> $task */
  public static function allowsTask(string $tool, array $task): bool
  {
    return self::allows($tool, self::topicOf($task));
  }

  /** @param array<string,mixed> $task */
  public static function topicOf(array $task): string
  {
    $topic = trim((string) ($task['tema_ayuda'] ?? ''));
    return $topic !== '' ? $topic : trim((string) ($task['tema'] ?? ''));
  }

  /** @param array<string,mixed> $task */
  public static function deniedMessage(string $tool, array $task): string
  {
    $topic = self::topicOf($task);
    return (self::LABELS[$tool] ?? 'Esta herramienta') . ' no aplica a las tareas de ' . ($topic !== '' ? $topic : 'este tema') . '.';
  }

  /** Sin tildes, en minúsculas y "arriendo y venta" = "arriendo o venta" (como CommercialSlaService::topicKey). */
  public static function topicKey(string $topic): string
  {
    $topic = mb_strtolower(trim($topic), 'UTF-8');
    $topic = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'u', 'n'], $topic);
    $topic = preg_replace('/\s+/', ' ', $topic) ?? '';
    return str_replace('arriendo y venta', 'arriendo o venta', $topic);
  }
}
