<?php

declare(strict_types=1);

namespace SCM\Commercial;

final class CommercialSmsMessage
{
  public const PREFIX = 'SKC SuCasa Inmobiliaria ';
  public const MAX_CHARACTERS = 160;
  public const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
  public const GSM_EXTENDED = "\f^{}\\[~]|€";

  /** @return array{characters:int,units:int,encoding:string,segments:int} */
  public static function metrics(string $text): array
  {
    $characters = mb_str_split($text, 1, 'UTF-8');
    $units = 0;
    $unicode = false;
    foreach ($characters as $character) {
      if (str_contains(self::GSM_BASIC, $character)) {
        $units++;
      } elseif (str_contains(self::GSM_EXTENDED, $character)) {
        $units += 2;
      } else {
        $unicode = true;
      }
    }
    if ($unicode) {
      $units = intdiv(strlen(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);
    }
    $single = $unicode ? 70 : 160;
    $multipart = $unicode ? 67 : 153;
    return ['characters' => count($characters), 'units' => $units, 'encoding' => $unicode ? 'Unicode' : 'GSM-7',
      'segments' => $units <= $single ? 1 : (int) ceil($units / $multipart)];
  }
}
