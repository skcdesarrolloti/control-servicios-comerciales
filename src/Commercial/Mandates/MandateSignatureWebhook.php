<?php

declare(strict_types=1);

namespace SCM\Commercial\Mandates;

use SCM\Core\Database;

/**
 * Aviso de eSignatures (public/esignatures-webhook.php), copia de formularios/comun/webhook_esignatures.php y
 * firma_procesar_aviso: mantener iguales.
 * - contract-signed: guarda el PDF firmado (el enlace de eSignatures vence a los 3 días) y deja el mandato "Firmado"
 *   con pdf_firmado y fecha_firma.
 * - contract-withdrawn (anulado o rechazado): el mandato vuelve a "No enviado" para corregirlo y enviarlo otra vez.
 * No se confía en el aviso: el estado y el PDF se consultan a la API con el token. Solo cuenta el contrato vigente del
 * mandato (id_esignatures). Si algo falla, lanza la excepción: el webhook responde 500 y eSignatures reintenta.
 */
final class MandateSignatureWebhook
{
  private Database $db;
  private MandateSignature $signature;
  /** @var callable(string):string Descarga el PDF y devuelve su enlace guardado. */
  private $storePdf;

  /** @param callable(string):string $storePdf */
  public function __construct(Database $db, MandateSignature $signature, callable $storePdf)
  {
    $this->db = $db;
    $this->signature = $signature;
    $this->storePdf = $storePdf;
  }

  /** ¿El aviso trae la firma X-Signature-SHA256 correcta (HMAC-SHA256 del cuerpo con el token, en hex o base64)? */
  public static function validSignature(string $body, string $header, string $token): bool
  {
    $hmac = hash_hmac('sha256', $body, $token, true);
    $header = trim($header);
    return $header !== '' && (hash_equals(bin2hex($hmac), strtolower($header)) || hash_equals(base64_encode($hmac), $header));
  }

  /** @param array<string,mixed> $notice */
  public function process(array $notice): string
  {
    $event = (string) ($notice['status'] ?? '');
    $contract = trim((string) ($notice['data']['contract']['id'] ?? ''));
    if (!in_array($event, ['contract-signed', 'contract-withdrawn'], true) || $contract === '') {
      return 'Ignorado (' . $event . ')';
    }
    $table = $this->db->table('jet_cct_contrato_mandato');
    $mandate = $this->db->getRow('SELECT * FROM `' . $table . '` WHERE id_esignatures = ? LIMIT 1', [$contract]);
    if (!is_array($mandate)) {
      return 'El contrato ' . $contract . ' no es el vigente de ningún mandato';
    }
    $id = (int) $mandate['_ID'];
    $current = trim((string) ($mandate['estado'] ?? ''));
    $info = $this->signature->contractInfo($contract);
    $status = (string) ($info['status'] ?? '');
    if ($event === 'contract-signed') {
      if ($status !== 'signed') {
        return 'Mandato ' . $id . ': el contrato aún no está firmado (' . $status . ')';
      }
      if ($current === 'Firmado' && trim((string) ($mandate['pdf_firmado'] ?? '')) !== '') {
        return 'Mandato ' . $id . ': ya estaba firmado';
      }
      $pdf = (string) ($info['contract_pdf_url'] ?? '');
      if ($pdf === '') {
        throw new \RuntimeException('El contrato firmado no trae contract_pdf_url.');
      }
      $data = ['estado' => 'Firmado', 'pdf_firmado' => ($this->storePdf)($pdf), 'fecha_firma' => date('Y-m-d H:i:s')];
    } else {
      if ($status !== 'withdrawn' || $current === 'Firmado') {
        return 'Mandato ' . $id . ': sin cambios (' . $status . ')';
      }
      $data = ['estado' => 'No enviado'];
    }
    $this->db->update($table, MandateFields::existing($this->db, $table, $data), ['_ID' => $id]);
    return 'Mandato ' . $id . ': ' . $data['estado'];
  }

  /** Descarga el PDF firmado (curl) y lo guarda con su enlace firmado. */
  public static function downloader(\SCM\Support\StoredFileService $files): callable
  {
    return static function (string $url) use ($files): string {
      $curl = curl_init($url);
      curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60]);
      $pdf = curl_exec($curl);
      $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
      curl_close($curl);
      if (!is_string($pdf) || $status !== 200 || strncmp($pdf, '%PDF', 4) !== 0) {
        throw new \RuntimeException('No se pudo descargar el PDF firmado (HTTP ' . $status . ').');
      }
      return $files->storePdfBytes($pdf);
    };
  }
}
