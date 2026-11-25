<?php

declare(strict_types=1);

namespace SCM\Commercial\Calls;

use SCM\Commercial\CommercialTaskAssistant;
use SCM\Commercial\Medios\MedioResolver;
use SCM\Commercial\MiniMaxChat;

/**
 * Llenado inteligente de "Registrar llamada": convierte lo que dictó o pegó el funcionario en los campos del formulario.
 * - Correo y celular se sacan con reglas (también dictados: "arroba", "punto com"), así funcionan aunque falle la IA.
 * - MiniMax llena lo demás, solo con valores de las listas del formulario (lo que no coincide se deja vacío).
 * - Siempre: no es comisionista, es cliente inmediato y merece tarea.
 * - El presupuesto solo sale de lo que dijo el cliente (nunca del precio del inmueble); si dijo que no tiene, queda en No.
 */
final class CommercialCallsSmartFill
{
  public const FIXED = ['comisionista' => 'No', 'cliente_inmediato' => 'Si', 'merece_ticket' => 'Si'];
  private const OFFER_TOPICS = ['Arriendo', 'Venta', 'Arriendo o venta'];
  private const LABELS = [
    'cliente' => 'Nombre', 'celular' => 'Celular', 'email' => 'Correo', 'indicativo' => 'País', 'celular_wsp' => 'Celular WhatsApp',
    'medio' => 'Medio', 'tema_ayuda' => 'Motivo', 'tiene_codigo' => 'Código del inmueble', 'id_inmueble' => 'Código del inmueble',
    'tipo_inmueble' => 'Tipo de inmueble', 'destinacion' => 'Destinación', 'uso' => 'Uso', 'presupuesto' => 'Presupuesto',
    'precio' => 'Precio', 'tiene_presupuesto' => 'Presupuesto', 'factores_importantes' => 'Factores importantes', 'descripcion' => 'Descripción',
  ];

  public function __construct(private ?MiniMaxChat $chat)
  {
  }

  /**
   * @param array<string,mixed> $options CommercialCallsService::options()
   * @return array{campos:array<string,string|list<string>>,llenados:list<string>,faltantes:list<string>,ia:bool,aviso:string}
   */
  public function fill(string $text, array $options): array
  {
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    $fields = self::rules($text);
    $ai = false;
    $notice = '';
    if ($this->chat instanceof MiniMaxChat && $this->chat->isConfigured()) {
      try {
        $answer = MiniMaxChat::decodeJson($this->chat->complete($this->messages($text, $options), 900, 0.1)) ?? [];
        $fields = array_merge(self::validate($answer, $options), array_filter($fields, static fn($value): bool => $value !== ''));
        $ai = true;
      } catch (\RuntimeException $exception) {
        $notice = 'La IA no respondió (' . $exception->getMessage() . '): se llenó solo el celular y el correo.';
      }
    } else {
      $notice = 'La IA no está configurada (MINIMAX_API_KEY): se llenó solo el celular y el correo.';
    }

    if (!isset($fields['indicativo']) && in_array('57', array_column($options['paises'] ?? [], 'value'), true) && isset($fields['celular'])) {
      $fields['indicativo'] = '57';
    }
    $topic = (string) ($fields['tema_ayuda'] ?? '');
    if (in_array($topic, self::OFFER_TOPICS, true)) {
      $fields['tiene_codigo'] = isset($fields['id_inmueble']) ? 'Si' : 'No';
      unset($fields['precio']);
      // Con cifra, cuenta con presupuesto; si dijo que no tiene, No (y al guardar queda en la descripción).
      if (isset($fields['presupuesto'])) {
        $fields['tiene_presupuesto'] = 'Si';
      } elseif (($fields['tiene_presupuesto'] ?? '') !== 'No') {
        unset($fields['tiene_presupuesto']);
      }
    } else {
      unset($fields['id_inmueble'], $fields['presupuesto'], $fields['tiene_presupuesto']);
      if (!in_array($topic, ['Captacion', 'Recaptacion'], true)) unset($fields['precio']);
    }
    $fields += self::FIXED;

    $filled = array_values(array_unique(array_map(static fn(string $key): string => self::LABELS[$key], array_keys(array_intersect_key($fields, self::LABELS)))));
    $wanted = ['cliente', 'celular', 'email', 'medio', 'tema_ayuda'];
    $missing = array_values(array_map(static fn(string $key): string => self::LABELS[$key], array_diff($wanted, array_keys($fields))));
    return ['campos' => $fields, 'llenados' => $filled, 'faltantes' => $missing, 'ia' => $ai, 'aviso' => $notice];
  }

  /**
   * Correo y celular por reglas: el dictado los trae como "laura arroba gmail punto com" y "300 123 45 67".
   * @return array<string,string>
   */
  public static function rules(string $text): array
  {
    $out = [];
    $spoken = preg_replace('/\s*\barroba\b\s*/iu', '@', $text) ?? $text;
    // Antes de la arroba también se dicta: "laura punto gomez", "laura guion bajo gomez".
    $spoken = preg_replace('/\s+gui[oó]n\s+bajo\s+(?=[\p{L}0-9._%+-]*@)/iu', '_', $spoken) ?? $spoken;
    $spoken = preg_replace('/\s+punto\s+(?=[\p{L}0-9._%+-]*@)/iu', '.', $spoken) ?? $spoken;
    if (preg_match('/([\p{L}0-9._%+-]+)@([\p{L}0-9-]+(?:\s*(?:\.|\bpunto\b)\s*[\p{L}0-9-]+)+)/iu', $spoken, $match)) {
      $domain = preg_replace('/\s*(?:\.|\bpunto\b)\s*/iu', '.', $match[2]) ?? $match[2];
      $email = mb_strtolower($match[1] . '@' . $domain);
      if (filter_var($email, FILTER_VALIDATE_EMAIL)) $out['email'] = $email;
    }
    if (preg_match('/(?<!\d)(?:\+?\s*57[\s.-]*)?(3\d{2}(?:[\s.-]*\d){7})(?!\d)/u', $text, $match)) {
      $out['celular'] = preg_replace('/\D+/', '', $match[1]) ?? '';
    }
    return array_filter($out, static fn(string $value): bool => $value !== '');
  }

  /**
   * Solo pasa lo que existe en las listas del formulario; el resto queda vacío para llenarlo a mano.
   * @param array<string,mixed> $answer
   * @param array<string,mixed> $options
   * @return array<string,string|list<string>>
   */
  public static function validate(array $answer, array $options): array
  {
    $text = static fn(string $key, int $limit = 120): string => is_scalar($answer[$key] ?? null) ? mb_substr(CommercialTaskAssistant::stripNoise((string) $answer[$key]), 0, $limit) : '';
    $pick = static function (string $value, array $list): string {
      $key = MedioResolver::key($value);
      if ($key === '') return '';
      foreach ($list as $item) {
        if (MedioResolver::key((string) $item) === $key) return (string) $item;
      }
      return '';
    };
    $out = [];
    $name = $text('cliente', 120);
    if ($name !== '') $out['cliente'] = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);
    foreach (['celular', 'celular_wsp'] as $phone) {
      $digits = preg_replace('/\D+/', '', $text($phone)) ?? '';
      if (str_starts_with($digits, '57') && strlen($digits) === 12) $digits = substr($digits, 2);
      if (strlen($digits) >= 7 && strlen($digits) <= 15) $out[$phone] = $digits;
    }
    $email = mb_strtolower(str_replace(' ', '', $text('email')));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) $out['email'] = $email;
    $country = preg_replace('/\D+/', '', $text('indicativo')) ?? '';
    if ($country !== '' && in_array($country, array_column($options['paises'] ?? [], 'value'), true)) $out['indicativo'] = $country;
    foreach (['medio' => 'medios', 'tema_ayuda' => 'temas', 'tipo_inmueble' => 'tipos', 'destinacion' => 'destinaciones', 'uso' => 'usos'] as $field => $list) {
      $value = $pick($text($field), is_array($options[$list] ?? null) ? $options[$list] : []);
      if ($value !== '') $out[$field] = $value;
    }
    if (MedioResolver::key($text('tiene_presupuesto')) === 'no') $out['tiene_presupuesto'] = 'No';
    $code = preg_replace('/\D+/', '', $text('id_inmueble')) ?? '';
    if ($code !== '' && strlen($code) <= 9) $out['id_inmueble'] = $code;
    foreach (['presupuesto', 'precio'] as $money) {
      $amount = (int) (preg_replace('/\D+/', '', $text($money)) ?? '');
      if ($amount >= 100000) $out[$money] = (string) $amount;
    }
    $factors = [];
    foreach (is_array($answer['factores_importantes'] ?? null) ? $answer['factores_importantes'] : [] as $factor) {
      $value = is_scalar($factor) ? $pick((string) $factor, is_array($options['factores'] ?? null) ? $options['factores'] : []) : '';
      if ($value !== '') $factors[] = $value;
    }
    if ($factors !== []) $out['factores_importantes'] = array_values(array_unique($factors));
    $description = $text('descripcion', 1000);
    if ($description !== '') $out['descripcion'] = $description;
    return $out;
  }

  /**
   * @param array<string,mixed> $options
   * @return list<array{role:string,content:string}>
   */
  private function messages(string $text, array $options): array
  {
    $lists = [
      'medio' => $options['medios'] ?? [],
      'tema_ayuda' => $options['temas'] ?? [],
      'tipo_inmueble' => $options['tipos'] ?? [],
      'destinacion' => $options['destinaciones'] ?? [],
      'uso' => $options['usos'] ?? [],
      'factores_importantes' => $options['factores'] ?? [],
    ];
    $json = json_encode($lists, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    return [
      [
        'role' => 'system',
        'content' => 'Extraes datos de una llamada comercial de una inmobiliaria colombiana para llenar un formulario. No inventes: si un dato no aparece, déjalo vacío. Escribe solo en español con alfabeto latino. Devuelve únicamente JSON válido.',
      ],
      [
        'role' => 'user',
        'content' => "Del texto, devuelve JSON con estas llaves: cliente (nombre completo de la persona), celular (solo dígitos, sin indicativo), celular_wsp (solo si dio otro número de WhatsApp), email, indicativo (código de país, 57 para Colombia), medio, tema_ayuda, tipo_inmueble, destinacion, uso, id_inmueble (código del inmueble si lo menciona), presupuesto (pesos, solo dígitos, lo que puede pagar en arriendo o compra; vacío si no lo dijo), tiene_presupuesto (No solo si dijo que aún no tiene presupuesto definido; si no, vacío), precio (pesos, solo dígitos, el valor de su inmueble si quiere que se lo arrienden o vendan), factores_importantes (arreglo) y descripcion (dos o tres frases en tercera persona con lo que busca: zona, características y condiciones).\n"
          . "Para medio, tema_ayuda, tipo_inmueble, destinacion, uso y factores_importantes usa exactamente un valor de estas listas o déjalo vacío. tema_ayuda: Arriendo o Venta si busca un inmueble; Captacion si ofrece su inmueble para arrendar o vender.\nListas: {$json}\n\nTexto: {$text}",
      ],
    ];
  }
}
